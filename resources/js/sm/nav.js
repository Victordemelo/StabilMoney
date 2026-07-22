/* ============ StabilMoney — Navegação por AJAX (pjax) da sidebar ============ */
// Troca só o #content (o shell — sidebar/topbar/bottom-nav/modal — persiste),
// sem recarregar a página. Re-executa scripts inline do novo conteúdo, reinicia
// os módulos de conteúdo, atualiza título/histórico/estado-ativo e rola ao topo.
// Qualquer imprevisto (erro de rede, sessão expirada, resposta que não é uma
// página do shell) faz FALLBACK para navegação normal — nunca deixa preso.

export function initNav(reinitContent) {
    const content = document.getElementById('content');
    if (!content) return;

    let loading = false;

    async function load(url, { push = true } = {}) {
        if (loading) return;
        loading = true;
        document.body.classList.add('pjax-loading');

        let resp, html;
        try {
            resp = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Pjax': '1' },
                credentials: 'same-origin',
            });
            // Sessão expirada (redirect p/ login) ou erro do servidor → navegação normal.
            if (!resp.ok || resp.redirected) { window.location.href = resp.url || url; return; }
            html = await resp.text();
        } catch (_) {
            window.location.href = url; // sem rede/erro → deixa o navegador tentar
            return;
        } finally {
            loading = false;
            document.body.classList.remove('pjax-loading');
        }

        const doc = new DOMParser().parseFromString(html, 'text/html');
        const novo = doc.getElementById('content');
        if (!novo) { window.location.href = url; return; } // não é página do shell

        if (doc.title) document.title = doc.title;

        content.innerHTML = novo.innerHTML;
        runScripts(content);
        if (typeof reinitContent === 'function') reinitContent();
        setActive(url);

        if (push) history.pushState({ pjax: true }, '', url);
        content.scrollTop = 0;
        window.scrollTo(0, 0);
    }

    // innerHTML não executa <script>: recria os scripts JS inline p/ rodarem.
    // Blocos de dados (type diferente de js, ex.: application/json do dashboard) ficam intactos.
    function runScripts(container) {
        container.querySelectorAll('script').forEach((old) => {
            const type = (old.getAttribute('type') || '').toLowerCase();
            if (type && type !== 'text/javascript' && type !== 'module') return;
            const s = document.createElement('script');
            for (const attr of old.attributes) s.setAttribute(attr.name, attr.value);
            s.textContent = old.textContent;
            old.replaceWith(s);
        });
    }

    // Marca ativo o item de menu correspondente ao caminho atual (cobre subrotas).
    function setActive(url) {
        const path = new URL(url, location.origin).pathname;
        document.querySelectorAll('.nav-item, .bn-item').forEach((a) => {
            const href = a.getAttribute('href');
            if (!href || href === '#') return;
            const p = new URL(href, location.origin).pathname;
            const match = p === '/' ? path === '/' : (path === p || path.startsWith(p + '/'));
            a.classList.toggle('active', match);
        });
    }

    // Intercepta os cliques dos links marcados com data-pjax.
    document.querySelectorAll('[data-pjax]').forEach((a) => {
        a.addEventListener('click', (e) => {
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1) return; // nova aba etc.
            e.preventDefault();
            // Fecha o drawer mobile ao navegar.
            const sidebar = document.getElementById('sidebar');
            const scrim = document.getElementById('scrim');
            if (sidebar) sidebar.classList.remove('open');
            if (scrim) scrim.classList.remove('show');
            load(a.getAttribute('href'));
        });
    });

    window.addEventListener('popstate', () => load(location.href, { push: false }));

    // Recarrega a página atual sem reload (usado pelo modal "Lançar" após salvar).
    window.smPjaxReload = () => load(location.href, { push: false });
}
