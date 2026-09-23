const VERSAO = @json($versao);
@verbatim
/*
 * Service worker do Stabil Money (PWA) — servido por PwaController@serviceWorker.
 * NÃO é processado pelo Vite.
 *
 * VERSÃO (P-6 da auditoria de 06/09/2026): a linha de cima, `const VERSAO`, é o ÚNICO
 * trecho que o Blade interpreta — é a versão do build (hash do manifest do Vite, ver
 * PwaController::versaoDoBuild). Ela existe para o navegador perceber o deploy: ele só
 * instala um SW novo quando os BYTES deste script mudam. Com o nome de cache fixo
 * (`sm-cache-v2`), um build novo não mudava byte nenhum — a aba aberta seguia com o CSS
 * e o JS velhos, e o cache do /build guardava todas as versões para sempre. Agora cada
 * build tem o seu cache, e o `activate` apaga os das versões anteriores.
 *   - O `v3` do nome do cache é manual: suba-o só quando mudar a ESTRATÉGIA de cache
 *     deste arquivo e o que já está guardado precisar ir embora mesmo sem build novo.
 *   - tests/js/service-worker.test.js executa este código trocando SÓ a linha da versão
 *     por uma de teste — qualquer outro Blade fora do @verbatim o faz se recusar a rodar.
 *
 * Estratégia (conservadora de propósito, para NÃO quebrar o app):
 *  - Só intercepta GET. POST/PATCH/DELETE (forms + CSRF do Laravel) passam direto —
 *    inclusive o do logout, que o SW apenas OBSERVA (ver HTML_AUTENTICADO).
 *  - Assets estáticos (build do Vite, ícones, fontes): stale-while-revalidate.
 *  - Navegações: network-first → cai na página /offline quando sem rede.
 *  - NUNCA guarda HTML autenticado, com UMA exceção deliberada: o formulário de
 *    novo lançamento (/transactions/create), cacheado p/ abrir offline. O próprio SW
 *    o apaga quando a sessão acaba, e a página repete a limpeza quando o dono muda
 *    no aparelho (offline-queue.js), pra ninguém ver o form de outro usuário.
 *
 * ⚠️ Tudo aqui dentro é JavaScript puro, sem Blade: tests/js/service-worker.test.js
 *    executa este mesmo código, e se recusa a rodar se algo ficar fora do @verbatim
 *    (estaria testando um SW diferente do que o navegador recebe).
 *
 * ⚠️ Este SW obedece à CSP da PRÓPRIA resposta do /sw.js, e cada fetch() dele é
 *    connect-src. Se ele passar a buscar um host novo (hoje: fonts.googleapis.com e
 *    fonts.gstatic.com), o host precisa entrar no connect-src do SecurityHeaders para o
 *    /sw.js — senão o fetch é bloqueado em silêncio e a página recebe erro de rede.
 *    E mudar só o header NÃO atualiza SW já instalado: o navegador compara os BYTES do
 *    script. Qualquer mudança de CSP para o SW precisa vir com uma mudança aqui.
 */
const CACHE = 'sm-cache-v3-' + VERSAO;

// Precache mínimo: tudo público, sem dado do usuário.
const PRECACHE = [
  '/offline',
  '/assets/icons/icon-192.png',
  '/assets/icons/icon-512.png',
  '/assets/icons/icon-maskable-512.png',
];

// ---- HTML autenticado × fim da sessão -----------------------------------------
//
// Páginas COM dado do usuário que o SW guarda para abrir offline. Hoje é só o
// formulário de lançamento, que traz as contas, as categorias e os nomes da
// família. Esta lista é a fonte única dos dois lados: o ramo de navegação só
// guarda o que está aqui, e a limpeza apaga tudo o que está aqui — uma página
// nova que entrar na lista já nasce com as duas coisas.
const HTML_AUTENTICADO = ['/transactions/create'];

// Quando a sessão acaba, esse HTML tem de sair do aparelho: num celular
// compartilhado, quem abrisse o app offline depois do "Sair" veria o formulário
// com os dados do dono anterior.
//
// A limpeza mora AQUI porque os outros dois lugares não dão conta sozinhos:
//  - o header do logout. `Clear-Site-Data: "cache"` limpa o cache HTTP, NÃO o
//    Cache Storage (`caches.*`), que é onde este arquivo guarda o formulário. O
//    valor que limparia o Cache Storage é "storage" — e ele leva junto o IndexedDB
//    da fila offline (lançamentos que ainda não chegaram ao servidor) e desregistra
//    este SW (fim do Background Sync). Fora de cogitação;
//  - o JS da página (offline-queue.js). Só apaga se o carregamento seguinte executar
//    o bundle. Continua lá, como segunda camada.
//
// O SW, ao contrário, vê as navegações de TODAS as abas, com ou sem JS na página.
// Dois gatilhos, e nenhum deles encosta no IndexedDB:
//
//  1. ROTA_LOGOUT — o POST do "Sair" passando por aqui. É a intenção explícita de
//     sair, então apaga na hora, sem esperar a resposta: vale até se a rede cair no
//     meio do clique.
//  2. ROTAS_SEM_SESSAO respondendo 200. As duas são do grupo `guest`, que
//     REDIRECIONA quem tem sessão; 200 ali é o servidor dizendo "este navegador não
//     tem sessão". Pega o que o 1 não vê — sessão expirada, conta banida, sessões
//     derrubadas por troca de senha em outro aparelho: a primeira navegação online a
//     qualquer tela do app cai no /login. É também onde termina a cadeia do próprio
//     "Sair" (/logout → / → /login). Sem rede não há resposta, e aí nada é apagado:
//     offline não dá para saber se a sessão acabou, e o dono precisa seguir lançando.
//
// ⚠️ Os caminhos são os das rotas do Laravel (`logout`, `login`, `register`).
//    Renomear uma rota sem mexer aqui desliga a limpeza em SILÊNCIO — o
//    ServiceWorkerApagaHtmlAutenticadoTest compara os dois lados. E não ponha nas
//    ROTAS_SEM_SESSAO uma página que abra também para quem está logado: o SW
//    apagaria o formulário offline do dono a cada visita.
const ROTA_LOGOUT = '/logout';
const ROTAS_SEM_SESSAO = ['/login', '/register'];

function apagarHtmlAutenticado() {
  // Varre TODOS os caches, não só o CACHE atual — mesmo critério do offline-queue.js:
  // formulário guardado sob outro nome continua sendo dado de usuário. `ignoreSearch`
  // pega a mesma página com query string (ex.: ?autor=ID).
  return caches.keys()
    .then((nomes) => Promise.all(nomes.map((nome) => caches.open(nome).then((cache) =>
      Promise.all(HTML_AUTENTICADO.map((caminho) => cache.delete(caminho, { ignoreSearch: true })))
    ))))
    // Nunca derruba a navegação que a disparou: se o Cache Storage falhar, fica a
    // limpeza da página (segunda camada) — que é como tudo funcionava antes.
    .catch(() => {});
}

self.addEventListener('install', (event) => {
  // allSettled: se um item do precache falhar (ícone renomeado, 5xx passageiro),
  // a instalação NÃO é abortada — /offline e os demais ainda entram no cache.
  event.waitUntil(
    caches.open(CACHE)
      .then((cache) => Promise.allSettled(PRECACHE.map((u) => cache.add(u))))
      .then(() => self.skipWaiting())
  );
});

// Versão nova no ar: os caches das anteriores vão embora inteiros — é o que impede o
// /build de acumular o CSS/JS de todo deploy. Vai junto o formulário offline guardado
// pela versão velha, DE PROPÓSITO: ele aponta para o CSS/JS que acabaram de ser
// apagados, e servido offline abriria sem estilo e sem a fila offline (o envio iria
// direto para a rede e se perderia). Ele volta ao cache na próxima vez que a página for
// aberta online. A fila (IndexedDB) não é cache: nada aqui encosta nela.
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

// ---- A página pergunta a versão ---------------------------------------------
// Quando este SW assume uma página que já estava aberta (`controllerchange`), o
// sm/pwa.js pergunta a versão dele e compara com a meta `sm-versao` da própria página:
// se diferem, a página está com CSS/JS de um build anterior e avisa a pessoa. A
// comparação é o que evita o alarme falso — SW novo com o MESMO build (mudou só este
// arquivo) não pede atualização nenhuma.
self.addEventListener('message', (event) => {
  const dados = event.data || {};
  if (dados.tipo === 'sm-versao?' && event.source) {
    event.source.postMessage({ tipo: 'sm-versao', versao: VERSAO });
  }
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
  const url = new URL(req.url);
  const mesmaOrigem = url.origin === self.location.origin;

  // Gatilho 1 da limpeza (ver HTML_AUTENTICADO): o "Sair". Fica ANTES da salvaguarda
  // abaixo e NÃO chama respondWith — o POST segue direto para a rede, como qualquer
  // POST, com o CSRF do formulário. O SW só aproveita a passagem para apagar o HTML
  // autenticado; o `waitUntil` o mantém vivo até a limpeza terminar.
  if (req.method === 'POST' && mesmaOrigem && url.pathname === ROTA_LOGOUT) {
    event.waitUntil(apagarHtmlAutenticado());
    return;
  }

  // SALVAGUARDA: o SW só mexe em GET. Tudo que altera estado (POST/PATCH/DELETE)
  // passa direto pela rede — é o que mantém os forms e o CSRF do Laravel intactos.
  if (req.method !== 'GET') return;

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
    // offline (é o ÚNICO HTML autenticado que guardamos — ver HTML_AUTENTICADO, que
    // também diz quando ele é apagado). Online sempre busca o fresco; offline serve
    // o cacheado. A chave é o caminho SEM query: o deep-link ?autor=ID abre o mesmo.
    if (HTML_AUTENTICADO.includes(url.pathname)) {
      event.respondWith(
        fetch(req)
          .then((res) => {
            // Só guarda o formulário se vier 200 DIRETO — um 302→/login seguido
            // (sessão expirada) chega com res.redirected=true e NÃO pode ser
            // cacheado sob a chave do formulário (senão serve login no lugar).
            if (res.status === 200 && !res.redirected) {
              const copy = res.clone();
              caches.open(CACHE).then((cache) => cache.put(url.pathname, copy)).catch(() => {});
            }
            return res;
          })
          .catch(() => caches.match(url.pathname)
            .then((hit) => hit || caches.match('/offline'))
            .then((r) => r || Response.error()))
      );
      return;
    }

    // Demais páginas: sem rede → página /offline da marca. Nunca cacheia HTML
    // autenticado (saldo sempre fresco; sem dado de um usuário para outro ver).
    const rede = fetch(req);

    // Gatilho 2 da limpeza (ver HTML_AUTENTICADO): página que só abre SEM sessão
    // respondeu 200. A página NÃO espera a limpeza — se o Cache Storage engasgar, o
    // login tem de abrir mesmo assim; o `waitUntil` (registrado já, durante o evento)
    // é que segura o SW vivo até ela terminar. Sem rede, nada a apagar (`() => null`).
    if (mesmaOrigem && ROTAS_SEM_SESSAO.includes(url.pathname)) {
      event.waitUntil(rede.then((res) => (res.status === 200 ? apagarHtmlAutenticado() : null), () => null));
    }

    event.respondWith(
      rede.catch(() => caches.match('/offline').then((r) => r || Response.error()))
    );
    return;
  }

  // Demais GET: tenta rede, cai no cache se houver algo guardado.
  event.respondWith(fetch(req).catch(() => caches.match(req).then((r) => r || Response.error())));
});
@endverbatim
