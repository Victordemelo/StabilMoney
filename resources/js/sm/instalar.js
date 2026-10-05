/**
 * "Instalar o app" (PWA) — na tela de login e em Configurações › Conta (out/2026).
 *
 * O Chrome/Edge/Android avisam que o app é instalável com o `beforeinstallprompt`: o
 * evento é guardado e o botão `[data-instalar-app]` aparece; o clique abre a janela de
 * instalação do próprio navegador. O Safari do iPhone não tem esse evento — lá aparece a
 * instrução `[data-instalar-ios]` (Compartilhar › Adicionar à Tela de Início). Já instalado
 * (aberto como app), mostra `[data-instalado]`. Celular só instala com HTTPS: em http (dev
 * pelo IP do Wi-Fi) o evento nunca vem e o botão simplesmente não aparece.
 */
let convite = null;

const instalado = () =>
    (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
    || window.navigator.standalone === true;

const ehIos = () => /iphone|ipad|ipod/i.test(window.navigator.userAgent || '')
    && !/crios|fxios|edgios/i.test(window.navigator.userAgent || '');

/** Mostra o que vale para ESTE navegador. Roda na carga e a cada troca de conteúdo (pjax). */
export function aplicarEstadoDeInstalacao(raiz = document) {
    const jaInstalado = instalado();
    raiz.querySelectorAll('[data-instalar-app]').forEach((btn) => { btn.hidden = jaInstalado || !convite; });
    raiz.querySelectorAll('[data-instalar-ios]').forEach((el) => { el.hidden = jaInstalado || convite !== null || !ehIos(); });
    raiz.querySelectorAll('[data-instalado]').forEach((el) => { el.hidden = !jaInstalado; });
}

export function initInstalar() {
    // O evento chega UMA vez, cedo: o ouvinte entra antes de qualquer tela pedir o botão.
    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        convite = e;
        aplicarEstadoDeInstalacao();
    });
    window.addEventListener('appinstalled', () => {
        convite = null;
        aplicarEstadoDeInstalacao();
    });

    // Delegação: o botão da tela de Configurações chega pelo pjax.
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest ? e.target.closest('[data-instalar-app]') : null;
        if (!btn || !convite) return;
        e.preventDefault();
        const evento = convite;
        convite = null; // o convite só pode ser usado uma vez
        evento.prompt();
        try { await evento.userChoice; } catch (erro) { /* janela fechada */ }
        aplicarEstadoDeInstalacao();
    });

    aplicarEstadoDeInstalacao();
}
