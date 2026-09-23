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
 * O DOM imita o `categories/index.blade.php`: duas colunas (`.cat-drop`), chips com os
 * `data-*` que o módulo lê e o modal compartilhado de criar/editar.
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
        </div>`;
}

function coluna(tipo, chips) {
    return `
        <div class="cat-col">
            <span class="cch-count" data-count-for="${tipo}">${chips.length}</span>
            <div class="cat-drop" data-type="${tipo}">
                ${chips.join('')}
                <div class="cat-drop-empty">Nenhuma categoria ainda.</div>
                <div class="cat-drop-hint">Solte aqui</div>
            </div>
        </div>`;
}

function montarPagina() {
    document.head.innerHTML = '<meta name="csrf-token" content="token-da-sessao">';
    document.body.innerHTML = `
        <div class="cat-cols" id="catCols" data-ordenar-url="/categories/ordenar">
            ${coluna('income', [chip(1, 'Salário', 'income')])}
            ${coluna('expense', [chip(2, 'Mercado', 'expense'), chip(3, 'Lazer', 'expense')])}
        </div>
        <div class="modal-scrim" id="catModal">
            <div class="modal modal-lg" data-type="income">
                <h3 data-cat-title>Nova categoria</h3>
                <p data-cat-sub></p>
                <button class="modal-x" type="button" data-cat-close>Fechar</button>
                <div class="flash-error" data-cat-error role="alert" hidden><span data-cat-error-msg></span></div>
                <form method="POST" action="/categories" data-cat-form data-type="income">
                    <input type="hidden" name="_token" value="token-da-sessao">
                    <span class="hint" data-cat-locked-hint hidden></span>
                    <input type="radio" id="cm-tt-income" name="type" value="income" checked>
                    <input type="radio" id="cm-tt-expense" name="type" value="expense">
                    <input class="input" type="text" id="cm-name" name="name">
                    <div class="icon-picker" data-cat-icons><input type="radio" name="icon" value="✨" checked></div>
                    <div class="color-picker" data-cat-colors><input type="radio" name="color" value="" checked></div>
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
