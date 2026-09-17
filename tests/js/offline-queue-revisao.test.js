import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { flush } from './helpers/flush.js';
import tabela from './fixtures/valores-da-fila-offline.json';

/**
 * Fila offline (`resources/js/sm/offline-queue.js`) — o VALOR de cada lançamento no aviso
 * "precisa de você" (itens retidos por 409 ou 422).
 *
 * P-3 da auditoria de PWA de 06/09/2026: o payload guarda o valor como a máscara deixou
 * ("31.000,00"), o aviso fazia `Number()` disso, dava NaN e mostrava R$ 0,00 em TODO item.
 * Num aviso sobre dinheiro que ainda não chegou ao servidor, o valor é o que a pessoa usa
 * para reconhecer o lançamento antes de escolher a fonte ou descartar — é o pior lugar
 * para um número errado.
 *
 * A leitura tem de seguir as regras do SERVIDOR (`NormalizesMoneyInput` + `decimal:0,2`),
 * não as do JS. A tabela de casos é compartilhada com
 * `tests/Feature/ValorDaFilaOfflineIgualAoServidorTest.php`, que manda cada entrada ao
 * servidor de verdade: o que este teste espera ver é o que o servidor grava.
 *
 * Tudo passa pelo módulo REAL: a página abre, a fila é lida do IndexedDB e o aviso é
 * desenhado pelo próprio `initOfflineQueue()`.
 */

/**
 * IndexedDB em memória, só com o que o módulo usa (open → transaction → objectStore →
 * put/delete/getAll). Os eventos disparam em microtask, DEPOIS de o módulo pendurar os
 * handlers — como no navegador. O jsdom não tem IndexedDB, e sem ele a fila falha calada.
 */
function indexedDbEmMemoria(registros) {
    const copia = (valor) => JSON.parse(JSON.stringify(valor));
    const dados = new Map(registros.map((r) => [r.client_uuid, copia(r)]));

    const db = {
        objectStoreNames: { contains: () => true },
        createObjectStore: () => {},
        transaction() {
            const leituras = [];
            const store = {
                put: (item) => { dados.set(item.client_uuid, copia(item)); return {}; },
                delete: (chave) => { dados.delete(chave); return {}; },
                getAll: () => {
                    const pedido = {};
                    leituras.push(() => pedido.onsuccess?.({ target: { result: [...dados.values()].map(copia) } }));
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

/** Abre uma página logada (usuário 7) com UM lançamento retido na fila e devolve o texto do aviso. */
async function avisoDoItem(item) {
    document.head.innerHTML = '<meta name="csrf-token" content="token-da-pagina"><meta name="sm-user" content="7">';

    vi.stubGlobal('indexedDB', indexedDbEmMemoria([{
        client_uuid: '6f1c2a4e-0000-4000-8000-000000000001',
        userId: '7',
        csrf: 'token-da-pagina',
        createdAt: 1,
        ...item,
    }]));

    vi.resetModules();
    const { initOfflineQueue } = await import('../../resources/js/sm/offline-queue.js');
    initOfflineQueue();
    await flush(200);

    const aviso = document.getElementById('sm-fila-revisao');
    expect(aviso, 'o aviso de lançamentos retidos não apareceu').not.toBeNull();

    return aviso.textContent;
}

/** Item retido por 409: o saldo não cobre e falta escolher a fonte. */
const retidoPorFonte = (amount, description = 'Mercado') => ({
    needsFunding: true,
    fonte: { precisa_fonte: true },
    payload: { type: 'expense', description, amount },
});

beforeEach(() => {
    // Offline: a página não tenta reenviar nada (o reenvio não é o assunto aqui).
    vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false);
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    document.head.innerHTML = '';
    document.body.innerHTML = '';
});

describe('offline-queue: valor no aviso de lançamentos retidos', () => {
    it('mostra o valor que a máscara guardou, não R$ 0,00 (P-3)', async () => {
        const texto = await avisoDoItem(retidoPorFonte('31.000,00'));

        expect(texto).toContain('Mercado (R$ 31.000,00) — o saldo não cobre');
        expect(texto).not.toContain('R$ 0,00');
    });

    it('vale também para o item que o servidor recusou (422)', async () => {
        const texto = await avisoDoItem({
            failed: true,
            motivo: 'Não dá: faltam R$ 1.100,00 e não há cheque especial.',
            payload: { type: 'expense', description: 'Farmácia', amount: '1.300,00' },
        });

        expect(texto).toContain('Farmácia (R$ 1.300,00) — Não dá: faltam R$ 1.100,00');
    });

    it.each(tabela.casos)('lê "$entrada" como o servidor: $rotulo ($porque)', async ({ entrada, rotulo }) => {
        const texto = await avisoDoItem(retidoPorFonte(entrada));

        expect(texto).toContain(`Mercado (${rotulo}) —`);
    });

    it('negativo sai no padrão do app, com o sinal antes do R$', async () => {
        // Lançamento nunca é negativo (o servidor recusa), mas o formato é o do Brl::format.
        const texto = await avisoDoItem(retidoPorFonte('-1.234,56'));

        expect(texto).toContain('Mercado (−R$ 1.234,56) —');
    });
});
