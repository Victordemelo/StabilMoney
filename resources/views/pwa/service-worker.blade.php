@verbatim
/*
 * Service worker do Stabil Money (PWA) — servido por PwaController@serviceWorker.
 * NÃO é processado pelo Vite. Para invalidar caches antigos, suba a versão abaixo.
 *
 * Estratégia (conservadora de propósito, para NÃO quebrar o app):
 *  - Só intercepta GET. POST/PATCH/DELETE (forms + CSRF do Laravel) passam direto.
 *  - Assets estáticos (build do Vite, ícones, fontes): stale-while-revalidate.
 *  - Navegações: network-first → cai na página /offline quando sem rede.
 *  - NUNCA guarda HTML autenticado, com UMA exceção deliberada: o formulário de
 *    novo lançamento (/transactions/create), cacheado p/ abrir offline. A página
 *    apaga esse cache no logout E quando o dono muda no aparelho (offline-queue.js),
 *    pra ninguém ver o form de outro usuário.
 */
const CACHE = 'sm-cache-v2';

// Precache mínimo: tudo público, sem dado do usuário.
const PRECACHE = [
  '/offline',
  '/assets/icons/icon-192.png',
  '/assets/icons/icon-512.png',
  '/assets/icons/icon-maskable-512.png',
];

self.addEventListener('install', (event) => {
  // allSettled: se um item do precache falhar (ícone renomeado, 5xx passageiro),
  // a instalação NÃO é abortada — /offline e os demais ainda entram no cache.
  event.waitUntil(
    caches.open(CACHE)
      .then((cache) => Promise.allSettled(PRECACHE.map((u) => cache.add(u))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

// ---- Background Sync: reenvio dos lançamentos offline -----------------------
// Quando a conexão volta, o navegador dispara este 'sync' — MESMO com o app
// fechado (Chromium/Android). Lemos a fila do IndexedDB (a mesma do
// offline-queue.js) e reenviamos cada lançamento. O servidor é idempotente
// (índice único user_id+client_uuid), então coincidir com o replay da página
// NÃO duplica.
//
// Trava por usuário (aparelho compartilhado), em duas camadas:
//  1. o item só é reenviado se estiver ARMADO, isto é, se tiver `csrf`. Quem arma
//     e desarma é a página (offline-queue.js): ela grava o token da sessão nos
//     itens do usuário logado e APAGA o token dos itens de outro dono. O SW não
//     enxerga cookie, sessão nem localStorage — é por esse sinal que ele sabe o
//     que pode reenviar;
//  2. o próprio token: numa sessão diferente da que o criou, o servidor responde 419.
const SYNC_TAG = 'sm-sync-lancamentos';
const ODB_NAME = 'sm-offline';
const ODB_STORE = 'lancamentos';

function odbOpen() {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(ODB_NAME, 1);
    req.onupgradeneeded = () => {
      const db = req.result;
      if (!db.objectStoreNames.contains(ODB_STORE)) db.createObjectStore(ODB_STORE, { keyPath: 'client_uuid' });
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}
function odbAll() {
  return odbOpen().then((db) => new Promise((resolve, reject) => {
    const rq = db.transaction(ODB_STORE, 'readonly').objectStore(ODB_STORE).getAll();
    rq.onsuccess = () => resolve(rq.result || []);
    rq.onerror = () => reject(rq.error);
  }));
}
function odbDelete(key) {
  return odbOpen().then((db) => new Promise((resolve) => {
    const tx = db.transaction(ODB_STORE, 'readwrite');
    tx.objectStore(ODB_STORE).delete(key);
    tx.oncomplete = () => resolve();
    tx.onerror = () => resolve();
  }));
}
// Regrava o item (usado para marcar `failed` sem perder o lançamento).
function odbPut(item) {
  return odbOpen().then((db) => new Promise((resolve) => {
    const tx = db.transaction(ODB_STORE, 'readwrite');
    tx.objectStore(ODB_STORE).put(item);
    tx.oncomplete = () => resolve();
    tx.onerror = () => resolve();
  }));
}

async function flushLancamentos() {
  // A página não apaga mais a fila de quem trocou de usuário — apagar destruía um
  // lançamento que existe no mundo real e nunca chegou ao servidor. Ela SEGURA os
  // itens do outro dono e tira o `csrf` deles. Por isso o filtro abaixo: item
  // DESARMADO (sem csrf) não é reenviado aqui. Sem esse filtro, o Background Sync
  // mandaria o lançamento do dono anterior na sessão de quem está logado agora e a
  // despesa cairia na família errada.
  const items = (await odbAll()).filter((i) => i && i.payload && i.csrf);

  let retry = false; // sobrou item por falha passageira → pede novo sync (backoff do navegador)
  for (const item of items) {
    // `needsFunding` = espera o usuário escolher a fonte na página. Reenviar aqui
    // só traria outro 409; decidir por ele é o que esta rodada veio proibir.
    if (item.failed || item.needsFunding) continue;

    const enviar = (payload) => fetch('/transactions', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': item.csrf || '',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify(payload),
    });

    let res;
    try {
      res = await enviar(item.payload);
    } catch (e) {
      retry = true; break; // a rede caiu no meio: tenta de novo no próximo sync
    }

    // 409: o saldo não cobre e existe fonte. Aqui NÃO há tela para perguntar (o
    // app pode estar fechado), e escolher sozinho é justamente o que o modelo v3
    // proíbe — o cheque especial cobra juros de verdade. Então o item fica RETIDO
    // com as opções do servidor, e a próxima carga da página abre o modal de
    // escolha. Nada se perde: o lançamento continua na fila.
    if (res.status === 409) {
      let fonte = null;
      try { fonte = (await res.json()).fonte || null; } catch (e) { /* corpo não-JSON */ }
      item.needsFunding = true;
      item.fonte = fonte;
      delete item.failed;
      delete item.motivo;
      await odbPut(item);
      continue;
    }

    if (res.ok) {
      await odbDelete(item.client_uuid); // 201 criado ou 200 já existia (idempotente)
    } else if (res.status >= 500) {
      retry = true; // erro passageiro do servidor
    } else if (res.status === 422) {
      // Dados inválidos ou nenhuma fonte cobre. MARCA como failed em vez de deixar
      // em silêncio: antes o item ficava vivo na fila, sendo reenviado para sempre
      // e sem nenhuma tela para o usuário resolver.
      item.failed = true;
      item.motivo = 'Saldo insuficiente ou dados inválidos — revise este lançamento.';
      await odbPut(item);
    }
    // 401/419 (sessão/CSRF de outra sessão): deixa p/ a página tratar no login
  }
  if (retry) throw new Error('sync incompleto'); // rejeita → navegador reagenda o sync
}

self.addEventListener('sync', (event) => {
  if (event.tag === SYNC_TAG) event.waitUntil(flushLancamentos());
});

self.addEventListener('fetch', (event) => {
  const req = event.request;

  // SALVAGUARDA: o SW só mexe em GET. Tudo que altera estado (POST/PATCH/DELETE)
  // passa direto pela rede — é o que mantém os forms e o CSRF do Laravel intactos.
  if (req.method !== 'GET') return;

  const url = new URL(req.url);

  // /build/* é versionado por hash (imutável) → cache-first puro (não revalida).
  // /assets/* (nomes fixos) e Google Fonts → stale-while-revalidate (serve o cache
  // e atualiza por baixo, então asset trocado com o MESMO nome não fica preso).
  const isHashedBuild =
    url.origin === self.location.origin && url.pathname.startsWith('/build/');
  const isMutableAsset =
    (url.origin === self.location.origin && url.pathname.startsWith('/assets/')) ||
    url.host.endsWith('fonts.googleapis.com') ||
    url.host.endsWith('fonts.gstatic.com');

  if (isHashedBuild || isMutableAsset) {
    event.respondWith(
      caches.match(req).then((hit) => {
        // /build/* imutável já em cache: serve direto, sem tocar a rede.
        if (hit && isHashedBuild) return hit;
        // Guarda só 200 DE VERDADE: pula 206 (Range do vídeo faz cache.put lançar —
        // res.ok é true p/ 206!) e 404/5xx (envenenariam o cache); mantém fontes
        // (opaque, status 0). O .catch no put e o fallback final evitam unhandled
        // rejection / respondWith(undefined).
        const fresh = fetch(req).then((res) => {
          if (res.status === 200 || res.type === 'opaque') {
            const copy = res.clone();
            caches.open(CACHE).then((cache) => cache.put(req, copy)).catch(() => {});
          }
          return res;
        }).catch(() => hit || Response.error());
        return hit || fresh; // /assets/ com cache → serve cache e revalida (SWR)
      })
    );
    return;
  }

  // Navegações (páginas): network-first.
  if (req.mode === 'navigate') {
    // Exceção controlada: o formulário de novo lançamento é cacheado para abrir
    // offline (é o ÚNICO HTML autenticado que guardamos; a página o apaga do
    // cache no logout). Online sempre busca o fresco; offline serve o cacheado.
    if (url.pathname === '/transactions/create') {
      event.respondWith(
        fetch(req)
          .then((res) => {
            // Só guarda o formulário se vier 200 DIRETO — um 302→/login seguido
            // (sessão expirada) chega com res.redirected=true e NÃO pode ser
            // cacheado sob a chave do formulário (senão serve login no lugar).
            if (res.status === 200 && !res.redirected) {
              const copy = res.clone();
              caches.open(CACHE).then((cache) => cache.put('/transactions/create', copy)).catch(() => {});
            }
            return res;
          })
          .catch(() => caches.match('/transactions/create')
            .then((hit) => hit || caches.match('/offline'))
            .then((r) => r || Response.error()))
      );
      return;
    }

    // Demais páginas: sem rede → página /offline da marca. Nunca cacheia HTML
    // autenticado (saldo sempre fresco; sem dado de um usuário para outro ver).
    event.respondWith(
      fetch(req).catch(() => caches.match('/offline').then((r) => r || Response.error()))
    );
    return;
  }

  // Demais GET: tenta rede, cai no cache se houver algo guardado.
  event.respondWith(fetch(req).catch(() => caches.match(req).then((r) => r || Response.error())));
});
@endverbatim
