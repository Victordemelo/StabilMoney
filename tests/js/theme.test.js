import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Tema claro/escuro (`resources/js/sm/theme.js` + o anti-flash inline dos layouts).
 *
 * O defeito (observação da auditoria de PWA de 06/09/2026): sem preferência salva, o app
 * abria SEMPRE claro — num celular no modo escuro, um clarão a cada abertura. A regra agora:
 * a escolha explícita (botão de tema, `sm-theme`) manda; sem ela, vale o tema do sistema
 * (`prefers-color-scheme`), inclusive quando ele muda com a página aberta.
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
        // [salvo, sistema escuro?, esperado]
        [null, true, 'dark'],
        [null, false, 'light'],
        ['dark', false, 'dark'],
        ['light', true, 'light'],
        ['dark', true, 'dark'],
        ['light', false, 'light'],
        // Lixo no storage (versão antiga, extensão) não é escolha: vale o sistema.
        ['azul', true, 'dark'],
        ['', false, 'light'],
    ])('salvo=%s, sistema escuro=%s → %s', async (salvo, escuro, esperado) => {
        const { resolverTema } = await import('../../resources/js/sm/theme.js');

        expect(resolverTema(salvo, escuro)).toBe(esperado);
    });
});

describe('initTheme: página que segue o tema (layouts app e legal)', () => {
    it('sem escolha salva e sistema escuro: abre ESCURO, com as bordas do sistema junto', async () => {
        sistemaEm(true);
        montarPagina();

        await iniciarTema();

        expect(tema()).toBe('dark');
        expect(corDaBarra()).toBe('#07140E');
        expect(barraDoIphone()).toBe('black');
    });

    it('sem escolha salva e sistema claro: abre claro', async () => {
        sistemaEm(false);
        montarPagina();

        await iniciarTema();

        expect(tema()).toBe('light');
        expect(corDaBarra()).toBe('#EFF4F1');
    });

    it('a escolha salva vence o sistema', async () => {
        localStorage.setItem('sm-theme', 'light');
        sistemaEm(true);
        montarPagina();

        await iniciarTema();

        expect(tema()).toBe('light');
    });

    it('o sistema muda com a página aberta: sem escolha salva, o app acompanha', async () => {
        const sistema = sistemaEm(false);
        montarPagina();
        await iniciarTema();

        sistema.mudarPara(true);
        expect(tema()).toBe('dark');
        expect(corDaBarra()).toBe('#07140E');

        sistema.mudarPara(false);
        expect(tema()).toBe('light');
    });

    it('com escolha salva, a mudança do sistema não mexe no tema', async () => {
        localStorage.setItem('sm-theme', 'light');
        const sistema = sistemaEm(false);
        montarPagina();
        await iniciarTema();

        sistema.mudarPara(true);

        expect(tema()).toBe('light');
    });

    it('o botão grava a escolha explícita — e daí em diante o sistema não manda mais', async () => {
        const sistema = sistemaEm(true);
        montarPagina();
        await iniciarTema();
        expect(tema()).toBe('dark');

        document.getElementById('themeBtn').click();

        expect(tema()).toBe('light');
        expect(localStorage.getItem('sm-theme')).toBe('light');

        sistema.mudarPara(false);
        sistema.mudarPara(true);
        expect(tema()).toBe('light');
    });

    it('o botão do celular grava do mesmo jeito', async () => {
        sistemaEm(false);
        montarPagina();
        await iniciarTema();

        document.getElementById('mTheme').click();

        expect(tema()).toBe('dark');
        expect(localStorage.getItem('sm-theme')).toBe('dark');
    });

    it('seguir o sistema NÃO grava nada: a escolha salva é só a do botão', async () => {
        const sistema = sistemaEm(true);
        montarPagina();
        await iniciarTema();
        sistema.mudarPara(false);

        expect(localStorage.getItem('sm-theme')).toBeNull();
    });

    it('navegador sem matchMedia: abre claro, como sempre foi, sem quebrar', async () => {
        montarPagina();

        await iniciarTema();

        expect(tema()).toBe('light');
    });

    it('storage bloqueado: segue o sistema e não quebra', async () => {
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('bloqueado'); });
        sistemaEm(true);
        montarPagina();

        await iniciarTema();

        expect(tema()).toBe('dark');
    });

    it('Safari antigo (só addListener) também acompanha a mudança', async () => {
        const ouvintes = [];
        const consulta = { matches: false, addListener: (f) => ouvintes.push(f) };
        window.matchMedia = vi.fn(() => consulta);
        montarPagina();
        await iniciarTema();

        ouvintes.forEach((f) => f({ matches: true }));

        expect(tema()).toBe('dark');
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

            const esperado = resolverTema(storage === 'ok' ? salvo : null, sistema === 'escuro');
            expect(tema()).toBe(esperado);
            expect(corDaBarra()).toBe(esperado === 'dark' ? '#07140E' : '#EFF4F1');
            expect(barraDoIphone()).toBe(esperado === 'dark' ? 'black' : 'default');
        });
    });
});
