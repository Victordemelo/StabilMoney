import { describe, expect, it, vi } from 'vitest';

/**
 * Parcelas no modal "Lançar" (out/2026): com cartão de crédito numa despesa aparece o select
 * de parcelas ao lado do valor (1x a 24x, "12x de R$ 41,67"); fora disso ele some e não viaja.
 */

vi.mock('../../resources/js/sm/funding.js', () => ({ pedirFonte: vi.fn() }));

import { initLaunch, textoDaParcela } from '../../resources/js/sm/launch.js';

function montar() {
    document.head.innerHTML = '<meta name="csrf-token" content="t">';
    const opcoes = Array.from({ length: 24 }, (_, i) => `<option value="${i + 1}">${i + 1}x</option>`).join('');
    document.body.innerHTML = `
        <button type="button" data-launch-open>Lançar</button>
        <div class="modal-scrim" id="launchModal">
            <div class="modal">
                <div class="flash-error" data-lm-error hidden><span data-lm-error-msg></span></div>
                <form action="/transactions" data-launch-form data-type="income"
                      data-store-action="/transactions" data-transfer-action="/transactions/transferir">
                    <input type="radio" id="lm-tt-income" name="type" value="income" checked>
                    <input type="radio" id="lm-tt-expense" name="type" value="expense">
                    <label for="lm-amount" data-lm-valor-rotulo>Valor (R$)</label>
                    <div class="lm-valor" data-lm-valor>
                        <input type="text" inputmode="decimal" id="lm-amount" name="amount">
                        <select id="lm-installments" name="installments" data-lm-parcelas hidden disabled>${opcoes}</select>
                    </div>
                    <input type="date" id="lm-date" name="date" value="2026-10-04">
                    <select id="lm-account" name="account_id">
                        <optgroup label="Contas correntes" data-para="income transfer">
                            <option value="7" data-para="income transfer" data-card="0" data-cash="1">Inter</option>
                        </optgroup>
                        <optgroup label="Cartões de crédito" data-para="expense">
                            <option value="9" data-para="expense" data-card="1">Crédito Itaú</option>
                        </optgroup>
                        <optgroup label="Pix e TED" data-para="expense">
                            <option value="7" data-para="expense" data-card="0">Pix → Inter</option>
                        </optgroup>
                    </select>
                    <span class="lm-saldo" data-lm-saldo hidden></span>
                    <select id="lm-category" name="category_id"><option value="">—</option></select>
                    <input type="text" id="lm-description" name="description">
                    <button type="button" data-close-btn>Cancelar</button>
                    <button type="submit" data-lm-save><span class="btn-label">Salvar</span></button>
                </form>
            </div>
        </div>`;
    initLaunch();
    document.querySelector('[data-launch-open]').click();
}

const escolherTipo = (tipo) => {
    const r = document.getElementById(`lm-tt-${tipo}`);
    r.checked = true;
    r.dispatchEvent(new Event('change', { bubbles: true }));
};
const escolherConta = (texto) => {
    const sel = document.getElementById('lm-account');
    [...sel.options].find((o) => o.textContent === texto).selected = true;
    sel.dispatchEvent(new Event('change', { bubbles: true }));
};
const parcelas = () => document.getElementById('lm-installments');
const digitar = (v) => {
    const c = document.getElementById('lm-amount');
    c.value = v;
    c.dispatchEvent(new Event('input', { bubbles: true }));
};

describe('Parcelas no modal Lançar', () => {
    it('o texto da opção mostra o valor de cada parcela', () => {
        expect(textoDaParcela(1, 50000)).toBe('À vista');
        expect(textoDaParcela(12, 50000)).toBe('12x de R$ 41,67');
        expect(textoDaParcela(3, 30000)).toBe('3x de R$ 100,00');
        expect(textoDaParcela(24, 0)).toBe('24x');
    });

    it('em receita o select some e fica fora do envio', () => {
        montar();
        expect(parcelas().hidden).toBe(true);
        expect(parcelas().disabled).toBe(true);
        expect(new FormData(document.querySelector('form')).has('installments')).toBe(false);
    });

    it('despesa no cartão de crédito mostra de 1x a 24x; no Pix, some', () => {
        montar();
        escolherTipo('expense');
        escolherConta('Crédito Itaú');
        expect(parcelas().hidden).toBe(false);
        expect(parcelas().options).toHaveLength(24);
        expect(document.querySelector('[data-lm-valor]').classList.contains('com-parcelas')).toBe(true);

        escolherConta('Pix → Inter');
        expect(parcelas().hidden).toBe(true);
        expect(parcelas().value).toBe('1');
    });

    it('o valor digitado atualiza as opções, e parcelado o rótulo vira "Valor total"', () => {
        montar();
        escolherTipo('expense');
        escolherConta('Crédito Itaú');
        digitar('50000'); // R$ 500,00 (a máscara entra pelos centavos)
        const sel = parcelas();
        sel.value = '12';
        sel.dispatchEvent(new Event('change'));

        expect(sel.selectedOptions[0].textContent).toMatch(/^12x de R\$ \d/);
        expect(document.querySelector('[data-lm-valor-rotulo]').textContent).toBe('Valor total (R$)');
        expect(new FormData(document.querySelector('form')).get('installments')).toBe('12');
    });
});
