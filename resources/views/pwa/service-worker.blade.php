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
