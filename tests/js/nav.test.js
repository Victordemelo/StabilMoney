import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Guarda da CSP no pjax (`resources/js/sm/nav.js`).
 *
 * O pjax troca o `#content` por HTML vindo do servidor e RECRIA os `<script>`
 * de dentro dele — `innerHTML` não executa script, então sem recriar a tela
 * ficaria inerte. Só que recriar é justamente o que reabriria o buraco que a CSP
 * fecha: o script recriado leva o nonce do documento vivo, e o navegador o
 * aceita. Se qualquer script do corpo fosse recriado, um XSS armazenado (o
 * achado do pentest de jul/2026) voltaria a executar — de graça.
 *
 * `descartarScriptsSemNonce()` é a única coisa entre as duas pontas, e ela erra
 * nos DOIS sentidos: deixar passar script injetado é XSS; apagar script legítimo
 * deixa a tela renderizada e sem comportamento nenhum, em silêncio (o navegador
 * não avisa). Daí a cobertura ir pelos dois lados em cada caso.
 *
 * Testa-se pelo caminho real (clicar num `[data-pjax]` e deixar o módulo fazer o
 * fetch), e não chamando as funções direto: elas são internas a `initNav` e não
 * há por que abrir a API do módulo só para o teste enxergar.
 */

import { flush } from './helpers/flush.js';

const NONCE_VIVO = 'nonce-do-documento-vivo';
const NONCE_DA_RESPOSTA = 'nonce-emitido-para-esta-resposta';

/** Envelope mínimo de `Response` que o `load()` consome. */
function resposta(html, { nonce = NONCE_DA_RESPOSTA, url = '/metas' } = {}) {
    return {
        ok: true,
        redirected: false,
        url,
        headers: { get: (h) => (h.toLowerCase() === 'x-csp-nonce' ? nonce : null) },
        text: async () => html,
    };
}

/** Documento completo servido pelo pjax, com o `#content` que ele procura. */
function pagina(dentroDoContent, { titulo = 'Metas' } = {}) {
    return `<!doctype html><html><head><title>${titulo}</title></head>`
        + `<body><div id="content">${dentroDoContent}</div></body></html>`;
}

let reinitContent;

/**
 * Monta o shell vivo, liga o `initNav` e devolve o disparador da navegação.
 *
 * O `NONCE_VIVO` é lido no TOPO do módulo (uma vez, na carga), então o `<script
 * nonce>` do shell precisa existir antes do import — daí o `resetModules` + import
 * dinâmico a cada teste.
 */
async function montarShell(html, opcoesDaResposta = {}) {
    document.head.innerHTML = `<script nonce="${NONCE_VIVO}"></script>`;
    document.body.innerHTML = `
        <a id="ir" class="nav-item" data-pjax href="/metas">Metas</a>
        <a id="ficar" class="nav-item active" data-pjax href="/">Visão geral</a>
        <div id="marcas"></div>
        <div id="content"><p id="antigo">conteúdo anterior</p></div>
    `;

    global.fetch = vi.fn(async () => resposta(html, opcoesDaResposta));
    reinitContent = vi.fn();

    vi.resetModules();
    const { initNav } = await import('../../resources/js/sm/nav.js');
    initNav(reinitContent);

    return async () => {
        document.getElementById('ir').click();
        await flush();
    };
}

/** O que os scripts sobreviventes escrevem ao rodar (marcador no DOM vivo). */
function marcas() {
    return document.getElementById('marcas').textContent.trim();
}

/** `<script>` que deixa rastro ao executar. `nonce` ausente = sem o atributo. */
function script(marca, { nonce, type } = {}) {
    const attrs = [
        nonce === undefined ? '' : ` nonce="${nonce}"`,
        type ? ` type="${type}"` : '',
    ].join('');

    return `<script${attrs}>document.getElementById('marcas').append('${marca} ')</` + 'script>';
}

beforeEach(() => {
    // `window.scrollTo` não existe de verdade no jsdom; sem o stub cada navegação
    // despeja um "Not implemented" no relatório.
    window.scrollTo = vi.fn();
    history.replaceState(null, '', '/');
});

afterEach(() => {
    vi.restoreAllMocks();
    delete window.smPjaxReload;
});

describe('script legítimo sobrevive e roda', () => {
    it('mantém o script que traz o nonce declarado no header da resposta', async () => {
        const navegar = await montarShell(pagina(script('legitimo', { nonce: NONCE_DA_RESPOSTA })));
        await navegar();

        expect(document.querySelectorAll('#content script')).toHaveLength(1);
        expect(marcas()).toBe('legitimo');
    });

    it('mantém vários scripts legítimos, na ordem em que vieram', async () => {
        const navegar = await montarShell(pagina(
            script('primeiro', { nonce: NONCE_DA_RESPOSTA })
            + script('segundo', { nonce: NONCE_DA_RESPOSTA }),
        ));
        await navegar();

        expect(marcas()).toBe('primeiro segundo');
    });

    it('aceita type="text/javascript" e type="module" como JS legítimo', async () => {
        // Os dois são código executável e passam pela mesma exigência de nonce.
        // Sobrevivência é o que dá para afirmar dos dois: o jsdom não executa
        // `type="module"` (não implementa módulos ES em script), então só o
        // clássico deixa rastro. Um deles legítimo e o outro descartado seria
        // visível aqui na contagem.
        const navegar = await montarShell(pagina(
            script('classico', { nonce: NONCE_DA_RESPOSTA, type: 'text/javascript' })
            + script('modulo', { nonce: NONCE_DA_RESPOSTA, type: 'module' }),
        ));
        await navegar();

        expect(document.querySelectorAll('#content script')).toHaveLength(2);
        expect(document.querySelector('#content script[type="module"]').nonce).toBe(NONCE_VIVO);
        expect(marcas()).toBe('classico');
    });

    it('exige nonce também de type="module" (não é passe livre)', async () => {
        const navegar = await montarShell(pagina(script('modulo-xss', { type: 'module' })));
        await navegar();

        expect(document.querySelectorAll('#content script')).toHaveLength(0);
    });
});

describe('script sem nonce válido é descartado', () => {
    it('descarta script SEM atributo nonce nenhum', async () => {
        const navegar = await montarShell(pagina(script('sem-nonce')));
        await navegar();

        expect(document.querySelectorAll('#content script')).toHaveLength(0);
        expect(marcas()).toBe('');
    });

    it('descarta script com nonce CHUTADO', async () => {
        const navegar = await montarShell(pagina(script('chutado', { nonce: 'nonce-inventado' })));
        await navegar();

        expect(document.querySelectorAll('#content script')).toHaveLength(0);
        expect(marcas()).toBe('');
    });

    it('descarta script com nonce vazio', async () => {
        const navegar = await montarShell(pagina(script('vazio', { nonce: '' })));
        await navegar();

        expect(marcas()).toBe('');
    });

    it('descarta o nonce do documento VIVO quando ele vem no corpo', async () => {
        // O caso que a checagem por header existe para pegar: um XSS que
        // conseguisse ler o nonce da página atual e o repetisse no conteúdo
        // injetado. Como a comparação é contra o nonce que o servidor emitiu
        // para ESTA resposta, repetir o nonce vivo não ajuda o atacante.
        const navegar = await montarShell(pagina(script('roubou-o-vivo', { nonce: NONCE_VIVO })));
        await navegar();

        expect(marcas()).toBe('');
    });

    it('XSS armazenado no meio de conteúdo legítimo: só o legítimo executa', async () => {
        // O cenário do pentest: nome de categoria (compartilhada na família!) com
        // `<script>` dentro, renderizado ao lado do bloco legítimo da tela.
        const navegar = await montarShell(pagina(
            '<h1>Minhas metas</h1>'
            + script('xss')
            + '<p>Categoria: <span>viagem</span></p>'
            + script('legitimo', { nonce: NONCE_DA_RESPOSTA }),
        ));
        await navegar();

        expect(marcas()).toBe('legitimo');
        expect(document.querySelectorAll('#content script')).toHaveLength(1);
        // O conteúdo à volta continua inteiro — a guarda tira o script, não a página.
        expect(document.querySelector('#content h1').textContent).toBe('Minhas metas');
        expect(document.querySelector('#content span').textContent).toBe('viagem');
    });

    it('sem o header X-Csp-Nonce, NADA executa — nem o que tem nonce', async () => {
        // Erra para o lado seguro: sem o nonce da resposta não há com o que
        // comparar, e recriar script às cegas é exatamente o buraco que a guarda
        // fecha. A tela fica inerte; um XSS, não.
        const navegar = await montarShell(
            pagina(script('legitimo', { nonce: NONCE_DA_RESPOSTA })),
            { nonce: null },
        );
        await navegar();

        expect(marcas()).toBe('');
        expect(document.querySelectorAll('#content script')).toHaveLength(0);
    });
});

describe('blocos de dados não são script', () => {
    it('mantém o application/json do dashboard mesmo sem nonce', async () => {
        // `#sm-dashboard-data` é o contrato que alimenta o `dashboard.js`. Não
        // executa nada, então não precisa de nonce — e descartá-lo deixaria o
        // dashboard sem dado nenhum.
        const json = '<script type="application/json" id="sm-dashboard-data">{"hasData":true}</' + 'script>';
        const navegar = await montarShell(pagina(json));
        await navegar();

        const bloco = document.getElementById('sm-dashboard-data');
        expect(bloco).not.toBeNull();
        expect(bloco.textContent).toBe('{"hasData":true}');
        // Não foi recriado: continua sem nonce, porque nunca vai executar.
        expect(bloco.getAttribute('nonce')).toBeNull();
    });

    it('mantém o bloco de dados e descarta o script injetado ao lado dele', async () => {
        const json = '<script type="application/json" id="dados">{"a":1}</' + 'script>';
        const navegar = await montarShell(pagina(json + script('xss')));
        await navegar();

        expect(document.getElementById('dados')).not.toBeNull();
        expect(marcas()).toBe('');
    });
});

describe('o script recriado leva o nonce do documento VIVO', () => {
    it('carimba o nonce vivo, não o da resposta', async () => {
        // É contra o nonce da página atual que o navegador valida o script novo.
        // O nonce da resposta serve só para AUTENTICAR o conteúdo; ele não pode
        // ir para o DOM vivo.
        const navegar = await montarShell(pagina(script('legitimo', { nonce: NONCE_DA_RESPOSTA })));
        await navegar();

        const recriado = document.querySelector('#content script');
        expect(recriado.nonce).toBe(NONCE_VIVO);
        expect(recriado.nonce).not.toBe(NONCE_DA_RESPOSTA);
    });

    it('copia os demais atributos do script original', async () => {
        const html = pagina(
            `<script id="meu-bloco" data-papel="grafico" nonce="${NONCE_DA_RESPOSTA}">`
            + `document.getElementById('marcas').append('legitimo ')</` + 'script>',
        );
        const navegar = await montarShell(html);
        await navegar();

        const recriado = document.querySelector('#content script');
        expect(recriado.id).toBe('meu-bloco');
        expect(recriado.dataset.papel).toBe('grafico');
        expect(marcas()).toBe('legitimo');
    });

    it('sem nonce no documento vivo, o recriado sai sem nonce (e o navegador o bloqueia)', async () => {
        // Shell sem a tag do `@vite` carimbada: `NONCE_VIVO` vira "". A guarda do
        // corpo continua valendo, mas o script recriado não tem como ser aceito —
        // o que degrada a tela, nunca a segurança.
        document.head.innerHTML = '';
        document.body.innerHTML = `
            <a id="ir" data-pjax href="/metas">Metas</a>
            <div id="marcas"></div>
            <div id="content"></div>
        `;
        global.fetch = vi.fn(async () => resposta(pagina(script('legitimo', { nonce: NONCE_DA_RESPOSTA }))));
        vi.resetModules();
        const { initNav } = await import('../../resources/js/sm/nav.js');
        initNav(vi.fn());

        document.getElementById('ir').click();
        await flush();

        expect(document.querySelector('#content script').nonce).toBe('');
    });
});

describe('⚠️ limite do jsdom — o ramo que só o navegador real prova', () => {
    it('documenta que aqui o atributo nonce NÃO é esvaziado no documento vivo', async () => {
        // O motivo de `descartarScriptsSemNonce` rodar sobre o documento INERTE do
        // DOMParser é uma proteção do NAVEGADOR: assim que o script entra num
        // documento vivo, ele esvazia o atributo e guarda o valor num slot interno,
        // exposto só pela propriedade `.nonce` — para um XSS não conseguir raspá-lo
        // do DOM. Medido no Chrome:
        //
        //     inerte → getAttribute('nonce') = "ABC" · .nonce = "ABC"
        //     vivo   → getAttribute('nonce') = ""    · .nonce = "ABC"
        //
        // Consequência: validar DEPOIS do `innerHTML` nunca casaria, e a guarda
        // apagaria justamente os scripts legítimos — tela renderizada e sem
        // comportamento, sem erro nenhum na tela.
        //
        // O jsdom não implementa esse esvaziamento (aqui o atributo continua
        // legível no documento vivo), então nenhum teste consegue provar a
        // diferença: este `expect` é um CANÁRIO. Se um dia ele falhar, é porque o
        // jsdom passou a imitar o navegador — e aí dá para escrever o teste de
        // verdade do "validar depois do innerHTML quebraria".
        const navegar = await montarShell(pagina(script('legitimo', { nonce: NONCE_DA_RESPOSTA })));
        await navegar();

        expect(document.querySelector('#content script').getAttribute('nonce')).not.toBe('');
    });
});

describe('o resto da troca de conteúdo', () => {
    it('substitui o #content, atualiza o título e reinicia os módulos', async () => {
        const navegar = await montarShell(pagina('<p id="novo">metas</p>', { titulo: 'Metas · Stabil Money' }));
        await navegar();

        expect(document.getElementById('antigo')).toBeNull();
        expect(document.getElementById('novo')).not.toBeNull();
        expect(document.title).toBe('Metas · Stabil Money');
        expect(reinitContent).toHaveBeenCalledTimes(1);
    });

    it('pede o HTML como pjax e empurra a nova URL no histórico', async () => {
        const navegar = await montarShell(pagina('<p>metas</p>'));
        await navegar();

        const [url, init] = global.fetch.mock.calls[0];
        expect(url).toBe('/metas');
        expect(init.headers['X-Pjax']).toBe('1');
        expect(init.credentials).toBe('same-origin');
        expect(location.pathname).toBe('/metas');
    });

    it('move o estado ativo do menu para o item navegado', async () => {
        const navegar = await montarShell(pagina('<p>metas</p>'));
        await navegar();

        expect(document.getElementById('ir').classList.contains('active')).toBe(true);
        expect(document.getElementById('ficar').classList.contains('active')).toBe(false);
    });

    it('ignora clique com Ctrl/Meta (abrir em nova aba não é pjax)', async () => {
        await montarShell(pagina('<p>metas</p>'));

        // O módulo sai SEM `preventDefault` (é o navegador que abre a nova aba), e
        // aí o jsdom tentaria navegar de verdade. Este listener corre depois do
        // dele — o `initNav` liga no próprio `<a>`, este no document — e só cala o
        // "Not implemented: navigation" no relatório.
        document.addEventListener('click', (e) => e.preventDefault(), { once: true });
        document.getElementById('ir').dispatchEvent(
            new MouseEvent('click', { bubbles: true, cancelable: true, ctrlKey: true }),
        );
        await flush();

        expect(global.fetch).not.toHaveBeenCalled();
    });
});

// ============================================================================
// Shell completo: o sino, a região de anúncio e a versão do build.
// ============================================================================

/**
 * O sino como o `partials/topbar` o monta: o do celular, o da topbar e a lista, todos com
 * `data-pjax-atualizar`. `itens` chegam como o Blade os imprime (texto do usuário escapado).
 */
function sino({ quantos = 0, vencidas = 0, itens = [] } = {}) {
    const selo = quantos ? `<span class="notif-badge${vencidas ? ' late' : ''}">${quantos}</span>` : '';
    const classe = quantos ? 'icon-btn has-notif' : 'icon-btn';
    const resumo = quantos ? `: ${quantos} contas a pagar${vencidas ? `, ${vencidas} vencida` : ''}` : '';
    const lista = itens.length
        ? itens.map((nome) => `<div class="notif-item"><div class="ni-txt"><strong>${nome}</strong></div></div>`).join('')
        : '<div class="notif-empty"><p>Nada perto de vencer</p></div>';

    return `
        <a class="${classe}" href="/faturas" id="mNotif" data-pjax-atualizar="class aria-label" aria-label="Vencimentos${resumo}">${selo}</a>
        <button class="${classe}" id="notifBtn" type="button" data-pjax-atualizar="class aria-label"
                aria-label="Notificações${resumo}" aria-expanded="false">${selo}</button>
        <div class="notif-pop" id="notifPop" data-pjax-atualizar aria-hidden="true">${lista}</div>
    `;
}

const metaDaVersao = (versao) => (versao === null ? '' : `<meta name="sm-versao" content="${versao}">`);

/** Página inteira como o servidor responde ao pjax: head com a versão, shell e #content. */
function paginaCompleta(dentroDoContent, { titulo = 'Metas · StabilMoney', versao = 'build-1', sinoDaPagina = sino() } = {}) {
    return `<!doctype html><html><head><title>${titulo}</title>${metaDaVersao(versao)}</head>`
        + `<body><header>${sinoDaPagina}</header><div id="content">${dentroDoContent}</div></body></html>`;
}

/**
 * Monta o shell vivo COMPLETO (sino, região de anúncio `#sm-anuncio`, meta da versão) e
 * devolve o disparador da navegação. `respostas` = o que o servidor devolve a cada fetch,
 * em ordem (a última se repete).
 */
async function montarShellCompleto({ versao = 'build-1', sinoVivo = sino(), respostas = [paginaCompleta('<h2>Metas</h2>')] } = {}) {
    document.head.innerHTML = `<script nonce="${NONCE_VIVO}"></script>${metaDaVersao(versao)}`;
    document.title = 'Visão geral · StabilMoney';
    document.body.innerHTML = `
        <a id="ir" class="nav-item" data-pjax href="/metas">Metas</a>
        <a id="ficar" class="nav-item active" data-pjax href="/">Visão geral</a>
        <header>${sinoVivo}</header>
        <div id="marcas"></div>
        <main id="content"><h2 id="tituloAntigo">Visão geral</h2><button id="dentro" type="button">ação</button></main>
        <div id="sm-anuncio" role="status" aria-live="polite" aria-atomic="true"></div>
    `;

    let chamada = 0;
    global.fetch = vi.fn(async () => resposta(respostas[Math.min(chamada++, respostas.length - 1)]));
    reinitContent = vi.fn();

    vi.resetModules();
    const { initNav } = await import('../../resources/js/sm/nav.js');
    initNav(reinitContent);

    return async () => {
        document.getElementById('ir').click();
        await flush();
    };
}

/**
 * Pedidos de navegação COMPLETA. O jsdom não navega de verdade: ele recusa e avisa no
 * console virtual ("Not implemented: navigation…") — é esse aviso que prova que o módulo
 * mandou o navegador carregar a página, em vez de trocar o #content.
 */
function ouvirNavegacaoCompleta() {
    const pedidos = [];
    const ouvinte = (erro) => {
        if (erro.type === 'not-implemented' && /navigation/i.test(erro.message)) pedidos.push(erro.message);
    };
    globalThis.jsdom.virtualConsole.on('jsdomError', ouvinte);
    return { pedidos, parar: () => globalThis.jsdom.virtualConsole.off('jsdomError', ouvinte) };
}

const seloDe = (id) => document.querySelector(`#${id} .notif-badge`);

describe('o sino do shell acompanha a navegação (P-4)', () => {
    it('selo, classe, nome acessível e lista vêm da página nova', async () => {
        const navegar = await montarShellCompleto({
            respostas: [paginaCompleta('<h2>Metas</h2>', {
                sinoDaPagina: sino({ quantos: 3, vencidas: 1, itens: ['Condomínio', 'Luz', 'Nubank'] }),
            })],
        });
        expect(seloDe('notifBtn')).toBeNull();

        await navegar();

        for (const id of ['notifBtn', 'mNotif']) {
            expect(seloDe(id).textContent).toBe('3');
            expect(seloDe(id).classList.contains('late')).toBe(true);
            expect(document.getElementById(id).classList.contains('has-notif')).toBe(true);
        }
        expect(document.getElementById('notifBtn').getAttribute('aria-label')).toBe('Notificações: 3 contas a pagar, 1 vencida');
        expect(document.querySelectorAll('#notifPop .notif-item')).toHaveLength(3);
        expect(document.querySelector('#notifPop').textContent).toContain('Condomínio');
    });

    it('o sino esvazia quando a última conta sai da lista', async () => {
        const navegar = await montarShellCompleto({
            sinoVivo: sino({ quantos: 1, itens: ['Aluguel'] }),
            respostas: [paginaCompleta('<h2>Metas</h2>', { sinoDaPagina: sino() })],
        });

        await navegar();

        expect(seloDe('notifBtn')).toBeNull();
        expect(seloDe('mNotif')).toBeNull();
        expect(document.getElementById('notifBtn').classList.contains('has-notif')).toBe(false);
        expect(document.querySelector('#notifPop .notif-empty')).not.toBeNull();
    });

    it('os elementos do shell são os MESMOS: listeners e estado do JS continuam', async () => {
        // O popover do sino é ligado UMA vez pelo shell.js (no botão e na lista). Trocar o
        // elemento em vez dos filhos deixaria o sino mudo depois da primeira navegação.
        const navegar = await montarShellCompleto({
            respostas: [paginaCompleta('<h2>Metas</h2>', { sinoDaPagina: sino({ quantos: 2 }) })],
        });
        const botao = document.getElementById('notifBtn');
        const lista = document.getElementById('notifPop');
        const clique = vi.fn();
        botao.addEventListener('click', clique);
        botao.setAttribute('aria-expanded', 'true');
        lista.classList.add('open');

        await navegar();

        expect(document.getElementById('notifBtn')).toBe(botao);
        expect(document.getElementById('notifPop')).toBe(lista);
        // Não listados em `data-pjax-atualizar`: são do JS, a página nova não os pisa.
        expect(botao.getAttribute('aria-expanded')).toBe('true');
        expect(lista.classList.contains('open')).toBe(true);
        botao.click();
        expect(clique).toHaveBeenCalledTimes(1);
    });

    it('o nome da conta (dado do usuário) chega como TEXTO', async () => {
        const navegar = await montarShellCompleto({
            respostas: [paginaCompleta('<h2>Metas</h2>', {
                // Como o Blade imprime um nome de conta fixa malicioso: escapado.
                sinoDaPagina: sino({ quantos: 1, itens: ['&lt;img src=x onerror=alert(1)&gt;'] }),
            })],
        });

        await navegar();

        expect(document.querySelector('#notifPop strong').textContent).toBe('<img src=x onerror=alert(1)>');
        expect(document.querySelector('#notifPop img')).toBeNull();
    });

    it('os nós são CLONADOS do documento inerte, nunca reinterpretados como HTML', async () => {
        // HTML que muda de sentido ao ser serializado e lido de novo (mutation XSS): no
        // documento inerte o <noscript> é marcação e o "</noscript>" é só texto de um
        // atributo; lido de novo num documento com script ligado, ele fecha o <noscript> e
        // o <img> vira elemento. Cópia por `innerHTML` cairia nessa; clone de nó, não.
        const armadilha = '<noscript><p title="</noscript><img id=pego src=x>"></p></noscript>';
        const navegar = await montarShellCompleto({
            respostas: [paginaCompleta('<h2>Metas</h2>', { sinoDaPagina: sino({ quantos: 1, itens: [armadilha] }) })],
        });

        await navegar();

        expect(document.getElementById('pego')).toBeNull();
    });

    it('script que viesse no sino não entra — nem com o nonce certo', async () => {
        const navegar = await montarShellCompleto({
            respostas: [paginaCompleta('<h2>Metas</h2>', {
                sinoDaPagina: sino({ quantos: 1, itens: [script('no-sino', { nonce: NONCE_DA_RESPOSTA })] }),
            })],
        });

        await navegar();

        expect(document.querySelectorAll('#notifPop script')).toHaveLength(0);
        expect(marcas()).toBe('');
    });

    it('atributo fora da lista não é copiado, e manipulador inline nunca, nem listado', async () => {
        document.head.innerHTML = `<script nonce="${NONCE_VIVO}"></script>`;
        document.body.innerHTML = `
            <a id="ir" data-pjax href="/metas">Metas</a>
            <p id="dado" data-pjax-atualizar="title onclick">antigo</p>
            <main id="content"></main>
        `;
        global.fetch = vi.fn(async () => resposta(
            '<html><head></head><body>'
            + '<p id="dado" data-pjax-atualizar title="novo" onclick="alert(1)" lang="en">novo</p>'
            + '<main id="content"><h2>Metas</h2></main></body></html>',
        ));
        vi.resetModules();
        const { initNav } = await import('../../resources/js/sm/nav.js');
        initNav(vi.fn());

        document.getElementById('ir').click();
        await flush();

        const dado = document.getElementById('dado');
        expect(dado.textContent).toBe('novo');
        expect(dado.getAttribute('title')).toBe('novo');
        expect(dado.hasAttribute('onclick')).toBe(false);
        expect(dado.hasAttribute('lang')).toBe(false);
    });

    it('elemento sem par na página nova fica como está', async () => {
        const navegar = await montarShellCompleto({
            sinoVivo: sino({ quantos: 2 }),
            respostas: ['<html><head><meta name="sm-versao" content="build-1"></head>'
                + '<body><div id="content"><h2>Metas</h2></div></body></html>'],
        });

        await navegar();

        expect(seloDe('notifBtn').textContent).toBe('2');
    });

    it('o smPjaxReload (depois de salvar no modal) também traz o sino novo', async () => {
        await montarShellCompleto({
            respostas: [paginaCompleta('<h2>Visão geral</h2>', { sinoDaPagina: sino({ quantos: 1, itens: ['Luz'] }) })],
        });

        window.smPjaxReload();
        await flush();

        expect(seloDe('notifBtn').textContent).toBe('1');
    });
});

describe('build novo no ar: a navegação vira COMPLETA (P-6)', () => {
    let navegacao;

    beforeEach(() => {
        navegacao = ouvirNavegacaoCompleta();
    });

    afterEach(() => {
        navegacao.parar();
    });

    it('a página buscada é de outro build: não troca por pjax, pede o carregamento completo', async () => {
        // O HTML novo foi feito para o CSS/JS novos, e esta aba roda os velhos.
        const navegar = await montarShellCompleto({
            versao: 'build-1',
            respostas: [paginaCompleta('<h2 id="novo">Metas</h2>', { versao: 'build-2', sinoDaPagina: sino({ quantos: 5 }) })],
        });

        await navegar();

        expect(navegacao.pedidos).toHaveLength(1);
        expect(document.getElementById('tituloAntigo')).not.toBeNull();
        expect(document.getElementById('novo')).toBeNull();
        expect(seloDe('notifBtn')).toBeNull();
        expect(reinitContent).not.toHaveBeenCalled();
        expect(location.pathname).toBe('/');
    });

    it('mesmo build: pjax normal, sem carregamento completo', async () => {
        const navegar = await montarShellCompleto({
            respostas: [paginaCompleta('<h2 id="novo">Metas</h2>', { versao: 'build-1' })],
        });

        await navegar();

        expect(navegacao.pedidos).toHaveLength(0);
        expect(document.getElementById('novo')).not.toBeNull();
    });

    it('depois do aviso de versão nova (sm:versao-nova), a navegação é completa sem nem buscar por pjax', async () => {
        const navegar = await montarShellCompleto();

        window.dispatchEvent(new CustomEvent('sm:versao-nova'));
        await navegar();

        expect(global.fetch).not.toHaveBeenCalled();
        expect(navegacao.pedidos).toHaveLength(1);
        expect(document.getElementById('tituloAntigo')).not.toBeNull();
    });

    it('o smPjaxReload também vira carregamento completo depois do aviso', async () => {
        await montarShellCompleto();

        window.dispatchEvent(new CustomEvent('sm:versao-nova'));
        window.smPjaxReload();
        await flush();

        expect(global.fetch).not.toHaveBeenCalled();
        expect(navegacao.pedidos).toHaveLength(1);
    });
});

describe('leitor de tela: a troca se anuncia e o foco vai para a página nova', () => {
    const anuncio = () => document.getElementById('sm-anuncio').textContent;

    it('anuncia o título da página nova na região viva', async () => {
        const navegar = await montarShellCompleto({
            respostas: [paginaCompleta('<h2>Metas</h2>', { titulo: 'Metas · StabilMoney' })],
        });

        await navegar();

        expect(anuncio()).toBe('Metas · StabilMoney');
    });

    it('navegar de novo para a mesma tela anuncia de novo (nó novo, não só texto igual)', async () => {
        const navegar = await montarShellCompleto();
        await navegar();
        const primeiro = document.querySelector('#sm-anuncio p');

        await navegar();

        expect(document.querySelector('#sm-anuncio p')).not.toBe(primeiro);
        expect(anuncio()).toBe('Metas · StabilMoney');
    });

    it('o foco sai do link do menu e vai para o título da página nova', async () => {
        const navegar = await montarShellCompleto({
            respostas: [paginaCompleta('<h2 id="titulo">Metas</h2><button>Nova meta</button>')],
        });
        document.getElementById('ir').focus();

        await navegar();

        const titulo = document.getElementById('titulo');
        expect(document.activeElement).toBe(titulo);
        // Focável por script, fora da ordem do Tab.
        expect(titulo.getAttribute('tabindex')).toBe('-1');
    });

    it('título que o navegador não foca (escondido no desktop): vai para o seguinte', async () => {
        // O h1 do dashboard é `display: none` no desktop, e o navegador não foca elemento
        // escondido. O jsdom não calcula estilo, então a recusa é simulada.
        const foco = HTMLElement.prototype.focus;
        vi.spyOn(HTMLHeadingElement.prototype, 'focus').mockImplementation(function (opcoes) {
            if (this.id !== 'oculto') foco.call(this, opcoes);
        });
        const navegar = await montarShellCompleto({
            respostas: [paginaCompleta('<h1 id="oculto">Bem-vindo</h1><h2 id="visivel">Visão geral</h2>')],
        });

        await navegar();

        expect(document.activeElement).toBe(document.getElementById('visivel'));
    });

    it('título dentro de aria-hidden (o card-fantasma) não recebe o foco', async () => {
        const navegar = await montarShellCompleto({
            respostas: [paginaCompleta('<div aria-hidden="true"><h2 id="fantasma">Exemplo</h2></div><h2 id="real">Dependentes</h2>')],
        });

        await navegar();

        expect(document.activeElement).toBe(document.getElementById('real'));
    });

    it('sem título nenhum, o foco vai para o próprio #content', async () => {
        const navegar = await montarShellCompleto({ respostas: [paginaCompleta('<p>só texto</p>')] });

        await navegar();

        expect(document.activeElement).toBe(document.getElementById('content'));
    });

    it('smPjaxReload não se anuncia nem tira o foco de quem está no shell', async () => {
        // É a MESMA tela montada de novo depois de salvar no modal: a pessoa não saiu dela.
        await montarShellCompleto({ respostas: [paginaCompleta('<h2>Visão geral</h2>', { titulo: 'Visão geral · StabilMoney' })] });
        document.getElementById('notifBtn').focus();

        window.smPjaxReload();
        await flush();

        expect(anuncio()).toBe('');
        expect(document.activeElement).toBe(document.getElementById('notifBtn'));
    });

    it('smPjaxReload com o foco DENTRO do conteúdo trocado: o foco vai ao título, não cai no <body>', async () => {
        await montarShellCompleto({ respostas: [paginaCompleta('<h2 id="titulo">Visão geral</h2>')] });
        document.getElementById('dentro').focus();

        window.smPjaxReload();
        await flush();

        expect(document.activeElement).toBe(document.getElementById('titulo'));
    });
});
