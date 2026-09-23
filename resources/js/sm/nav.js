/* ============ StabilMoney — Navegação por AJAX (pjax) da sidebar ============ */
// Troca só o #content (o shell — sidebar/topbar/bottom-nav/modal — persiste),
// sem recarregar a página. Re-executa scripts inline do novo conteúdo, reinicia
// os módulos de conteúdo, atualiza título/histórico/estado-ativo e rola ao topo.
// Também traz da página nova o que o SHELL mostra de dado do servidor (o sino),
// anuncia a troca para o leitor de tela e leva o foco até o conteúdo novo.
// Qualquer imprevisto (erro de rede, sessão expirada, resposta que não é uma
// página do shell, build novo no ar) faz FALLBACK para navegação normal — nunca
// deixa preso.

import { EVENTO_VERSAO_NOVA } from './pwa';

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

    // Versão nova do app instalada com esta página aberta (ver `sm/pwa.js`): o CSS/JS
    // desta aba ficaram para trás, e trocar só o #content montaria a tela nova com eles.
    // Daqui em diante toda navegação é COMPLETA — inclusive o `smPjaxReload` depois de
    // salvar no modal, que é quando um reload menos atrapalha.
    let versaoNova = false;
    window.addEventListener(EVENTO_VERSAO_NOVA, () => { versaoNova = true; });

    /**
     * `recarga` = a MESMA página, montada de novo depois de uma ação (o `smPjaxReload`).
     * Ela não se anuncia nem puxa o foco — a pessoa não saiu de onde estava —, a não ser
     * que o foco estivesse dentro do conteúdo substituído, e aí ele cairia no <body>.
     */
    async function load(url, { push = true, recarga = false } = {}) {
        if (loading) return;
        if (versaoNova) { navegarSemPjax(url); return; }
        loading = true;
        document.body.classList.add('pjax-loading');

        let resp, html, nonceDaResposta = '';
        try {
            resp = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Pjax': '1' },
                credentials: 'same-origin',
            });
            // Sessão expirada (redirect p/ login) ou erro do servidor → navegação normal.
            if (!resp.ok || resp.redirected) { navegarSemPjax(resp.url || url); return; }
            // Nonce que o servidor emitiu para ESTE HTML. Vem do header, não do
            // corpo: header não pode ser forjado por conteúdo armazenado.
            nonceDaResposta = resp.headers.get('X-Csp-Nonce') || '';
            html = await resp.text();
        } catch (_) {
            navegarSemPjax(url); // sem rede/erro → deixa o navegador tentar
            return;
        } finally {
            loading = false;
            document.body.classList.remove('pjax-loading');
        }

        const doc = new DOMParser().parseFromString(html, 'text/html');
        const novo = doc.getElementById('content');
        if (!novo) { navegarSemPjax(url); return; } // não é página do shell

        // Deploy entre o carregamento desta aba e agora (P-6 da auditoria de 06/09/2026):
        // o HTML novo foi feito para o CSS/JS novos, e esta aba ainda roda os velhos. A
        // meta `sm-versao` sai do mesmo cálculo que versiona o service worker.
        if (versaoDoBuild(doc) !== versaoDoBuild(document)) { navegarSemPjax(url); return; }

        if (doc.title) document.title = doc.title;

        // Valida ANTES de encostar no DOM vivo — ver `descartarScriptsSemNonce`.
        descartarScriptsSemNonce(novo, nonceDaResposta);

        const focoNoConteudo = content.contains(document.activeElement);

        content.innerHTML = novo.innerHTML;
        runScripts(content);
        atualizarShell(doc);
        if (typeof reinitContent === 'function') reinitContent();
        setActive(url);

        if (push) history.pushState({ pjax: true }, '', url);
        content.scrollTop = 0;
        window.scrollTo(0, 0);

        // A troca era silenciosa para quem usa leitor de tela: o conteúdo mudava e o foco
        // ficava no link clicado (achado moderado da auditoria de acessibilidade de 07/09).
        if (!recarga) {
            anunciar(document.title);
            focarConteudo();
        } else if (focoNoConteudo) {
            focarConteudo();
        }
    }

    // Carregamento completo pelo navegador — a saída de emergência de toda anomalia. Para
    // o MESMO endereço (o `smPjaxReload`, ou o voltar do navegador), é recarregar a página.
    function navegarSemPjax(url) {
        const destino = new URL(url, location.href);
        if (destino.href.split('#')[0] === location.href.split('#')[0]) window.location.reload();
        else window.location.href = destino.href;
    }

    /** A versão do build com que um documento foi montado ('' = sem a meta). */
    function versaoDoBuild(doc) {
        return doc.querySelector('meta[name="sm-versao"]')?.getAttribute('content') || '';
    }

    /**
     * Traz da página nova o que o SHELL mostra de dado do servidor (P-4 da auditoria de
     * 06/09/2026). O shell não é trocado pelo pjax — era por isso que o sino seguia com o
     * número de quando a aba abriu: criar uma conta fixa que vence hoje e navegar não
     * mudava nada até um reload completo.
     *
     * Opt-in por elemento: quem mostra dado do servidor leva `data-pjax-atualizar` e um
     * `id` (o par é achado pelo id na página nova). Os FILHOS são trocados; atributos, só
     * os listados no valor (ex.: "class aria-label"). Todo o resto do elemento é estado do
     * JS e fica como está: o `aria-expanded` do botão do sino, a classe `open` do popover
     * e os listeners, que moram no elemento — ele nunca é substituído.
     *
     * Os filhos entram como NÓS clonados do documento inerte do DOMParser, nunca por
     * `innerHTML`: o nome de uma conta fixa é dado do usuário, e ele chega aqui já como
     * texto — reserializar e reinterpretar como HTML é o que abriria a porta a um XSS.
     * Script que viesse junto sai antes (clone de documento inerte não executa, mas não
     * há por que carregá-lo).
     */
    function atualizarShell(doc) {
        document.querySelectorAll('[data-pjax-atualizar][id]').forEach((vivo) => {
            const novo = doc.getElementById(vivo.id);
            if (!novo || !novo.hasAttribute('data-pjax-atualizar')) return;

            const atributos = (vivo.getAttribute('data-pjax-atualizar') || '').split(/\s+/)
                // Manipulador inline nunca: a CSP o bloqueia, e não há por que copiá-lo.
                .filter((nome) => nome && !/^on/i.test(nome));
            for (const nome of atributos) {
                if (novo.hasAttribute(nome)) vivo.setAttribute(nome, novo.getAttribute(nome));
                else vivo.removeAttribute(nome);
            }

            novo.querySelectorAll('script').forEach((s) => s.remove());
            vivo.replaceChildren(...[...novo.childNodes].map((no) => document.importNode(no, true)));
        });
    }

    /**
     * Escreve o título da página nova na região viva `#sm-anuncio` (layouts/app), que o
     * leitor de tela lê sozinho. Um parágrafo NOVO a cada anúncio: trocar o texto por um
     * igual (navegar duas vezes para a mesma tela) pode não ser anunciado; nó novo é.
     */
    function anunciar(texto) {
        const regiao = document.getElementById('sm-anuncio');
        if (!regiao || !texto) return;

        const aviso = document.createElement('p');
        aviso.textContent = texto;
        regiao.replaceChildren(aviso);
    }

    /**
     * Leva o foco ao título da página nova — o primeiro h1/h2 do conteúdo que de fato
     * aceite foco —, ou ao próprio #content. Assim o Tab seguinte continua da tela nova, e
     * o leitor de tela lê a partir dela, em vez de o foco ficar num link do menu.
     *
     * "De fato aceite": no desktop o h1 do dashboard está escondido (`display: none`), e
     * o navegador simplesmente não foca elemento escondido — por isso a conferência pelo
     * `activeElement` e a tentativa do candidato seguinte. Título dentro de algo marcado
     * `aria-hidden` (o card-fantasma de Dependentes) nem entra: foco em conteúdo que o
     * leitor de tela não enxerga o deixaria perdido.
     */
    function focarConteudo() {
        const candidatos = [...content.querySelectorAll('h1, h2'), content];

        for (const alvo of candidatos) {
            if (alvo !== content && alvo.closest('[aria-hidden="true"]')) continue;
            // `tabindex="-1"`: focável por script, fora da ordem do Tab.
            if (!alvo.hasAttribute('tabindex')) alvo.setAttribute('tabindex', '-1');
            // Sem rolar: o topo já foi posto no lugar acima.
            alvo.focus({ preventScroll: true });
            if (document.activeElement === alvo) return;
        }
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
    window.smPjaxReload = () => load(location.href, { push: false, recarga: true });
}
