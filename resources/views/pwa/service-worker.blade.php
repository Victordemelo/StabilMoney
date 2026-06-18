@verbatim
/*
 * Service worker do Stabil Money (PWA) — servido por PwaController@serviceWorker.
 * NÃO é processado pelo Vite. Para invalidar caches antigos, suba a versão abaixo.
 *
 * Estratégia (conservadora de propósito, para NÃO quebrar o app):
 *  - Só intercepta GET. POST/PATCH/DELETE (forms + CSRF do Laravel) passam direto.
 *  - Assets estáticos (build do Vite, ícones, fontes): cache-first.
 *  - Navegações: network-first → cai na página /offline quando sem rede.
 *  - NUNCA guarda HTML autenticado (saldo sempre fresco; sem dado de um usuário
 *    em cache para outro ver).
 */
const CACHE = 'sm-cache-v1';

// Precache mínimo: tudo público, sem dado do usuário.
const PRECACHE = [
  '/offline',
  '/assets/icons/icon-192.png',
  '/assets/icons/icon-512.png',
  '/assets/icons/icon-maskable-512.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE)
      .then((cache) => cache.addAll(PRECACHE))
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

  // Assets estáticos same-origin (build do Vite, ícones, logo) e Google Fonts:
  // cache-first (imutáveis/versionados) → app abre offline depois da 1ª visita.
  const isStaticAsset =
    (url.origin === self.location.origin &&
      (url.pathname.startsWith('/build/') || url.pathname.startsWith('/assets/'))) ||
    url.host.endsWith('fonts.googleapis.com') ||
    url.host.endsWith('fonts.gstatic.com');

  if (isStaticAsset) {
    event.respondWith(
      caches.match(req).then((hit) => {
        if (hit) return hit;
        return fetch(req).then((res) => {
          const copy = res.clone();
          caches.open(CACHE).then((cache) => cache.put(req, copy));
          return res;
        });
      })
    );
    return;
  }

  // Navegações (páginas): network-first. Sem rede → página /offline da marca.
  // Não cacheia a resposta (HTML pode ser autenticado).
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(() => caches.match('/offline'))
    );
    return;
  }

  // Demais GET: tenta rede, cai no cache se houver algo guardado.
  event.respondWith(fetch(req).catch(() => caches.match(req)));
});
@endverbatim
