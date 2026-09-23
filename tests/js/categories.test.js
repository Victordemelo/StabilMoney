import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { flush } from './helpers/flush.js';

import { initCategories } from '../../resources/js/sm/categories.js';

/**
 * Página de categorias (`resources/js/sm/categories.js`) — o que o usuário vê quando o
 * servidor RECUSA uma troca de tipo.
 *
 * Arrastar uma categoria para a outra coluna troca o tipo dela (PATCH categories.update).
 * Categoria com lançamentos não pode trocar — os lançamentos ficariam presos a uma
 * categoria do tipo oposto —, e o servidor responde 422 explicando o porquê e a saída
 * ("crie uma categoria nova em Receitas"). O arraste mostrava só "Não foi possível mover a
 * categoria. Tente novamente.": a pessoa tentava de novo algo que nunca ia passar, sem
 * saber por quê. Aqui o `fetch` é mockado e o que se observa é o aviso, o rollback do chip
 * e quais requisições saíram.
 *
 * Também cobre a reordenação SEM arrastar (botões ▲▼, achado A-4 da auditoria de
 * acessibilidade) e o modal como diálogo (A-2) — ver os dois últimos `describe`.
 *
 * O DOM imita o `categories/index.blade.php`: duas colunas (`.cat-drop`) com o cabeçalho
 * de onde sai o nome da coluna, chips com os `data-*` e os botões de mover que o módulo
 * lê, a região de anúncio (`#catAnuncio`) e o modal compartilhado de criar/editar.
 */

const MSG_DO_SERVIDOR =
    '"Mercado" não pode virar receita: tem 2 lançamentos de despesa, e eles ficariam numa categoria do tipo errado. '
    + 'Para lançar receitas, crie uma categoria nova em Receitas.';

/** Envelope mínimo de `Response` que o módulo consome. */
function resposta(status, corpo = {}) {
    return { status, ok: status >= 200 && status < 300, json: async () => corpo };
}

/** O 422 que o CategoryController devolve (ValidationException no campo `type`). */
function recusaDeTipo() {
    return resposta(422, { message: MSG_DO_SERVIDOR, errors: { type: [MSG_DO_SERVIDOR] } });
}

function chip(id, nome, tipo) {
    return `
        <div class="cat-chip" draggable="true" data-id="${id}" data-name="${nome}" data-color="#0F6B47"
             data-icon="🛒" data-type="${tipo}" data-locked="0" data-update-url="/categories/${id}">
            <span class="cc-name">${nome}</span>
            <a class="cc-act" href="/categories/${id}/edit" data-cat-open="edit">Editar</a>
            <span class="cc-mover">
                <button type="button" class="cc-mv" data-cat-mover="-1" aria-label="Mover ${nome} para cima">▲</button>
                <button type="button" class="cc-mv" data-cat-mover="1" aria-label="Mover ${nome} para baixo">▼</button>
            </span>
        </div>`;
}

function coluna(tipo, chips) {
    return `
        <div class="cat-col">
            <div class="cat-col-head">
                <h3>${tipo === 'income' ? 'Receitas' : 'Despesas'}</h3>
                <span class="cch-count" data-count-for="${tipo}">${chips.length}</span>
            </div>
            <div class="cat-drop" data-type="${tipo}">
                ${chips.join('')}
                <div class="cat-drop-empty">Nenhuma categoria ainda.</div>
                <div class="cat-drop-hint">Solte aqui</div>
            </div>
        </div>`;
}

const DESPESAS_PADRAO = [[2, 'Mercado'], [3, 'Lazer']];

function montarPagina(despesas = DESPESAS_PADRAO) {
    document.head.innerHTML = '<meta name="csrf-token" content="token-da-sessao">';
    document.body.innerHTML = `
        <button type="button" id="foraDoModal">Algo da página</button>
        <p class="sr-only" id="catAnuncio" role="status"></p>
        <div class="cat-cols" id="catCols" data-ordenar-url="/categories/ordenar">
            ${coluna('income', [chip(1, 'Salário', 'income')])}
            ${coluna('expense', despesas.map(([id, nome]) => chip(id, nome, 'expense')))}
        </div>
        <div class="modal-scrim" id="catModal">
            <div class="modal modal-lg" data-type="income" role="dialog" aria-modal="true" aria-labelledby="catModal-titulo">
                <h3 id="catModal-titulo" data-cat-title>Nova categoria</h3>
                <p data-cat-sub></p>
                <button class="modal-x" type="button" data-cat-close>Fechar</button>
                <div class="flash-error" data-cat-error role="alert" hidden><span data-cat-error-msg></span></div>
                <form method="POST" action="/categories" data-cat-form data-type="income">
                    <input type="hidden" name="_token" value="token-da-sessao">
                    <div class="modal-body">
                        <span class="hint" data-cat-locked-hint hidden></span>
                        <input type="radio" id="cm-tt-income" name="type" value="income" checked>
                        <input type="radio" id="cm-tt-expense" name="type" value="expense">
                        <input class="input" type="text" id="cm-name" name="name">
                        <div class="icon-picker" data-cat-icons><input type="radio" name="icon" value="✨" checked></div>
                        <div class="color-picker" data-cat-colors><input type="radio" name="color" value="" checked></div>
                    </div>
                    <button type="submit" data-cat-save>Salvar</button>
                </form>
            </div>
        </div>`;

    initCategories();
}

const drop = (tipo) => document.querySelector(`.cat-drop[data-type="${tipo}"]`);
const chipDe = (id) => document.querySelector(`.cat-chip[data-id="${id}"]`);
const contagem = (tipo) => document.querySelector(`.cch-count[data-count-for="${tipo}"]`).textContent;

/**
 * Evento de arraste como o jsdom aceita: ele não tem `DragEvent`/`DataTransfer`, então o
 * `dataTransfer` é um dublê com o que o módulo toca. `clientY` 0 solta o chip no fim da
 * coluna (o jsdom não calcula layout, todo retângulo mede zero).
 */
function eventoDeArraste(tipo) {
    const e = new Event(tipo, { bubbles: true, cancelable: true });
    Object.defineProperty(e, 'dataTransfer', { value: { effectAllowed: '', dropEffect: '', setData() {} } });
    Object.defineProperty(e, 'clientY', { value: 0 });
    return e;
}

/** A sequência que o navegador dispara ao arrastar e soltar noutra coluna. */
async function arrastar(elemento, destino) {
    elemento.dispatchEvent(eventoDeArraste('dragstart'));
    destino.dispatchEvent(eventoDeArraste('dragover'));
    destino.dispatchEvent(eventoDeArraste('drop'));
    elemento.dispatchEvent(eventoDeArraste('dragend'));
    await flush();
}

let respostas;
let chamadas;

beforeEach(() => {
    respostas = [];
    chamadas = [];

    global.fetch = vi.fn(async (url, init = {}) => {
        chamadas.push({ url: String(url), method: init.method, body: init.body });
        const proxima = respostas.shift();
        if (proxima instanceof Error) throw proxima;
        return proxima || resposta(500);
    });

    vi.spyOn(window, 'alert').mockImplementation(() => {});
    window.smPjaxReload = vi.fn();

    montarPagina();
});

afterEach(() => {
    vi.restoreAllMocks();
    delete window.smPjaxReload;
});

describe('arrastar para a outra coluna', () => {
    it('recusado com 422: o aviso mostra a mensagem do servidor e o chip volta para onde estava', async () => {
        respostas = [recusaDeTipo()];

        await arrastar(chipDe(2), drop('income'));

        expect(window.alert).toHaveBeenCalledTimes(1);
        expect(window.alert).toHaveBeenCalledWith(MSG_DO_SERVIDOR);

        // Rollback: mesma coluna, mesma posição (antes de "Lazer"), mesmo tipo.
        expect(chipDe(2).parentElement).toBe(drop('expense'));
        expect(chipDe(2).nextElementSibling).toBe(chipDe(3));
        expect(chipDe(2).dataset.type).toBe('expense');
        expect(contagem('income')).toBe('1');
        expect(contagem('expense')).toBe('2');

        // Só o PATCH do tipo saiu — nada de gravar a ordem de uma troca recusada.
        expect(chamadas).toHaveLength(1);
        expect(chamadas[0]).toMatchObject({ url: '/categories/2', method: 'PATCH' });
        expect(JSON.parse(chamadas[0].body)).toMatchObject({ name: 'Mercado', type: 'income' });
    });

    it('sem conexão: avisa que o servidor não respondeu, sem fingir outra causa', async () => {
        respostas = [new TypeError('Failed to fetch')];

        await arrastar(chipDe(2), drop('income'));

        expect(window.alert).toHaveBeenCalledWith('Sem conexão com o servidor. Tente de novo em instantes.');
        expect(chipDe(2).parentElement).toBe(drop('expense'));
    });

    it('recusa sem mensagem (500): cai no aviso genérico e desfaz', async () => {
        respostas = [resposta(500)];

        await arrastar(chipDe(2), drop('income'));

        expect(window.alert).toHaveBeenCalledWith('Não foi possível mover a categoria. Tente novamente.');
        expect(chipDe(2).parentElement).toBe(drop('expense'));
    });

    it('aceito: o chip fica na coluna nova e a ordem dela é gravada', async () => {
        respostas = [resposta(200, { ok: true }), resposta(200, { ok: true })];

        await arrastar(chipDe(2), drop('income'));

        expect(window.alert).not.toHaveBeenCalled();
        expect(chipDe(2).parentElement).toBe(drop('income'));
        expect(chipDe(2).dataset.type).toBe('income');
        expect(chamadas.map((c) => c.url)).toEqual(['/categories/2', '/categories/ordenar']);
        expect(JSON.parse(chamadas[1].body)).toEqual({ ids: [1, 2] });
    });
});

describe('modal de editar', () => {
    it('trocar o tipo de categoria com lançamentos: o banner mostra a mensagem do servidor', async () => {
        respostas = [recusaDeTipo()];

        chipDe(2).querySelector('[data-cat-open="edit"]').click();
        const receita = document.getElementById('cm-tt-income');
        receita.checked = true;
        receita.dispatchEvent(new Event('change', { bubbles: true }));

        document.querySelector('[data-cat-form]').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await flush();

        expect(chamadas).toHaveLength(1);
        expect(chamadas[0]).toMatchObject({ url: '/categories/2', method: 'POST' });
        expect(chamadas[0].body.get('_method')).toBe('PUT');
        expect(chamadas[0].body.get('type')).toBe('income');

        expect(document.querySelector('[data-cat-error]').hidden).toBe(false);
        expect(document.querySelector('[data-cat-error-msg]').textContent).toBe(MSG_DO_SERVIDOR);
        // Continua aberto: a pessoa lê o motivo e decide (fechar ou voltar o tipo).
        expect(document.getElementById('catModal').classList.contains('open')).toBe(true);
    });
});

/**
 * Reordenar SEM arrastar — achado A-4 da auditoria de acessibilidade: o chip era
 * `draggable`, e arrastar era o ÚNICO jeito de mudar a ordem. Quem usa teclado, leitor de
 * tela ou um dedo que não segura o arraste não reordenava nada. Os botões ▲▼ de cada chip
 * gravam no MESMO PATCH categories.ordenar e anunciam a posição nova.
 */
describe('reordenar pelos botões ▲▼ (sem arrastar)', () => {
    const botao = (id, direcao) => chipDe(id).querySelector(`[data-cat-mover="${direcao}"]`);
    const ordem = (tipo) => Array.from(drop(tipo).querySelectorAll('.cat-chip')).map((c) => Number(c.dataset.id));
    const anuncio = () => document.getElementById('catAnuncio').textContent;

    it('as pontas nascem aria-disabled: o primeiro não sobe, o último não desce', () => {
        expect(botao(2, -1).getAttribute('aria-disabled')).toBe('true');
        expect(botao(2, 1).hasAttribute('aria-disabled')).toBe(false);
        expect(botao(3, -1).hasAttribute('aria-disabled')).toBe(false);
        expect(botao(3, 1).getAttribute('aria-disabled')).toBe('true');
        // Chip sozinho na coluna: as duas pontas.
        expect(botao(1, -1).getAttribute('aria-disabled')).toBe('true');
        expect(botao(1, 1).getAttribute('aria-disabled')).toBe('true');
    });

    it('▼ troca com o de baixo, grava a ordem nova e anuncia a posição', async () => {
        respostas = [resposta(200, { ok: true })];

        botao(2, 1).click();
        await flush();

        expect(ordem('expense')).toEqual([3, 2]);
        expect(chamadas).toHaveLength(1);
        expect(chamadas[0]).toMatchObject({ url: '/categories/ordenar', method: 'PATCH' });
        expect(JSON.parse(chamadas[0].body)).toEqual({ ids: [3, 2] });
        expect(anuncio()).toBe('Mercado: posição 2 de 2 em Despesas.');
        expect(window.alert).not.toHaveBeenCalled();
    });

    it('as pontas se recalculam depois de mover', async () => {
        respostas = [resposta(200, { ok: true })];

        botao(2, 1).click();
        await flush();

        expect(botao(3, -1).getAttribute('aria-disabled')).toBe('true');
        expect(botao(2, 1).getAttribute('aria-disabled')).toBe('true');
        expect(botao(2, -1).hasAttribute('aria-disabled')).toBe(false);
    });

    it('o foco fica no botão apertado: dá para apertar de novo sem caçar o botão', async () => {
        respostas = [resposta(200, { ok: true })];
        botao(3, -1).focus();

        botao(3, -1).click();
        await flush();

        expect(ordem('expense')).toEqual([3, 2]);
        expect(document.activeElement).toBe(botao(3, -1));
    });

    it('▲ no topo não chama o servidor, e quem apertou ouve por quê', async () => {
        botao(2, -1).click();
        await flush();

        expect(ordem('expense')).toEqual([2, 3]);
        expect(chamadas).toHaveLength(0);
        expect(anuncio()).toBe('Mercado já está no topo de Despesas.');
    });

    it('recusado pelo servidor: a coluna volta à ordem gravada e avisa', async () => {
        respostas = [resposta(500)];

        botao(2, 1).click();
        await flush();

        expect(ordem('expense')).toEqual([2, 3]);
        expect(window.alert).toHaveBeenCalledWith('Não foi possível salvar a nova ordem. Tente novamente.');
        expect(botao(2, -1).getAttribute('aria-disabled')).toBe('true');
    });

    it('sem conexão: também volta e avisa', async () => {
        respostas = [new TypeError('Failed to fetch')];

        botao(3, -1).click();
        await flush();

        expect(ordem('expense')).toEqual([2, 3]);
        expect(window.alert).toHaveBeenCalledTimes(1);
    });

    it('cliques rápidos: um PATCH por vez, e o seguinte leva a ordem MAIS NOVA', async () => {
        montarPagina([[2, 'Mercado'], [3, 'Lazer'], [4, 'Transporte']]);

        // O servidor só responde quando o teste mandar: é o que mostra se o segundo
        // PATCH espera o primeiro ou sai por cima dele.
        const pendentes = [];
        global.fetch = vi.fn((url, init = {}) => {
            chamadas.push({ url: String(url), method: init.method, body: init.body });
            return new Promise((resolve) => pendentes.push(resolve));
        });

        botao(2, 1).click();
        await flush();
        expect(chamadas).toHaveLength(1);
        expect(JSON.parse(chamadas[0].body)).toEqual({ ids: [3, 2, 4] });

        botao(2, 1).click();
        await flush();
        // Com o primeiro ainda sem resposta, o segundo espera na fila.
        expect(ordem('expense')).toEqual([3, 4, 2]);
        expect(chamadas).toHaveLength(1);

        pendentes[0](resposta(200, { ok: true }));
        await flush();

        expect(chamadas).toHaveLength(2);
        expect(JSON.parse(chamadas[1].body)).toEqual({ ids: [3, 4, 2] });

        pendentes[1](resposta(200, { ok: true }));
        await flush();
        expect(window.alert).not.toHaveBeenCalled();
    });

    it('arrastar também anuncia onde a categoria foi parar', async () => {
        respostas = [resposta(200, { ok: true }), resposta(200, { ok: true })];

        await arrastar(chipDe(2), drop('income'));

        expect(anuncio()).toBe('Mercado: posição 2 de 2 em Receitas.');
        // E a coluna de destino ganhou pontas novas.
        expect(botao(1, 1).hasAttribute('aria-disabled')).toBe(false);
        expect(botao(2, 1).getAttribute('aria-disabled')).toBe('true');
    });
});

describe('o modal de categoria é um diálogo (A-2)', () => {
    const tecla = (key) => document.activeElement.dispatchEvent(
        new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }),
    );

    it('o lápis abre com foco no Nome e o resto inerte; o Esc fecha e devolve o foco ao lápis', () => {
        const lapis = chipDe(2).querySelector('[data-cat-open="edit"]');
        lapis.focus();

        lapis.click();

        const modal = document.getElementById('catModal');
        expect(modal.classList.contains('open')).toBe(true);
        expect(document.activeElement).toBe(document.getElementById('cm-name'));
        expect(document.getElementById('catCols').hasAttribute('inert')).toBe(true);
        expect(document.getElementById('foraDoModal').hasAttribute('inert')).toBe(true);

        tecla('Escape');

        expect(modal.classList.contains('open')).toBe(false);
        expect(document.activeElement).toBe(lapis);
        expect(document.querySelectorAll('[inert]')).toHaveLength(0);
    });
});
