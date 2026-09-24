import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { flush } from './helpers/flush.js';

/**
 * Fila offline (`resources/js/sm/offline-queue.js`) — o formulário CHEIO de lançamento
 * (`/transactions/create`, `form[data-offline-queue]`) quando ele chega pelo pjax.
 *
 * O `initOfflineQueue()` roda UMA vez, na carga da página (é do shell). Até aqui ele
 * procurava o formulário naquele instante e ligava o `submit` NO ELEMENTO. Só que o
 * formulário também entra pelo pjax, que troca o `#content` inteiro — e aí o elemento é
 * outro, sem ouvinte nenhum:
 *
 *  - a página cheia aberta, a pessoa usa o "Lançar" da topbar e salva: o modal chama o
 *    `smPjaxReload()`, que remonta o `/transactions/create` com um formulário novo;
 *  - a pessoa abre "Nova transação", vai ao Histórico pelo menu (pjax) e volta pelo
 *    botão "voltar" do navegador (`popstate` → pjax).
 *
 * Nos dois casos o "Salvar" virava o POST comum do navegador. Sem internet — que é
 * justamente para o que esta página é guardada offline —, o lançamento morria numa página
 * de erro de rede em vez de ir para a fila. Online, perdia o retry do 419.
 *
 * O IndexedDB é um dublê em memória (o jsdom não tem), com o mesmo recorte do usado em
 * `offline-queue-revisao.test.js`: open → transaction → objectStore → put/delete/getAll.
 */

let fila;

function indexedDbEmMemoria() {
    const copia = (valor) => JSON.parse(JSON.stringify(valor));
    fila = new Map();

    const db = {
        objectStoreNames: { contains: () => true },
        createObjectStore: () => {},
        transaction() {
            const leituras = [];
            const store = {
                put: (item) => { fila.set(item.client_uuid, copia(item)); return {}; },
                delete: (chave) => { fila.delete(chave); return {}; },
                getAll: () => {
                    const pedido = {};
                    leituras.push(() => pedido.onsuccess?.({ target: { result: [...fila.values()].map(copia) } }));
                    return pedido;
                },
            };
            const tx = { objectStore: () => store };

            queueMicrotask(() => {
                leituras.forEach((ler) => ler());
                tx.oncomplete?.();
            });

            return tx;
        },
    };

    return {
        open() {
            const pedido = { result: db };
            queueMicrotask(() => pedido.onsuccess?.());
            return pedido;
        },
    };
}

/** O formulário como o `transactions/_form.blade.php` o entrega na criação. */
function formularioDeLancamento(descricao) {
    return `
        <form method="POST" action="/transactions" data-tx-form data-offline-queue>
            <input type="hidden" name="_token" value="token-da-pagina">
            <input type="radio" name="type" value="expense" checked>
            <input type="text" inputmode="decimal" name="amount" value="42,00">
            <input type="date" name="date" value="2026-09-24">
            <select name="account_id"><option value="3" selected>Corrente</option></select>
            <input type="text" name="description" value="${descricao}">
            <button type="submit">Salvar</button>
        </form>`;
}

/** Página logada (usuário 7) aberta com `conteudo` no #content; liga a fila como o app.js. */
async function abrirPagina(conteudo) {
    document.head.innerHTML = '<meta name="csrf-token" content="token-da-pagina"><meta name="sm-user" content="7">';
    document.body.innerHTML = `<main id="content">${conteudo}</main>`;

    vi.resetModules();
    const { initOfflineQueue } = await import('../../resources/js/sm/offline-queue.js');
    initOfflineQueue();
    await flush(100);
}

/** O que o `nav.js` faz com o #content numa troca por pjax: HTML novo, elementos novos. */
function trocarConteudoComoOPjax(conteudo) {
    document.getElementById('content').innerHTML = conteudo;
}

/** "Salvar" no formulário da tela; devolve o evento para ver se alguém o interceptou. */
async function salvar() {
    const evento = new Event('submit', { bubbles: true, cancelable: true });
    document.querySelector('form[data-offline-queue]').dispatchEvent(evento);
    await flush(100);

    return evento;
}

beforeEach(() => {
    vi.stubGlobal('indexedDB', indexedDbEmMemoria());
    // SEM internet: o envio tem de ir para a fila, não para a rede.
    vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false);
    global.fetch = vi.fn(async () => { throw new TypeError('Failed to fetch'); });
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    document.head.innerHTML = '';
    document.body.innerHTML = '';
});

describe('offline-queue: formulário cheio que chega pelo pjax', () => {
    it('formulário da carga da página: sem internet, vai para a fila (o que já funcionava)', async () => {
        await abrirPagina(formularioDeLancamento('Padaria'));

        const evento = await salvar();

        expect(evento.defaultPrevented).toBe(true);
        expect([...fila.values()].map((i) => i.payload.description)).toEqual(['Padaria']);
    });

    it('formulário remontado pelo smPjaxReload (depois do "Lançar"): sem internet, também vai para a fila', async () => {
        await abrirPagina(formularioDeLancamento('Padaria'));
        trocarConteudoComoOPjax(formularioDeLancamento('Farmácia'));

        const evento = await salvar();

        // Sem ninguém interceptar, o navegador faria o POST — e sem rede, página de erro.
        expect(evento.defaultPrevented).toBe(true);
        expect([...fila.values()].map((i) => i.payload.description)).toEqual(['Farmácia']);
    });

    it('formulário que volta pelo "voltar" do navegador (popstate → pjax): sem internet, vai para a fila', async () => {
        // A página aberta era outra (o Histórico, por exemplo): na carga não havia formulário.
        await abrirPagina('<h1>Histórico</h1>');
        trocarConteudoComoOPjax(formularioDeLancamento('Mercado'));

        const evento = await salvar();

        expect(evento.defaultPrevented).toBe(true);
        const [item] = fila.values();
        expect(item.payload).toMatchObject({ description: 'Mercado', amount: '42,00', account_id: '3' });
        // Carimbado com o dono da página — a trava da troca de usuário continua valendo.
        expect(item.userId).toBe('7');
        expect(item.payload).not.toHaveProperty('_token');
    });
});
