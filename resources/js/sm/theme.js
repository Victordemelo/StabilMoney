/* ============ StabilMoney — Tema claro/escuro ============ */
// O atributo data-theme já é aplicado por um script inline no <head> dos
// layouts (anti-flash). Aqui só sincronizamos o ícone e ligamos os toggles.

const SUN = '<circle cx="12" cy="12" r="4.5"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5l1.5 1.5M17.5 17.5 19 19M19 5l-1.5 1.5M6.5 17.5 5 19"/>';
const MOON = '<path d="M20 14.5A8 8 0 1 1 9.5 4a6.3 6.3 0 0 0 10.5 10.5Z"/>';

// Troca o desenho sol/lua nos botões de tema (desktop e mobile)
function syncIcons(theme) {
    const ic = theme === 'dark' ? MOON : SUN;
    const themeIcon = document.getElementById('themeIcon');
    if (themeIcon) themeIcon.innerHTML = ic;
    const mt = document.querySelector('#mTheme svg');
    if (mt) mt.innerHTML = ic;
}

/* As "bordas do sistema" — barra do navegador/status no celular — não são
   pintadas pelo CSS: elas leem metas do <head>. O inline anti-flash do layout já
   as acerta na primeira pintura; aqui refazemos a mesma conta quando o usuário
   TROCA o tema, senão a barra ficava com a cor do tema anterior até um reload.

   Os hex vêm dos data-light/data-dark da própria meta (fonte única: o layout, que
   copia os tokens `--bg` do design-system.css). Só mexemos na meta marcada com
   `data-sm-theme` — as telas de auth têm cor fixa (o topo delas é o painel verde
   do vídeo) e ficam de fora. */
function syncSystemColors(theme) {
    const cor = document.querySelector('meta[name="theme-color"][data-sm-theme]');
    if (cor) {
        const valor = cor.getAttribute(theme === 'dark' ? 'data-dark' : 'data-light');
        if (valor) cor.setAttribute('content', valor);
    }

    // iOS (app instalado): `black` = glifos claros, `default` = glifos escuros.
    // Nem toda versão do iOS reaplica esta meta sem recarregar a página — por
    // isso o valor certo também sai do servidor/inline; ver partials/pwa-head.
    const barra = document.querySelector('meta[name="apple-mobile-web-app-status-bar-style"]');
    if (barra) barra.setAttribute('content', theme === 'dark' ? 'black' : 'default');
}

function setTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    try { localStorage.setItem('sm-theme', theme); } catch (e) { /* storage indisponível */ }
    syncIcons(theme);
    syncSystemColors(theme);
}

export function initTheme() {
    const atual = document.documentElement.getAttribute('data-theme') || 'light';
    syncIcons(atual);
    // Rede de segurança: se o inline do <head> não tiver rodado (página servida
    // de um cache antigo, storage bloqueado), as metas se acertam aqui.
    syncSystemColors(atual);

    const toggle = () =>
        setTheme(document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');

    const themeBtn = document.getElementById('themeBtn');
    if (themeBtn) themeBtn.addEventListener('click', toggle);
    const mTheme = document.getElementById('mTheme');
    if (mTheme) mTheme.addEventListener('click', toggle);
}
