import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { flush } from './helpers/flush.js';

/**
 * Formulário cheio de lançamento (`offline-queue.js`): a chave de idempotência
 * (`client_uuid`) é UMA POR LANÇAMENTO da tela, não uma por clique em "Salvar" (24/09/2026).
 *
 * O servidor deduplica pela chave. Com uma chave nova a cada clique, um envio que terminou
 * sem resposta de sucesso mas FOI gravado — o 504 do nginx com o PHP ainda trabalhando —
 * virava dois lançamentos no "Tente de novo". A chave agora fica no formulário até o
 * lançamento ir para a fila (que zera o formulário para o próximo).
 */

let naFila;

function indexedDbEmMemoria() {
    naFila = [];
    const db = {
        objectStoreNames: { contains: () => true },
        createObjectStore: () => {},
        transaction() {
            const leituras = [];
            const store = {
                put: (item) => { naFila.push(item.client_uuid); return {}; },
                delete: () => ({}),
                getAll: () => {
                    const pedido = {};
                    leituras.push(() => pedido.onsuccess?.({ target: { result: [] } }));
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

const resposta = (status, corpo = {}) => ({ status, ok: status >= 200 && status < 300, json: async () => corpo });

let posts;
let respostas;
let online;

beforeEach(async () => {
    vi.stubGlobal('indexedDB', indexedDbEmMemoria());
    online = true;
    vi.spyOn(navigator, 'onLine', 'get').mockImplementation(() => online);

    posts = [];
    respostas = [];
    global.fetch = vi.fn(async (url, init = {}) => {
        if (String(url).includes('/csrf-token')) return resposta(200, { token: 'fresco' });
        if ((init.method || 'GET') !== 'POST') return resposta(200, []);
        posts.push(JSON.parse(init.body));
        return respostas.shift() || resposta(500);
    });

    document.head.innerHTML = '<meta name="csrf-token" content="token"><meta name="sm-user" content="7">';
    document.body.innerHTML = `
        <main id="content">
            <form method="POST" action="/transactions" data-offline-queue>
                <input type="hidden" name="_token" value="token">
                <input type="radio" name="type" value="expense" checked>
                <input type="text" inputmode="decimal" name="amount" value="150,00">
                <input type="date" name="date" value="2026-09-24">
                <select name="account_id"><option value="3" selected>Corrente</option></select>
                <input type="text" name="description" value="Mercado">
                <button type="submit">Salvar</button>
            </form>
        </main>`;

    vi.resetModules();
    const { initOfflineQueue } = await import('../../resources/js/sm/offline-queue.js');
    initOfflineQueue();
    await flush(100);
    posts = [];
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    document.head.innerHTML = '';
    document.body.innerHTML = '';
});

async function salvar() {
    document.querySelector('form[data-offline-queue]')
        .dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await flush(200);
}

describe('formulário cheio: uma chave por lançamento', () => {
    it('"Tente de novo" depois de um 504 manda a MESMA chave — o servidor não duplica', async () => {
        respostas = [resposta(504), resposta(200)];

        await salvar();
        await salvar();

        expect(posts).toHaveLength(2);
        expect(posts[1].client_uuid).toBe(posts[0].client_uuid);
    });

    it('depois de ir para a fila, o próximo lançamento da tela ganha chave nova', async () => {
        online = false;

        await salvar();
        await salvar();

        // Nenhum POST (sem rede): os dois lançamentos foram para a fila, cada um com a sua chave.
        expect(posts).toHaveLength(0);
        expect(naFila).toHaveLength(2);
        expect(naFila[1]).not.toBe(naFila[0]);
    });
});
