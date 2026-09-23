import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { flush } from './helpers/flush.js';

/**
 * Service worker (`resources/views/pwa/service-worker.blade.php`, servido em /sw.js):
 * o que sobra no aparelho quando a sessão acaba — e o que acontece com ele a cada deploy.
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
 * servido por rota e por UMA linha: a primeira, `const VERSAO = @json($versao);`, pela qual
 * o PwaController põe no script a versão do build (P-6 da mesma auditoria). Todo o resto
 * mora dentro de `@verbatim` — o Blade não interpreta nada ali —, então o que este teste
 * executa é o que o navegador recebe, com a versão trocada pela do teste (é assim que ele
 * simula um deploy). O que é do navegador (Cache Storage, rede, IndexedDB, `self`) entra
 * como dublê em memória.
 */

const ORIGEM = 'https://app.stabilmoney.test';
// Caminho em string, não `new URL(...)`: no ambiente jsdom o `URL` global é o do jsdom, e o
// `readFileSync` do Node não o reconhece como URL de arquivo.
const ARQUIVO = resolve(dirname(fileURLToPath(import.meta.url)), '../../resources/views/pwa/service-worker.blade.php');

/** O HTML autenticado do formulário, com o tipo de dado que não pode vazar. */
const FORMULARIO = '<form data-offline-queue>Nubank · Mercado · Victor (titular)</form>';
const PAGINA_OFFLINE = '<h1>Você está offline</h1>';

/** A versão do build com que o aparelho de teste nasce. */
const VERSAO_INICIAL = 'build-1';

/**
 * O JavaScript servido em /sw.js a partir do conteúdo da view, com a versão `versao`.
 *
 * O ponto controlado é a linha da versão, exatamente como está na view. Qualquer OUTRO
 * trecho fora do @verbatim — uma interpolação a mais, uma diretiva — faz o teste se
 * recusar a rodar: ele passaria a executar um código diferente do servido, e a aprovar um
 * SW que ninguém recebe.
 */
function extrairCodigo(blade, versao) {
    const trecho = blade.match(/^const VERSAO = @json\(\$versao\);\n@verbatim\n([\s\S]*)@endverbatim\s*$/);

    // O Blade fecha o bloco no PRIMEIRO `@endverbatim`: um segundo no meio quer dizer que
    // há Blade de verdade entre dois blocos — o que a captura acima, sozinha, aceitaria.
    if (!trecho || trecho[1].includes('@endverbatim')) {
        throw new Error(
            'O service worker saiu do formato "linha da versão + @verbatim": o teste deixaria de executar o que o Laravel serve.',
        );
    }

    // Mesmo formato que o `@json` do Blade produz para uma string.
    return `const VERSAO = ${JSON.stringify(versao)};\n${trecho[1]}`;
}

function codigoServido(versao = VERSAO_INICIAL) {
    return extrairCodigo(readFileSync(ARQUIVO, 'utf8'), versao);
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

const ResponseFalso = { error: () => ({ status: 0, type: 'error', corpo: '' }) };

/**
 * Executa o service worker da versão `versao` num escopo falso, sobre o aparelho
 * `aparelho` (rede, Cache Storage e IndexedDB). Dois SWs sobre o mesmo aparelho = o
 * navegador instalando a versão nova por cima da antiga, que é o que um deploy faz.
 */
function executarServiceWorker(aparelho, versao) {
    const ouvintes = {};
    const escopo = {
        location: new URL('/sw.js', ORIGEM),
        addEventListener: (tipo, ouvinte) => { ouvintes[tipo] = ouvinte; },
        skipWaiting: vi.fn(async () => {}),
        clients: { claim: vi.fn(async () => {}) },
    };

    // `new Function` recebe o arquivo do PRÓPRIO repositório (nada vindo de fora): é o jeito
    // de executar o SW como script clássico, com os globais do navegador trocados pelos
    // dublês acima — um `import` não serviria, o SW não é módulo.
    new Function('self', 'caches', 'fetch', 'indexedDB', 'Response', codigoServido(versao))(
        escopo, aparelho.caches, aparelho.fetch, aparelho.indexedDB, ResponseFalso,
    );

    return { ...aparelho, ouvintes, escopo };
}

/**
 * Carrega o service worker num escopo falso e o deixa no estado de um aparelho em uso:
 * instalado, ativo, e com o dono tendo aberto o formulário online (o SW o guardou) e
 * carregado o CSS do app.
 */
async function aparelhoEmUso(versao = VERSAO_INICIAL) {
    const estado = { sessao: true, offline: false };
    const fetch = vi.fn(async (req) => servidor(estado, req));
    const caches = new CacheStorageFalso(fetch);
    // A fila offline mora no IndexedDB. Nenhum caminho da limpeza pode encostar nele.
    const indexedDB = { open: vi.fn(), deleteDatabase: vi.fn() };

    const sw = executarServiceWorker({ estado, fetch, caches, indexedDB }, versao);

    await ciclo(sw, 'install');
    await ciclo(sw, 'activate');
    await concluida(requisicao(sw, '/transactions/create'));
    await concluida(requisicao(sw, '/build/assets/app-3f9a1c.css', { mode: 'no-cors' }));

    // Pré-condição: sem ela, "o formulário sumiu" passaria sem o SW ter feito nada.
    expect(caches.guardados()).toContain('/transactions/create');
    fetch.mockClear();

    return sw;
}

/**
 * O deploy visto do aparelho: o navegador baixa o /sw.js, os bytes mudaram (a versão do
 * build está neles), e o SW novo instala e ativa por cima do antigo — `skipWaiting` +
 * `clients.claim`, sem esperar as abas fecharem.
 */
async function deploy(sw, versao) {
    const novo = executarServiceWorker(sw, versao);

    await ciclo(novo, 'install');
    await ciclo(novo, 'activate');

    return novo;
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

describe('service worker: cada build tem a sua versão, e o deploy chega ao aparelho (P-6)', () => {
    // O defeito: o nome do cache era fixo (`sm-cache-v2`). O navegador só instala SW novo
    // quando os BYTES do /sw.js mudam, e um deploy não mudava byte nenhum — a aba aberta
    // (num app instalado, por dias) seguia com o CSS/JS velhos, e o cache do /build guardava
    // o CSS de todos os deploys para sempre. Agora a versão do build está no script.
    let sw;

    beforeEach(async () => {
        sw = await aparelhoEmUso(VERSAO_INICIAL);
    });

    const nomesDosCaches = () => [...sw.caches.porNome.keys()];

    it('o nome do cache leva a versão do build', () => {
        expect(nomesDosCaches()).toHaveLength(1);
        expect(nomesDosCaches()[0]).toContain(VERSAO_INICIAL);
    });

    it('build novo: o activate apaga o cache inteiro da versão anterior', async () => {
        sw = await deploy(sw, 'build-2');

        expect(nomesDosCaches()).toHaveLength(1);
        expect(nomesDosCaches()[0]).toContain('build-2');
        // O CSS do build anterior não existe mais no servidor e sai do aparelho.
        expect(sw.caches.guardados()).not.toContain('/build/assets/app-3f9a1c.css');
    });

    it('o /build não acumula: depois de três deploys só sobra o cache do último', async () => {
        for (const versao of ['build-2', 'build-3', 'build-4']) {
            await concluida(requisicao(sw, `/build/assets/app-${versao}.css`, { mode: 'no-cors' }));
            sw = await deploy(sw, versao);
        }

        expect(nomesDosCaches()).toHaveLength(1);
        expect(sw.caches.guardados().filter((caminho) => caminho.startsWith('/build/'))).toEqual([]);
    });

    it('a versão nova chega com a página offline e os ícones de volta no precache', async () => {
        sw = await deploy(sw, 'build-2');

        expect(sw.caches.guardados()).toEqual(expect.arrayContaining([
            '/offline',
            '/assets/icons/icon-192.png',
        ]));
    });

    it('o deploy não encosta na fila offline (IndexedDB)', async () => {
        await deploy(sw, 'build-2');

        // A fila é a única cópia de um dinheiro que ainda não chegou ao servidor.
        expect(sw.indexedDB.open).not.toHaveBeenCalled();
        expect(sw.indexedDB.deleteDatabase).not.toHaveBeenCalled();
    });

    it('o formulário da versão velha vai junto, e offline a navegação cai na página offline — nunca num form sem CSS', async () => {
        // O form guardado aponta para o CSS/JS do build anterior, que acabou de sair do
        // cache: aberto offline, viria sem estilo e sem a fila (o envio iria direto para a
        // rede e se perderia). Melhor a página offline, que diz a verdade.
        sw = await deploy(sw, 'build-2');
        sw.estado.offline = true;

        const aberto = await concluida(requisicao(sw, '/transactions/create'));

        expect(sw.caches.guardados()).not.toContain('/transactions/create');
        expect(aberto.corpo).toBe(PAGINA_OFFLINE);
    });

    it('o formulário volta a ser guardado, no cache da versão nova, no próximo acesso online', async () => {
        sw = await deploy(sw, 'build-2');

        await concluida(requisicao(sw, '/transactions/create'));

        const cacheNovo = sw.caches.porNome.get(nomesDosCaches()[0]);
        expect([...cacheNovo.entradas.keys()].map((chave) => new URL(chave).pathname)).toContain('/transactions/create');
    });

    it('SW novo com o MESMO build (mudou só o código dele) não apaga nada à toa', async () => {
        sw = await deploy(sw, VERSAO_INICIAL);

        expect(sw.caches.guardados()).toEqual(expect.arrayContaining([
            '/transactions/create',
            '/build/assets/app-3f9a1c.css',
        ]));
    });

    describe('a página pergunta a versão (ver sm/pwa.js)', () => {
        function mensagem(dados) {
            const origem = { postMessage: vi.fn() };
            sw.ouvintes.message({ data: dados, source: origem });
            return origem.postMessage;
        }

        it('responde com a versão do build que ele carrega', async () => {
            sw = await deploy(sw, 'build-2');

            expect(mensagem({ tipo: 'sm-versao?' })).toHaveBeenCalledWith({ tipo: 'sm-versao', versao: 'build-2' });
        });

        it('mensagem que não é a pergunta fica sem resposta', () => {
            expect(mensagem({ tipo: 'outra-coisa' })).not.toHaveBeenCalled();
            expect(mensagem(null)).not.toHaveBeenCalled();
        });
    });
});

describe('a trava do teste: só a linha da versão pode ficar fora do @verbatim', () => {
    const blade = readFileSync(ARQUIVO, 'utf8');

    it('executa o arquivo de verdade', () => {
        expect(() => extrairCodigo(blade, 'x')).not.toThrow();
    });

    it('recusa Blade a mais fora do @verbatim — senão aprovaria um SW que ninguém recebe', () => {
        const comInterpolacao = blade.replace('@verbatim\n', '@verbatim\n@endverbatim\nconst DONO = {{ auth()->id() }};\n@verbatim\n');

        expect(() => extrairCodigo(comInterpolacao, 'x')).toThrow(/@verbatim/);
    });

    it('recusa a linha da versão trocada por outra coisa', () => {
        const outraLinha = blade.replace('const VERSAO = @json($versao);', 'const VERSAO = {{ $versao }};');

        expect(() => extrairCodigo(outraLinha, 'x')).toThrow(/@verbatim/);
    });
});
