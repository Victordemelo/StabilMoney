import { afterEach, describe, expect, it } from 'vitest';
import { initEdicaoTravada } from '../../resources/js/sm/edicao-travada.js';

/**
 * Editar movimentação com os campos travados (13/10/2026 — pedido do Victor): só o "Editar
 * movimentação" libera os campos e mostra Salvar/Cancelar; o Cancelar desfaz e trava de novo.
 */

const montar = (liberado = false) => {
    document.body.innerHTML = `
        <form data-tx-travavel ${liberado ? 'data-tx-liberado' : ''}>
            <input type="hidden" name="_token" value="x">
            <input type="hidden" name="type" value="expense">
            <input class="input" id="amount" name="amount" value="10,00" readonly>
            <input class="input" id="description" name="description" value="Mercado">
            <select class="input" id="category_id" name="category_id"><option value="1" selected>A</option><option value="2">B</option></select>
            <div data-tx-acoes><a href="/transactions" data-tx-cancelar>Cancelar</a><button type="submit">Salvar</button></div>
        </form>
        <section data-tx-editar hidden><button type="button" data-tx-liberar>Editar movimentação</button></section>`;
    initEdicaoTravada(document);
    return document.querySelector('form');
};

afterEach(() => { document.body.innerHTML = ''; });

describe('Edição travada', () => {
    it('abre travada: campos desabilitados, Salvar escondido, o Editar à vista', () => {
        const form = montar();
        expect(form.querySelector('#description').disabled).toBe(true);
        expect(form.querySelector('#category_id').disabled).toBe(true);
        expect(form.querySelector('[data-tx-acoes]').hidden).toBe(true);
        expect(document.querySelector('[data-tx-editar]').hidden).toBe(false);
        // O que vai escondido continua indo (token, tipo).
        expect(form.querySelector('[name="_token"]').disabled).toBe(false);
    });

    it('o Editar libera os campos, mostra Salvar e põe o foco no primeiro editável', () => {
        const form = montar();
        document.querySelector('[data-tx-liberar]').click();
        expect(form.querySelector('#description').disabled).toBe(false);
        expect(form.querySelector('#amount').readOnly).toBe(true);
        expect(form.querySelector('[data-tx-acoes]').hidden).toBe(false);
        expect(document.querySelector('[data-tx-editar]').hidden).toBe(true);
        expect(document.activeElement.id).toBe('description');
    });

    it('o Cancelar desfaz o digitado e trava de novo', () => {
        const form = montar();
        document.querySelector('[data-tx-liberar]').click();
        form.querySelector('#description').value = 'Outra coisa';
        form.querySelector('[data-tx-cancelar]').click();
        expect(form.querySelector('#description').value).toBe('Mercado');
        expect(form.querySelector('#description').disabled).toBe(true);
        expect(form.querySelector('[data-tx-acoes]').hidden).toBe(true);
    });

    it('com erro de validação (o servidor marca), já abre liberada', () => {
        const form = montar(true);
        expect(form.querySelector('#description').disabled).toBe(false);
        expect(document.querySelector('[data-tx-editar]').hidden).toBe(true);
    });
});
