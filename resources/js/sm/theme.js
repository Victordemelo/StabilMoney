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

function setTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    try { localStorage.setItem('sm-theme', theme); } catch (e) { /* storage indisponível */ }
    syncIcons(theme);
}

export function initTheme() {
    syncIcons(document.documentElement.getAttribute('data-theme') || 'light');

    const toggle = () =>
        setTheme(document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');

    const themeBtn = document.getElementById('themeBtn');
    if (themeBtn) themeBtn.addEventListener('click', toggle);
    const mTheme = document.getElementById('mTheme');
    if (mTheme) mTheme.addEventListener('click', toggle);
}
