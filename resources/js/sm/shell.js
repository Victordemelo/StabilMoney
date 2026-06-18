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

    // --- Topbar: transparente no topo, ganha fundo ao rolar (o efeito passa por trás) ---
    if (app) {
        const scrollEl = document.getElementById('content'); // desktop rola no .content; mobile rola no window
        const onScroll = () => {
            const rolou = (scrollEl && scrollEl.scrollTop > 6) || window.scrollY > 6;
            app.classList.toggle('scrolled', rolou);
        };
        if (scrollEl) scrollEl.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll(); // estado inicial (ex.: recarregar já rolado)
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

    // --- Popovers (perfil na sidebar + notificações na topbar) ---
    // Um helper só: abrir um fecha os outros; um único clique-fora/Esc para todos.
    const popovers = [];
    const makePopover = (btn, pop, onOpen) => {
        if (!btn || !pop) return null;
        let aberto = false;
        const api = {
            get aberto() { return aberto; },
            contemAlvo: (t) => pop.contains(t) || btn.contains(t),
            fechar() {
                if (!aberto) return;
                aberto = false;
                btn.setAttribute('aria-expanded', 'false');
                pop.setAttribute('aria-hidden', 'true');
                pop.classList.remove('open');
            },
            abrir() {
                popovers.forEach((p) => { if (p !== api) p.fechar(); }); // só um aberto por vez
                aberto = true;
                btn.setAttribute('aria-expanded', 'true');
                pop.setAttribute('aria-hidden', 'false');
                pop.classList.add('open');
                if (onOpen) onOpen();
            },
        };
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            aberto ? api.fechar() : api.abrir();
        });
        popovers.push(api);
        return api;
    };

    // Perfil: posição fixa ancorada no botão (reposiciona em resize/scroll)
    const profileBtn = document.getElementById('profileBtn');
    const profilePop = document.getElementById('profilePop');
    const posicionarPerfil = () => {
        if (!profileBtn || !profilePop) return;
        const r = profileBtn.getBoundingClientRect();
        const w = profilePop.offsetWidth || 256;
        const left = Math.max(12, Math.min(r.left, window.innerWidth - w - 12));
        profilePop.style.left = left + 'px';
        profilePop.style.bottom = (window.innerHeight - r.top + 10) + 'px';
    };
    const perfilPop = makePopover(profileBtn, profilePop, posicionarPerfil);
    if (perfilPop) {
        window.addEventListener('resize', () => { if (perfilPop.aberto) posicionarPerfil(); });
        const content = document.getElementById('content');
        if (content) content.addEventListener('scroll', () => { if (perfilPop.aberto) posicionarPerfil(); });
    }

    // Notificações: posicionado via CSS absoluto (não precisa reposicionar)
    makePopover(document.getElementById('notifBtn'), document.getElementById('notifPop'));

    // Clique fora fecha o popover aberto; Esc fecha todos
    document.addEventListener('click', (e) => {
        popovers.forEach((p) => { if (p.aberto && !p.contemAlvo(e.target)) p.fechar(); });
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') popovers.forEach((p) => p.fechar());
    });

    // --- Flash de sessão: some sozinho após 4s ---
    document.querySelectorAll('[data-flash]').forEach((el) => {
        setTimeout(() => {
            el.classList.add('hide');
            setTimeout(() => el.remove(), 400); // espera a transição de saída
        }, 4000);
    });
}
