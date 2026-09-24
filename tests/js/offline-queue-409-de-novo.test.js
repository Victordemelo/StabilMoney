import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { flush } from './helpers/flush.js';

/**
 * Formulário cheio de lançamento (`offline-queue.js`, envio ONLINE) quando o 409 volta depois
 * da escolha da fonte (24/09/2026).
 *
 * O servidor recalcula na hora de gravar: se o disponível caiu desde a pergunta, o valor
 * aprovado ficou pequeno e ele devolve outro 409, com as opções recalculadas — em vez de
 * resgatar ou usar cheque especial além do que a pessoa viu. O formulário perguntava uma
 * vez só e o segundo 409 caía no "Não foi possível registrar agora". Agora pergunta de novo
 * (no máximo 3 vezes), e a escolha anterior não sobra no reenvio.
 *
 * `pedirFonte` é dublê (o modal tem teste próprio em funding.test.js); o IndexedDB é o mesmo
 * dublê em memória de `offline-queue-form-pelo-pjax.test.js`.
 */

const mocks = vi.hoisted(() => ({ pedirFonte: vi.fn() }));

vi.mock('../../resources/js/sm/funding.js', () => ({ pedirFonte: mocks.pedirFonte }));

function indexedDbEmMemoria() {
    const db = {
        objectStoreNames: { contains: () => true },
        createObjectStore: () => {},
        transaction() {
            const leituras = [];
            const store = {
                put: () => ({}),
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

beforeEach(async () => {
    vi.stubGlobal('indexedDB', indexedDbEmMemoria());
    vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(true);

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
    mocks.pedirFonte.mockReset();
    document.head.innerHTML = '';
    document.body.innerHTML = '';
});

async function salvar() {
    document.querySelector('form[data-offline-queue]')
        .dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    await flush(200);
}

describe('formulário cheio: o 409 que volta depois da escolha', () => {
    it('pergunta de novo com as opções recalculadas, e o reenvio leva só a última escolha', async () => {
        const recalculado = { faltante: 300, fontes: [{ id: 'cheque_especial', cobre: true }] };
        respostas = [
            resposta(409, { fonte: { faltante: 120, fontes: [] } }),
            resposta(409, { fonte: recalculado }),
            resposta(201),
        ];
        mocks.pedirFonte
            .mockResolvedValueOnce({ funding_source: 'resgate_investimento', funding_investment_id: '9', funding_max_amount: '120.00' })
            .mockResolvedValueOnce({ funding_source: 'cheque_especial', funding_max_amount: '300.00' });

        await salvar();

        expect(mocks.pedirFonte).toHaveBeenCalledTimes(2);
        expect(mocks.pedirFonte.mock.calls[1][0]).toEqual(recalculado);
        expect(posts).toHaveLength(3);
        expect(posts[2]).toMatchObject({ funding_source: 'cheque_especial', funding_max_amount: '300.00' });
        expect(posts[2]).not.toHaveProperty('funding_investment_id');
        expect(new Set(posts.map((p) => p.client_uuid)).size).toBe(1);
    });

    it('cancelar na segunda pergunta não reenvia', async () => {
        respostas = [resposta(409, { fonte: {} }), resposta(409, { fonte: {} })];
        mocks.pedirFonte
            .mockResolvedValueOnce({ funding_source: 'cheque_especial', funding_max_amount: '120.00' })
            .mockResolvedValueOnce(null);

        await salvar();

        expect(posts).toHaveLength(2);
    });
});
