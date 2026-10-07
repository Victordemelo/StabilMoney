import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Tema claro/escuro (`resources/js/sm/theme.js` + o anti-flash inline dos layouts).
 *
 * A regra (out/2026, decisão do Victor: "a primeira vez é modo claro"): a escolha explícita
 * (botão de tema, `sm-theme`) manda; sem ela, vale o CLARO — também com o sistema no modo
 * escuro, e sem acompanhar a mudança do sistema. (De set a out/2026 valia o do sistema.)
 *
 * A regra mora em DOIS lugares, de propósito: no `resolverTema` do módulo e no script inline
 * do <head> dos layouts app e legal, que precisa rodar antes do CSS pintar (o bundle chega
 * tarde demais). Duas cópias só ficam iguais se alguém conferir — por isso a última parte
 * deste arquivo EXECUTA os scripts inline de verdade, lidos das views, e compara com o módulo.
 */

const LAYOUTS = resolve(__dirname, '../../resources/views/layouts');
const CONSULTA = '(prefers-color-scheme: dark)';

/** `window.matchMedia` controlável: o "sistema" do teste, que pode mudar de tema. */
function sistemaEm(escuro) {
    const ouvintes = new Set();
    const consulta = {
        media: CONSULTA,
        matches: escuro,
        addEventListener: (tipo, f) => { if (tipo === 'change') ouvintes.add(f); },
        removeEventListener: (tipo, f) => ouvintes.delete(f),
        /** O celular entrando (ou saindo) do modo escuro. */
        mudarPara(novo) {
            consulta.matches = novo;
            ouvintes.forEach((f) => f({ matches: novo, media: CONSULTA }));
        },
    };
    window.matchMedia = vi.fn((q) => (q === CONSULTA ? consulta : { matches: false, addEventListener() {} }));
    return consulta;
}

/**
 * O <head> e os botões de tema de uma página. `segueOTema` = a meta marcada com
 * `data-sm-theme`, que só os layouts app e legal têm (auth é sempre claro; o painel,
 * sempre escuro).
 */
function montarPagina({ segueOTema = true, temaDoServidor = 'light' } = {}) {
    if (temaDoServidor) document.documentElement.setAttribute('data-theme', temaDoServidor);
    else document.documentElement.removeAttribute('data-theme');

    document.head.innerHTML = [
        segueOTema
            ? '<meta name="theme-color" content="#EFF4F1" data-sm-theme data-light="#EFF4F1" data-dark="#07140E">'
            : '<meta name="theme-color" content="#0C3D2B">',
        '<meta name="apple-mobile-web-app-status-bar-style" content="default">',
    ].join('');
    document.body.innerHTML = `
        <button id="themeBtn" type="button"><svg id="themeIcon"></svg></button>
        <button id="mTheme" type="button"><svg></svg></button>
    `;
}

const tema = () => document.documentElement.getAttribute('data-theme');
const corDaBarra = () => document.querySelector('meta[name="theme-color"]').getAttribute('content');
const barraDoIphone = () =>
    document.querySelector('meta[name="apple-mobile-web-app-status-bar-style"]').getAttribute('content');

async function iniciarTema() {
    vi.resetModules();
    const modulo = await import('../../resources/js/sm/theme.js');
    modulo.initTheme();
    return modulo;
}

beforeEach(() => {
    localStorage.clear();
});

afterEach(() => {
    vi.restoreAllMocks();
    delete window.matchMedia;
    localStorage.clear();
});

describe('resolverTema: a regra', () => {
    it.each([
        // [salvo, esperado]
        [null, 'light'],
        ['dark', 'dark'],
        ['light', 'light'],
        // Lixo no storage (versão antiga, extensão) não é escolha: vale o claro.
        ['azul', 'light'],
        ['', 'light'],
    ])('salvo=%s → %s', async (salvo, esperado) => {
        const { resolverTema } = await import('../../resources/js/sm/theme.js');

        expect(resolverTema(salvo)).toBe(esperado);
    });
});

describe('initTheme: página que segue o tema (layouts app e legal)', () => {
    it('primeira entrada, com o sistema no modo ESCURO: abre CLARO, com as bordas do sistema junto', async () => {
        sistemaEm(true);
        montarPagina({ temaDoServidor: null });

        await iniciarTema();

        expect(tema()).toBe('light');
        expect(corDaBarra()).toBe('#EFF4F1');
        expect(barraDoIphone()).toBe('default');
    });

    it('a escolha salva vale: quem escolheu o escuro abre escuro', async () => {
        localStorage.setItem('sm-theme', 'dark');
        sistemaEm(false);
        montarPagina();

        await iniciarTema();

        expect(tema()).toBe('dark');
        expect(corDaBarra()).toBe('#07140E');
        expect(barraDoIphone()).toBe('black');
    });

    it('o sistema mudar com a página aberta não troca o tema', async () => {
        const sistema = sistemaEm(false);
        montarPagina();
        await iniciarTema();

        sistema.mudarPara(true);

        expect(tema()).toBe('light');
    });

    it('o botão grava a escolha explícita, e ela vale na próxima visita', async () => {
        sistemaEm(true);
        montarPagina();
        await iniciarTema();
        expect(tema()).toBe('light');

        document.getElementById('themeBtn').click();

        expect(tema()).toBe('dark');
        expect(localStorage.getItem('sm-theme')).toBe('dark');

        montarPagina({ temaDoServidor: null });
        await iniciarTema();
        expect(tema()).toBe('dark');
    });

    it('o botão do celular grava do mesmo jeito', async () => {
        sistemaEm(false);
        montarPagina();
        await iniciarTema();

        document.getElementById('mTheme').click();

        expect(tema()).toBe('dark');
        expect(localStorage.getItem('sm-theme')).toBe('dark');
    });

    it('abrir sem escolher NÃO grava nada: a escolha salva é só a do botão', async () => {
        sistemaEm(true);
        montarPagina();
        await iniciarTema();

        expect(localStorage.getItem('sm-theme')).toBeNull();
    });

    it('navegador sem matchMedia: abre claro, sem quebrar', async () => {
        montarPagina();

        await iniciarTema();

        expect(tema()).toBe('light');
    });

    it('storage bloqueado: abre claro e não quebra', async () => {
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('bloqueado'); });
        sistemaEm(true);
        montarPagina();

        await iniciarTema();

        expect(tema()).toBe('light');
    });
});

describe('initTheme: páginas de tema FIXO ficam de fora', () => {
    it('telas de auth (sempre claras) não viram escuras com o sistema escuro', async () => {
        const sistema = sistemaEm(true);
        montarPagina({ segueOTema: false, temaDoServidor: null });

        await iniciarTema();
        sistema.mudarPara(true);

        expect(tema()).toBeNull();
        expect(corDaBarra()).toBe('#0C3D2B');
    });

    it('o painel (sempre escuro) não vira claro com o sistema claro', async () => {
        const sistema = sistemaEm(false);
        montarPagina({ segueOTema: false, temaDoServidor: 'dark' });

        await iniciarTema();
        sistema.mudarPara(false);

        expect(tema()).toBe('dark');
    });
});

describe('o anti-flash inline dos layouts aplica a MESMA regra', () => {
    /**
     * O script inline de tema da view, exatamente como está nela. Precisa ser JS puro (sem
     * Blade dentro) para o que roda aqui ser o que o navegador recebe — e precisa do nonce,
     * senão a CSP o bloqueia e a página pisca no tema errado.
     */
    function antiFlash(layout) {
        const blade = readFileSync(resolve(LAYOUTS, layout), 'utf8');
        const blocos = [...blade.matchAll(/<script nonce="\{\{ Vite::cspNonce\(\) \}\}">([\s\S]*?)<\/script>/g)]
            .map((m) => m[1])
            .filter((codigo) => codigo.includes('sm-theme'));

        if (blocos.length !== 1) {
            throw new Error(`${layout}: esperado UM script de tema com o nonce da CSP, achados ${blocos.length}.`);
        }
        if (/\{\{|\{!!|@\w/.test(blocos[0])) {
            throw new Error(`${layout}: o script de tema tem Blade dentro — o teste não executaria o que é servido.`);
        }

        return blocos[0];
    }

    const cenarios = [];
    for (const salvo of [null, 'dark', 'light', 'azul']) {
        for (const sistema of ['escuro', 'claro', 'sem matchMedia']) {
            for (const storage of ['ok', 'bloqueado']) {
                cenarios.push([salvo, sistema, storage]);
            }
        }
    }

    describe.each(['app.blade.php', 'legal.blade.php'])('%s', (layout) => {
        it.each(cenarios)('salvo=%s, sistema %s, storage %s', async (salvo, sistema, storage) => {
            const { resolverTema } = await import('../../resources/js/sm/theme.js');
            if (salvo !== null) localStorage.setItem('sm-theme', salvo);
            if (storage === 'bloqueado') {
                vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('bloqueado'); });
            }
            if (sistema !== 'sem matchMedia') sistemaEm(sistema === 'escuro');
            montarPagina();

            // Código da própria view do repositório (nada vindo de fora), executado como
            // o navegador executaria o <script> do <head>.
            new Function(antiFlash(layout))();

            const esperado = resolverTema(storage === 'ok' ? salvo : null);
            expect(tema()).toBe(esperado);
            expect(corDaBarra()).toBe(esperado === 'dark' ? '#07140E' : '#EFF4F1');
            expect(barraDoIphone()).toBe(esperado === 'dark' ? 'black' : 'default');
        });
    });
});
