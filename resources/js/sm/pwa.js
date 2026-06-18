// PWA: registra o service worker (/sw.js) para o app ser instalável e
// funcionar offline. Tudo defensivo — feature-detection + try/catch — para
// que uma falha de registro NUNCA quebre a página.

export function initPwa() {
    if (!('serviceWorker' in navigator)) return;

    // Registra após o load para não competir com o carregamento da página.
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch((err) => {
            console.warn('PWA: service worker não registrado —', err);
        });
    });
}
