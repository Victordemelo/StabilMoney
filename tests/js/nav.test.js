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
