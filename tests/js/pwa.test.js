import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { flush } from './helpers/flush.js';

/**
 * A página percebe a versão nova do app (`resources/js/sm/pwa.js`) — P-6 da auditoria de
 * PWA de 06/09/2026.
 *
 * O service worker agora leva a versão do build no próprio código (tests/js/service-worker
 * .test.js cobre aquela ponta): deploy novo ⇒ bytes novos ⇒ o navegador instala o SW novo,
 * que assume as abas abertas. Faltava a página: (1) pedir ao navegador que confira o /sw.js
 * — sozinho ele só confere numa navegação completa, e com o pjax quase não há uma —, e
 * (2) quando o SW novo assume, descobrir se a versão DELA ficou para trás e avisar.
 *
 * O `navigator.serviceWorker` do jsdom não existe; entra um dublê com o que o módulo usa.
 */

/** Um service worker ativo, do ponto de vista da página. */
function trabalhador(nome) {
    return { nome, postMessage: vi.fn() };
}

/**
 * `navigator.serviceWorker` falso. `assumir` = um SW (novo) passa a controlar a página;
 * `responder` = o SW manda uma mensagem para ela.
 */
function instalarServiceWorker({ controlador = trabalhador('versao-a'), update } = {}) {
    const eventos = new EventTarget();
    const registro = { update: vi.fn(update || (async () => {})) };
    const container = {
        controller: controlador,
        register: vi.fn(async () => registro),
        addEventListener: (...a) => eventos.addEventListener(...a),
        removeEventListener: (...a) => eventos.removeEventListener(...a),
        startMessages: vi.fn(),
        assumir(novo) {
            container.controller = novo;
            eventos.dispatchEvent(new Event('controllerchange'));
        },
        responder(dados) {
            eventos.dispatchEvent(new MessageEvent('message', { data: dados }));
        },
    };
    Object.defineProperty(navigator, 'serviceWorker', { value: container, configurable: true });

    return { container, registro };
}

/** A página do app, com a versão do build com que foi montada (layouts/app). */
function pagina(versao = 'build-1') {
    document.head.innerHTML = versao === null ? '' : `<meta name="sm-versao" content="${versao}">`;
    document.body.innerHTML = '<main id="content"></main>';
}

async function iniciar() {
    vi.resetModules();
    const modulo = await import('../../resources/js/sm/pwa.js');
    modulo.initPwa();
    // O registro espera o `load` da página, se ele ainda não aconteceu.
    if (document.readyState !== 'complete') window.dispatchEvent(new Event('load'));
    await flush();
    return modulo;
}

const aviso = () => document.getElementById('sm-versao-nova');
const proximoQuadro = () => new Promise((pronto) => requestAnimationFrame(() => pronto()));

let eventosDeVersao;
const contarEvento = () => { eventosDeVersao++; };

beforeEach(() => {
    eventosDeVersao = 0;
    window.addEventListener('sm:versao-nova', contarEvento);
});

afterEach(() => {
    window.removeEventListener('sm:versao-nova', contarEvento);
    delete navigator.serviceWorker;
    vi.useRealTimers();
    vi.restoreAllMocks();
});

describe('registro', () => {
    it('registra o /sw.js', async () => {
        const { container } = instalarServiceWorker();
        pagina();

        await iniciar();

        expect(container.register).toHaveBeenCalledWith('/sw.js');
    });

    it('navegador sem service worker: não faz nada e não quebra', async () => {
        pagina();

        await expect(iniciar()).resolves.toBeDefined();
        expect(aviso()).toBeNull();
    });
});

describe('um SW novo assume a aba: a página confere se ficou para trás', () => {
    it('pergunta a versão ao SW que acabou de assumir', async () => {
        const { container } = instalarServiceWorker({ controlador: trabalhador('versao-a') });
        pagina();
        await iniciar();

        const novo = trabalhador('versao-b');
        container.assumir(novo);

        expect(novo.postMessage).toHaveBeenCalledWith({ tipo: 'sm-versao?' });
    });

    it('primeira instalação (a aba não tinha SW): não há o que perguntar', async () => {
        // O SW acabou de assumir uma página que veio inteira da rede, com os assets certos.
        const { container } = instalarServiceWorker({ controlador: null });
        pagina();
        await iniciar();

        const primeiro = trabalhador('versao-a');
        container.assumir(primeiro);

        expect(primeiro.postMessage).not.toHaveBeenCalled();
    });

    it('versão do SW diferente da página: avisa a pessoa e o pjax passa a navegar completo', async () => {
        const { container } = instalarServiceWorker();
        pagina('build-1');
        await iniciar();

        container.responder({ tipo: 'sm-versao', versao: 'build-2' });

        expect(eventosDeVersao).toBe(1);
        expect(aviso()).not.toBeNull();
        const botoes = [...aviso().querySelectorAll('button')].map((b) => b.textContent);
        expect(botoes).toEqual(['Agora não', 'Atualizar']);
    });

    it('MESMA versão (mudou só o código do SW, ou a página acabou de recarregar): nada acontece', async () => {
        // É o alarme falso que a comparação evita: logo depois de um carregamento completo
        // que JÁ trouxe o CSS/JS novos, o SW novo assume a aba — e as versões batem.
        const { container } = instalarServiceWorker();
        pagina('build-2');
        await iniciar();

        container.responder({ tipo: 'sm-versao', versao: 'build-2' });

        expect(eventosDeVersao).toBe(0);
        expect(aviso()).toBeNull();
    });

    it('página sem a meta de versão (login, termos): nada acontece', async () => {
        const { container } = instalarServiceWorker();
        pagina(null);
        await iniciar();

        container.responder({ tipo: 'sm-versao', versao: 'build-2' });

        expect(aviso()).toBeNull();
        expect(eventosDeVersao).toBe(0);
    });

    it('mensagem que não é de versão é ignorada', async () => {
        const { container } = instalarServiceWorker();
        pagina();
        await iniciar();

        container.responder({ tipo: 'outra-coisa', versao: 'build-9' });
        container.responder(null);

        expect(aviso()).toBeNull();
    });

    it('o aviso aparece uma vez só', async () => {
        const { container } = instalarServiceWorker();
        pagina('build-1');
        await iniciar();

        container.responder({ tipo: 'sm-versao', versao: 'build-2' });
        container.responder({ tipo: 'sm-versao', versao: 'build-3' });

        expect(document.querySelectorAll('#sm-versao-nova')).toHaveLength(1);
        expect(eventosDeVersao).toBe(1);
    });

    it('o texto chega DEPOIS de a região viva existir — é o que faz o leitor de tela anunciar', async () => {
        const { container } = instalarServiceWorker();
        pagina('build-1');
        await iniciar();

        container.responder({ tipo: 'sm-versao', versao: 'build-2' });
        const regiao = aviso().querySelector('[role="status"]');
        expect(regiao.textContent).toBe('');

        await proximoQuadro();

        expect(regiao.textContent).toContain('versão nova');
    });

    it('"Agora não" fecha o aviso — nunca recarrega sozinho', async () => {
        const { container } = instalarServiceWorker();
        pagina('build-1');
        await iniciar();
        container.responder({ tipo: 'sm-versao', versao: 'build-2' });

        [...aviso().querySelectorAll('button')].find((b) => b.textContent === 'Agora não').click();

        expect(aviso()).toBeNull();
    });
});

describe('pedir ao navegador que confira o /sw.js', () => {
    // Sozinho, o navegador só confere o script numa navegação completa. Com o pjax, um app
    // instalado ficava dias aberto sem nunca descobrir o deploy.
    const MEIA_HORA = 30 * 60 * 1000;

    function aba(estado) {
        Object.defineProperty(document, 'visibilityState', { value: estado, configurable: true });
        document.dispatchEvent(new Event('visibilitychange'));
    }

    afterEach(() => {
        delete document.visibilityState;
    });

    it('quando o app volta para a frente, confere — mas no máximo a cada 30 minutos', async () => {
        vi.useFakeTimers();
        const { registro } = instalarServiceWorker();
        pagina();
        await iniciar();

        vi.advanceTimersByTime(10 * 60 * 1000);
        aba('hidden');
        aba('visible');
        expect(registro.update).not.toHaveBeenCalled();

        vi.setSystemTime(Date.now() + MEIA_HORA);
        aba('hidden');
        aba('visible');
        await flush();
        expect(registro.update).toHaveBeenCalledTimes(1);

        aba('hidden');
        aba('visible');
        await flush();
        expect(registro.update).toHaveBeenCalledTimes(1);
    });

    it('com o app aberto na frente, confere a cada 30 minutos', async () => {
        vi.useFakeTimers();
        const { registro } = instalarServiceWorker();
        pagina();
        await iniciar();
        aba('visible');

        vi.advanceTimersByTime(MEIA_HORA);
        await flush();
        expect(registro.update).toHaveBeenCalledTimes(1);

        vi.advanceTimersByTime(MEIA_HORA);
        await flush();
        expect(registro.update).toHaveBeenCalledTimes(2);
    });

    it('app em segundo plano não confere', async () => {
        vi.useFakeTimers();
        const { registro } = instalarServiceWorker();
        pagina();
        await iniciar();
        aba('hidden');

        vi.advanceTimersByTime(3 * MEIA_HORA);
        await flush();

        expect(registro.update).not.toHaveBeenCalled();
    });

    it('sem rede a conferência falha em silêncio (sem rejeição solta)', async () => {
        vi.useFakeTimers();
        const { registro } = instalarServiceWorker({ update: async () => { throw new TypeError('Failed to fetch'); } });
        pagina();
        await iniciar();
        aba('visible');

        vi.advanceTimersByTime(MEIA_HORA);
        await flush();

        // Uma rejeição sem tratamento derrubaria esta suíte (o Vitest acusa).
        expect(registro.update).toHaveBeenCalledTimes(1);
    });
});
