import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { flush } from './helpers/flush.js';

/**
 * Service worker (`resources/views/pwa/service-worker.blade.php`, servido em /sw.js):
 * o que sobra no aparelho quando a sessão acaba.
 *
 * O achado (P-2 da auditoria de PWA de 06/09/2026): o SW guarda `/transactions/create`
 * para o lançamento abrir offline, e esse HTML traz as contas, as categorias e os nomes
 * da família. O logout mandava `Clear-Site-Data: "cache"` acreditando que isso o apagava
 * — mas "cache" é o cache HTTP. O Cache Storage (`caches.*`) só cai com "storage", que o
 * app evita de propósito porque levaria junto o IndexedDB da fila offline. Quem de fato
 * apagava era o JS da tela de login, SE ele rodasse. Num celular compartilhado, quem
 * pegasse o app offline depois do "Sair" abria o formulário do dono anterior.
 *
 * Aqui roda o código REAL do service worker. O arquivo é uma view Blade só para ser
 * servido por rota, mas o JavaScript mora inteiro dentro de `@verbatim` — o Blade não
 * interpreta nada ali —, então o que este teste executa é o que o navegador recebe. O
 * que é do navegador (Cache Storage, rede, IndexedDB, `self`) entra como dublê em memória.
 */

const ORIGEM = 'https://app.stabilmoney.test';
// Caminho em string, não `new URL(...)`: no ambiente jsdom o `URL` global é o do jsdom, e o
// `readFileSync` do Node não o reconhece como URL de arquivo.
const ARQUIVO = resolve(dirname(fileURLToPath(import.meta.url)), '../../resources/views/pwa/service-worker.blade.php');

/** O HTML autenticado do formulário, com o tipo de dado que não pode vazar. */
const FORMULARIO = '<form data-offline-queue>Nubank · Mercado · Victor (titular)</form>';
const PAGINA_OFFLINE = '<h1>Você está offline</h1>';

/** O JavaScript de dentro do `@verbatim` — exatamente o que o Laravel serve em /sw.js. */
function codigoServido() {
    const blade = readFileSync(ARQUIVO, 'utf8');
    const trecho = blade.match(/^\s*@verbatim\s*\n([\s\S]*)@endverbatim\s*$/);

    // Sem esta trava, um trecho Blade fora do @verbatim faria o teste executar um código
    // diferente do servido — e passar por um SW que ninguém recebe.
    if (!trecho) {
        throw new Error('O service worker saiu do @verbatim: o teste deixaria de executar o que o Laravel serve.');
    }

    return trecho[1];
}

// ---- Dublês do navegador ----------------------------------------------------

/** Envelope mínimo de `Response`: só o que o SW lê. */
function resposta(status, corpo = '', { type = 'basic', redirected = false } = {}) {
    return {
        status,
        ok: status >= 200 && status < 300,
        type,
        redirected,
        corpo,
        clone: () => resposta(status, corpo, { type, redirected }),
    };
}

/**
 * Navegação que o servidor redireciona chega ao SW como `opaqueredirect` (status 0): o
 * navegador segue o redirect sozinho, num evento de fetch NOVO para o destino.
 */
const redirecionamento = () => resposta(0, '', { type: 'opaqueredirect' });

/** Um cache do Cache Storage, com a semântica de chave que o SW usa (inclusive `ignoreSearch`). */
class CacheFalso {
    constructor(buscar) {
        this.buscar = buscar;
        this.entradas = new Map();
    }

    static url(req) {
        return new URL(typeof req === 'string' ? req : req.url, ORIGEM);
    }

    chaves(req, { ignoreSearch = false } = {}) {
        const alvo = CacheFalso.url(req);
        return [...this.entradas.keys()].filter((chave) => {
            const u = new URL(chave);
            return ignoreSearch ? u.origin + u.pathname === alvo.origin + alvo.pathname : u.href === alvo.href;
        });
    }

    async match(req, opcoes) {
        const [chave] = this.chaves(req, opcoes);
        return chave ? this.entradas.get(chave) : undefined;
    }

    async put(req, res) {
        this.entradas.set(CacheFalso.url(req).href, res);
    }

    async add(url) {
        const res = await this.buscar({ url: CacheFalso.url(url).href, method: 'GET', mode: 'no-cors' });
        if (res.status !== 200) throw new TypeError(`precache falhou: ${url}`);
        await this.put(url, res);
    }

    async delete(req, opcoes) {
        const chaves = this.chaves(req, opcoes);
        chaves.forEach((chave) => this.entradas.delete(chave));
        return chaves.length > 0;
    }
}

class CacheStorageFalso {
    constructor(buscar) {
        this.buscar = buscar;
        this.porNome = new Map();
    }

    async open(nome) {
        if (!this.porNome.has(nome)) this.porNome.set(nome, new CacheFalso(this.buscar));
        return this.porNome.get(nome);
    }

    async keys() {
        return [...this.porNome.keys()];
    }

    async delete(nome) {
        return this.porNome.delete(nome);
    }

    async match(req, opcoes) {
        for (const cache of this.porNome.values()) {
            const achou = await cache.match(req, opcoes);
            if (achou) return achou;
        }
        return undefined;
    }

    /** Caminhos guardados em TODOS os caches — o que alguém com o aparelho na mão alcança. */
    guardados() {
        return [...this.porNome.values()].flatMap((cache) =>
            [...cache.entradas.keys()].map((chave) => new URL(chave).pathname));
    }
}

/**
 * O servidor, do ponto de vista do navegador. `sessao` diz se o cookie do aparelho ainda
 * vale: é o que decide se /login abre (visitante) ou redireciona (grupo `guest`).
 */
function servidor(estado, req) {
    if (estado.offline) throw new TypeError('Failed to fetch');

    const { pathname } = new URL(req.url);

    if (pathname === '/offline') return resposta(200, PAGINA_OFFLINE);
    if (pathname === '/login' || pathname === '/register') {
        return estado.sessao ? redirecionamento() : resposta(200, '<h1>Entrar</h1>');
    }
    if (pathname === '/transactions/create') {
        return estado.sessao ? resposta(200, FORMULARIO) : redirecionamento();
    }

    return resposta(200, `conteúdo de ${pathname}`);
}

/**
 * Carrega o service worker num escopo falso e o deixa no estado de um aparelho em uso:
 * instalado, ativo, e com o dono tendo aberto o formulário online (o SW o guardou) e
 * carregado o CSS do app.
 */
async function aparelhoEmUso() {
    const estado = { sessao: true, offline: false };
    const ouvintes = {};
    const fetch = vi.fn(async (req) => servidor(estado, req));
    const caches = new CacheStorageFalso(fetch);
    // A fila offline mora no IndexedDB. Nenhum caminho da limpeza pode encostar nele.
    const indexedDB = { open: vi.fn(), deleteDatabase: vi.fn() };

    const escopo = {
        location: new URL('/sw.js', ORIGEM),
        addEventListener: (tipo, ouvinte) => { ouvintes[tipo] = ouvinte; },
        skipWaiting: vi.fn(async () => {}),
        clients: { claim: vi.fn(async () => {}) },
    };
    const ResponseFalso = { error: () => ({ status: 0, type: 'error', corpo: '' }) };

    // `new Function` recebe o arquivo do PRÓPRIO repositório (nada vindo de fora): é o jeito
    // de executar o SW como script clássico, com os globais do navegador trocados pelos
    // dublês acima — um `import` não serviria, o SW não é módulo.
    new Function('self', 'caches', 'fetch', 'indexedDB', 'Response', codigoServido())(
        escopo, caches, fetch, indexedDB, ResponseFalso,
    );

    const sw = { estado, ouvintes, fetch, caches, indexedDB };

    await ciclo(sw, 'install');
    await ciclo(sw, 'activate');
    await concluida(requisicao(sw, '/transactions/create'));
    await concluida(requisicao(sw, '/build/assets/app-3f9a1c.css', { mode: 'no-cors' }));

    // Pré-condição: sem ela, "o formulário sumiu" passaria sem o SW ter feito nada.
    expect(caches.guardados()).toContain('/transactions/create');
    fetch.mockClear();

    return sw;
}

/** Dispara `install`/`activate` e espera o `waitUntil`. */
async function ciclo(sw, tipo) {
    const pendencias = [];
    sw.ouvintes[tipo]({ waitUntil: (p) => pendencias.push(p) });
    await Promise.all(pendencias);
}

/** Um `fetch` do navegador passando pelo SW. Guarda o que ele prometeu responder/esperar. */
function requisicao(sw, caminho, { method = 'GET', mode = 'navigate' } = {}) {
    const evento = {
        request: { url: new URL(caminho, ORIGEM).href, method, mode },
        resposta: null,
        pendencias: [],
        respondWith(p) { this.resposta = Promise.resolve(p); },
        waitUntil(p) { this.pendencias.push(p); },
    };

    sw.ouvintes.fetch(evento);

    return evento;
}

/**
 * Espera tudo o que o SW deixou em andamento: a resposta, o `waitUntil` e o `cache.put`
 * que o ramo do formulário dispara sem aguardar.
 */
async function concluida(evento) {
    const res = await evento.resposta;
    await Promise.all(evento.pendencias);
    await flush();
    return res;
}

// ---- Testes -----------------------------------------------------------------

describe('service worker: o HTML autenticado não sobrevive ao fim da sessão', () => {
    let sw;

    beforeEach(async () => {
        sw = await aparelhoEmUso();
    });

    describe('gatilho 1: o "Sair" (POST /logout)', () => {
        it('apaga o formulário guardado', async () => {
            await concluida(requisicao(sw, '/logout', { method: 'POST' }));

            expect(sw.caches.guardados()).not.toContain('/transactions/create');
        });

        it('não responde pelo POST: ele segue direto para a rede, como todo POST', async () => {
            const saida = requisicao(sw, '/logout', { method: 'POST' });
            await concluida(saida);

            // `respondWith` intocado = o navegador envia o POST sozinho, com o CSRF do form.
            expect(saida.resposta).toBeNull();
            expect(sw.fetch).not.toHaveBeenCalled();
        });

        it('é o cenário da auditoria: depois do "Sair", offline, o formulário não abre mais', async () => {
            await concluida(requisicao(sw, '/logout', { method: 'POST' }));

            sw.estado.offline = true;
            const aberto = await concluida(requisicao(sw, '/transactions/create'));

            expect(aberto.corpo).not.toContain('Nubank');
            expect(aberto.corpo).toBe(PAGINA_OFFLINE);
        });

        it('apaga mesmo sem rede no momento do clique — a intenção de sair já basta', async () => {
            sw.estado.offline = true;

            await concluida(requisicao(sw, '/logout', { method: 'POST' }));

            expect(sw.caches.guardados()).not.toContain('/transactions/create');
        });

        it('leva só o HTML autenticado: CSS, ícones, página offline e a fila ficam', async () => {
            await concluida(requisicao(sw, '/logout', { method: 'POST' }));

            expect(sw.caches.guardados()).toEqual(expect.arrayContaining([
                '/offline',
                '/assets/icons/icon-192.png',
                '/build/assets/app-3f9a1c.css',
            ]));
            // A fila de lançamentos offline é a única cópia de um dinheiro que ainda não
            // chegou ao servidor.
            expect(sw.indexedDB.open).not.toHaveBeenCalled();
            expect(sw.indexedDB.deleteDatabase).not.toHaveBeenCalled();
        });

        it('varre todos os caches, não só o da versão atual', async () => {
            // Mesmo critério da limpeza da página (offline-queue.js): formulário guardado sob
            // outro nome continua sendo dado de usuário.
            const outro = await sw.caches.open('sm-cache-v1');
            await outro.put('/transactions/create', resposta(200, FORMULARIO));

            await concluida(requisicao(sw, '/logout', { method: 'POST' }));

            expect(sw.caches.guardados()).not.toContain('/transactions/create');
        });
    });

    describe('gatilho 2: página que só abre SEM sessão respondendo 200', () => {
        it('/login abrindo apaga o formulário (sessão expirada, conta banida, logout em outro aparelho)', async () => {
            sw.estado.sessao = false;

            const login = await concluida(requisicao(sw, '/login'));

            expect(login.status).toBe(200); // a página chega normalmente
            expect(sw.caches.guardados()).not.toContain('/transactions/create');
        });

        it('/register abrindo também apaga', async () => {
            sw.estado.sessao = false;

            await concluida(requisicao(sw, '/register'));

            expect(sw.caches.guardados()).not.toContain('/transactions/create');
        });

        it('a tela de login abre mesmo com o Cache Storage travado: a limpeza não segura a página', async () => {
            sw.estado.sessao = false;
            sw.caches.keys = () => new Promise(() => {}); // nunca responde

            const login = requisicao(sw, '/login');
            const chegou = await Promise.race([
                login.resposta,
                new Promise((resolve) => setTimeout(() => resolve('travou'), 200)),
            ]);

            expect(chegou).not.toBe('travou');
            expect(chegou.status).toBe(200);
        });

        it('quem ainda tem sessão e abre /login é redirecionado — e o formulário fica', async () => {
            const login = await concluida(requisicao(sw, '/login'));

            expect(login.type).toBe('opaqueredirect');
            expect(sw.caches.guardados()).toContain('/transactions/create');
        });

        it('offline não apaga: sem resposta do servidor não há prova de que a sessão acabou', async () => {
            sw.estado.offline = true;

            const login = await concluida(requisicao(sw, '/login'));

            expect(login.corpo).toBe(PAGINA_OFFLINE);
            expect(sw.caches.guardados()).toContain('/transactions/create');
        });

        it('uma página qualquer respondendo 200 não apaga nada', async () => {
            await concluida(requisicao(sw, '/termos'));
            await concluida(requisicao(sw, '/'));

            expect(sw.caches.guardados()).toContain('/transactions/create');
        });
    });

    describe('o lançamento offline do dono continua funcionando', () => {
        it('o formulário abre offline para quem não saiu da conta', async () => {
            sw.estado.offline = true;

            const aberto = await concluida(requisicao(sw, '/transactions/create'));

            expect(aberto.corpo).toBe(FORMULARIO);
        });

        it('o deep-link com autor (?autor=ID) abre o mesmo formulário offline', async () => {
            sw.estado.offline = true;

            const aberto = await concluida(requisicao(sw, '/transactions/create?autor=5'));

            expect(aberto.corpo).toBe(FORMULARIO);
        });

        it('o formulário volta a ser guardado no próximo acesso online, depois de um novo login', async () => {
            await concluida(requisicao(sw, '/logout', { method: 'POST' }));

            await concluida(requisicao(sw, '/transactions/create'));

            expect(sw.caches.guardados()).toContain('/transactions/create');
        });

        it('um POST comum (lançar, pagar) passa direto e não apaga nada', async () => {
            const lancamento = requisicao(sw, '/transactions', { method: 'POST' });
            await concluida(lancamento);

            expect(lancamento.resposta).toBeNull();
            expect(lancamento.pendencias).toHaveLength(0);
            expect(sw.caches.guardados()).toContain('/transactions/create');
        });
    });
});
