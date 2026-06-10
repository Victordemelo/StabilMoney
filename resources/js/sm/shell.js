/* ============ StabilMoney — Shell (sidebar, drawer, flash) ============ */
// Sem troca de views via JS: cada item do menu é um link real do Laravel.

export function initShell() {
    const app = document.getElementById('app');
    const sidebar = document.getElementById('sidebar');
    const scrim = document.getElementById('scrim');

    // --- Collapse da sidebar (persistido em localStorage "sm-collapsed") ---
    if (app) {
        try {
            if (localStorage.getItem('sm-collapsed') === '1') app.classList.add('collapsed');
        } catch (e) { /* storage indisponível */ }

        const collapseBtn = document.getElementById('collapseBtn');
        if (collapseBtn) {
            collapseBtn.addEventListener('click', () => {
                const collapsed = app.classList.toggle('collapsed');
                try { localStorage.setItem('sm-collapsed', collapsed ? '1' : '0'); } catch (e) { /* noop */ }
            });
        }
    }

    // --- Drawer mobile (hamburger abre, scrim fecha) ---
    const closeDrawer = () => {
        if (sidebar) sidebar.classList.remove('open');
        if (scrim) scrim.classList.remove('show');
    };
    const mMenu = document.getElementById('mMenu');
    if (mMenu) {
        mMenu.addEventListener('click', () => {
            if (sidebar) sidebar.classList.add('open');
            if (scrim) scrim.classList.add('show');
        });
    }
    if (scrim) scrim.addEventListener('click', closeDrawer);

    // --- Flash de sessão: some sozinho após 4s ---
    document.querySelectorAll('[data-flash]').forEach((el) => {
        setTimeout(() => {
            el.classList.add('hide');
            setTimeout(() => el.remove(), 400); // espera a transição de saída
        }, 4000);
    });
}
