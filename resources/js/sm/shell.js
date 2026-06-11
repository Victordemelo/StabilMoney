/* ============ StabilMoney — Shell (sidebar, drawer, popover do perfil, flash) ============ */
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

    // --- Popover do perfil (.side-foot abre; fecha com clique fora ou Esc) ---
    const profileBtn = document.getElementById('profileBtn');
    const profilePop = document.getElementById('profilePop');
    if (profileBtn && profilePop) {
        let aberto = false;

        // Posição fixa ancorada no botão (mesma conta do protótipo v2)
        const posicionar = () => {
            const r = profileBtn.getBoundingClientRect();
            const w = profilePop.offsetWidth || 256;
            const left = Math.max(12, Math.min(r.left, window.innerWidth - w - 12));
            profilePop.style.left = left + 'px';
            profilePop.style.bottom = (window.innerHeight - r.top + 10) + 'px';
        };
        const abrirPop = () => {
            aberto = true;
            profileBtn.setAttribute('aria-expanded', 'true');
            profilePop.setAttribute('aria-hidden', 'false');
            profilePop.classList.add('open');
            posicionar();
        };
        const fecharPop = () => {
            if (!aberto) return;
            aberto = false;
            profileBtn.setAttribute('aria-expanded', 'false');
            profilePop.setAttribute('aria-hidden', 'true');
            profilePop.classList.remove('open');
        };

        profileBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            aberto ? fecharPop() : abrirPop();
        });
        document.addEventListener('click', (e) => {
            if (aberto && !profilePop.contains(e.target) && !profileBtn.contains(e.target)) fecharPop();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') fecharPop();
        });
        window.addEventListener('resize', () => {
            if (aberto) posicionar();
        });
        const content = document.getElementById('content');
        if (content) content.addEventListener('scroll', () => {
            if (aberto) posicionar();
        });
    }

    // --- Flash de sessão: some sozinho após 4s ---
    document.querySelectorAll('[data-flash]').forEach((el) => {
        setTimeout(() => {
            el.classList.add('hide');
            setTimeout(() => el.remove(), 400); // espera a transição de saída
        }, 4000);
    });
}
