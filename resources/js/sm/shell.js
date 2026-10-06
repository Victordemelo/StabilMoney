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
                // Foco DENTRO do popover (Tab até "Configurações" + Esc) volta para o
                // botão que o abriu, ANTES de esconder. Fechado, o popover fica
                // `visibility: hidden` e sai da ordem do Tab — medido no Chromium: sem
                // isto o foco ficava ~220ms num link invisível, depois caía no <body>,
                // e o Tab seguinte ia parar na topbar, longe de onde a pessoa estava.
                // É o padrão de "menu button" do WAI-ARIA: Esc fecha e devolve o foco.
                // Só quando o foco está lá dentro: num clique fora ele já foi para onde
                // a pessoa clicou, e puxá-lo de volta roubaria a interação dela.
                if (pop.contains(document.activeElement)) btn.focus();
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

    /**
     * Quanto o `env(safe-area-inset-*)` daquele lado mede agora (0 fora do app instalado).
     * O JS não lê `env()` direto: uma sonda invisível com o padding dele diz o valor.
     */
    const areaSegura = (lado) => {
        const sonda = document.createElement('div');
        sonda.style.cssText = `position:fixed;top:0;${lado}:0;width:0;height:0;visibility:hidden;pointer-events:none;`
            + `padding-${lado}:env(safe-area-inset-${lado}, 0px)`;
        document.body.appendChild(sonda);
        const valor = sonda.getBoundingClientRect().width || 0;
        sonda.remove();
        return valor;
    };

    // Perfil: posição fixa ancorada no botão (reposiciona em resize/scroll)
    const profileBtn = document.getElementById('profileBtn');
    const profilePop = document.getElementById('profilePop');
    const posicionarPerfil = () => {
        if (!profileBtn || !profilePop) return;
        const r = profileBtn.getBoundingClientRect();
        const w = profilePop.offsetWidth || 256;
        // No app instalado com o celular deitado, o notch fica na lateral: a margem de 12px
        // conta a partir da ÁREA SEGURA, senão o menu abre embaixo do notch.
        const esq = 12 + areaSegura('left');
        const dir = 12 + areaSegura('right');
        const left = Math.max(esq, Math.min(r.left, window.innerWidth - w - dir));
        profilePop.style.left = left + 'px';
        // Embaixo, a barra de gestos (celular deitado no app instalado): nunca abaixo dela.
        profilePop.style.bottom = Math.max(window.innerHeight - r.top + 10, 12 + areaSegura('bottom')) + 'px';
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

    // (O aviso de sucesso do servidor virou balão no canto — sm/balao.js.)
}
