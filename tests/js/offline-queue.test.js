import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { flush } from './helpers/flush.js';

/**
 * Fila offline (`resources/js/sm/offline-queue.js`) — a limpeza do formulário guardado
 * offline feita PELA PÁGINA.
 *
 * Desde a correção do P-2 (auditoria de PWA de 06/09/2026) quem apaga o
 * `/transactions/create` no fim da sessão é o próprio service worker
 * (`tests/js/service-worker.test.js`). Esta limpeza virou a SEGUNDA camada, e é por isso
 * que ela tem teste: é fácil olhar para o SW, concluir que isto aqui sobrou e remover.
 * Ela ainda cobre o que o SW não alcança — o SW de versão antiga que continua no controle
 * logo depois de um deploy, e o cache que fica no aparelho quando o SW é desregistrado.
 *
 * O Cache Storage entra como dublê que registra o que foi apagado. O IndexedDB fica sem
 * implementação de propósito: a limpeza não pode depender dele (e, sem ele, a fila falha
 * calada, que é o comportamento do módulo quando o navegador não tem IndexedDB).
 */

let apagados;

/** Carrega o módulo real numa página com (ou sem) usuário logado. */
async function abrirPagina({ usuario = null } = {}) {
    document.head.innerHTML = [
        '<meta name="csrf-token" content="token-da-pagina">',
        usuario ? `<meta name="sm-user" content="${usuario}">` : '',
    ].join('');

    vi.resetModules();
    const { initOfflineQueue } = await import('../../resources/js/sm/offline-queue.js');
    initOfflineQueue();
    await flush();
}

beforeEach(() => {
    apagados = [];
    localStorage.clear();

    // `initOfflineQueue` só confere se o IndexedDB existe.
    vi.stubGlobal('indexedDB', {});
    vi.stubGlobal('caches', {
        keys: async () => ['sm-cache-v2'],
        open: async (nome) => ({
            delete: async (chave) => {
                apagados.push(`${nome} ${chave}`);
                return true;
            },
        }),
    });
    // Offline: a página logada não tenta sincronizar a fila (não é o assunto aqui).
    vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false);
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    document.head.innerHTML = '';
});

describe('offline-queue: segunda camada da limpeza do formulário offline', () => {
    it('na tela de login (ninguém logado), apaga o formulário de quem usou o aparelho antes', async () => {
        localStorage.setItem('sm-form-user', '7');

        await abrirPagina();

        expect(apagados).toContain('sm-cache-v2 /transactions/create');
        expect(localStorage.getItem('sm-form-user')).toBeNull();
    });

    it('quando OUTRA pessoa entra no aparelho, apaga o formulário do dono anterior', async () => {
        localStorage.setItem('sm-form-user', '7');

        await abrirPagina({ usuario: '9' });

        expect(apagados).toContain('sm-cache-v2 /transactions/create');
        expect(localStorage.getItem('sm-form-user')).toBe('9');
    });

    it('o mesmo dono recarregando o app mantém o formulário para lançar offline', async () => {
        localStorage.setItem('sm-form-user', '7');

        await abrirPagina({ usuario: '7' });

        expect(apagados).toEqual([]);
    });
});
