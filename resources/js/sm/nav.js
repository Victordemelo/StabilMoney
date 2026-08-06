/* ============ StabilMoney — Navegação por AJAX (pjax) da sidebar ============ */
// Troca só o #content (o shell — sidebar/topbar/bottom-nav/modal — persiste),
// sem recarregar a página. Re-executa scripts inline do novo conteúdo, reinicia
// os módulos de conteúdo, atualiza título/histórico/estado-ativo e rola ao topo.
// Qualquer imprevisto (erro de rede, sessão expirada, resposta que não é uma
// página do shell) faz FALLBACK para navegação normal — nunca deixa preso.

// Nonce do documento VIVO, capturado uma vez (ele não muda enquanto a página existe).
//
// ⚠️ Lê-se a PROPRIEDADE `.nonce`, nunca `getAttribute('nonce')`: no documento já
// ativo o navegador esconde o nonce do atributo (devolve "") justamente para um XSS
// não conseguir raspá-lo do DOM. A propriedade só é legível por script que já está
// executando — quem tem isso não precisa do nonce.
//
// A origem é a tag que o `@vite` emitiu: o Laravel a carimba sozinho quando o
// middleware chama `Vite::useCspNonce()`.
const NONCE_VIVO = document.querySelector('script[nonce]')?.nonce || '';

export function initNav(reinitContent) {
    const content = document.getElementById('content');
    if (!content) return;

    let loading = false;

    async function load(url, { push = true } = {}) {
        if (loading) return;
        loading = true;
        document.body.classList.add('pjax-loading');

        let resp, html, nonceDaResposta = '';
        try {
            resp = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Pjax': '1' },
                credentials: 'same-origin',
            });
            // Sessão expirada (redirect p/ login) ou erro do servidor → navegação normal.
            if (!resp.ok || resp.redirected) { window.location.href = resp.url || url; return; }
            // Nonce que o servidor emitiu para ESTE HTML. Vem do header, não do
            // corpo: header não pode ser forjado por conteúdo armazenado.
            nonceDaResposta = resp.headers.get('X-Csp-Nonce') || '';
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

        // Valida ANTES de encostar no DOM vivo — ver `descartarScriptsSemNonce`.
        descartarScriptsSemNonce(novo, nonceDaResposta);

        content.innerHTML = novo.innerHTML;
        runScripts(content);
        if (typeof reinitContent === 'function') reinitContent();
        setActive(url);

        if (push) history.pushState({ pjax: true }, '', url);
        content.scrollTop = 0;
        window.scrollTo(0, 0);
    }

    /**
     * Descarta, no documento INERTE do DOMParser, todo script que não carregue o
     * nonce que o servidor declarou no header desta resposta.
     *
     * Precisa ser aqui, e não depois do `innerHTML`: assim que o script entra num
     * documento VIVO o navegador esvazia o atributo `nonce` (guarda o valor num
     * slot interno, exposto só pela propriedade `.nonce`) justamente para um XSS
     * não conseguir raspá-lo do DOM. Medido no Chrome:
     *
     *     documento inerte → getAttribute('nonce') = "ABC123" · .nonce = "ABC123"
     *     documento vivo   → getAttribute('nonce') = ""       · .nonce = "ABC123"
     *
     * Comparar no documento vivo, portanto, nunca casaria — e a guarda apagaria
     * justamente os scripts legítimos, deixando a tela sem comportamento.
     *
     * Um XSS armazenado que venha no corpo não tem nonce (ou tem um chutado), não
     * casa, e é descartado antes de existir no documento vivo. Sem esta checagem o
     * pjax seria um buraco aberto: carimbaria o nonce válido em QUALQUER script
     * vindo do corpo, dando de graça o que a CSP existe para negar.
     */
    function descartarScriptsSemNonce(raiz, nonceDaResposta) {
        raiz.querySelectorAll('script').forEach((s) => {
            const type = (s.getAttribute('type') || '').toLowerCase();
            // Bloco de dados (ex.: application/json do dashboard) não executa: fica.
            if (type && type !== 'text/javascript' && type !== 'module') return;

            if (!nonceDaResposta || s.getAttribute('nonce') !== nonceDaResposta) s.remove();
        });
    }

    // innerHTML não executa <script>: recria os scripts JS inline p/ rodarem.
    // Blocos de dados (type diferente de js) ficam intactos.
    //
    // O que chega aqui já passou pelo `descartarScriptsSemNonce`. O script recriado
    // leva o nonce do documento VIVO — é contra ele que o navegador valida —, e por
    // PROPRIEDADE (`s.nonce = ...`), que é a única forma aceita num elemento criado
    // por script: `setAttribute('nonce', ...)` não alimenta o slot interno.
    function runScripts(container) {
        container.querySelectorAll('script').forEach((old) => {
            const type = (old.getAttribute('type') || '').toLowerCase();
            if (type && type !== 'text/javascript' && type !== 'module') return;

            const s = document.createElement('script');
            for (const attr of old.attributes) {
                if (attr.name.toLowerCase() === 'nonce') continue;
                s.setAttribute(attr.name, attr.value);
            }
            s.nonce = NONCE_VIVO;
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
