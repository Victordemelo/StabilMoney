import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Modal "Lançar": cada tipo de lançamento só enxerga os métodos que aceita (out/2026 —
 * regra do Victor; o agrupamento sai do `Account::gruposDeLancamento`).
 *
 * - RECEITA e TRANSFERÊNCIA: só conta de banco (corrente/poupança);
 * - DESPESA: só por método — cartão de crédito, de débito, Pix ou TED.
 *
 * Pix e débito submetem o id da conta que espelham, então o MESMO valor aparece em mais de
 * uma opção: trocar a escolha por `select.value` marcaria a primeira (a da conta de banco,
 * desabilitada em despesa). O teste prende isso.
 */

vi.mock('../../resources/js/sm/funding.js', () => ({ pedirFonte: vi.fn() }));

import { initLaunch } from '../../resources/js/sm/launch.js';

function montar({ semMetodo = false, semCredito = false } = {}) {
    const credito = semCredito ? '' : `
        <optgroup label="Cartões de crédito" data-para="expense">
            <option value="9" data-para="expense" data-card="1" data-saldo="R$ 900,00" data-saldo-rotulo="limite livre" data-negativo="0">Roxinho (Crédito)</option>
        </optgroup>`;
    const metodos = semMetodo ? '' : `${credito}
        <optgroup label="Pix e TED" data-para="expense">
            <option value="7" data-para="expense" data-card="0" data-saldo="R$ 10,00" data-saldo-rotulo="disponível" data-negativo="0">Pix → Corrente</option>
        </optgroup>`;
    document.head.innerHTML = '<meta name="csrf-token" content="t">';
    document.body.innerHTML = `
        <button type="button" data-launch-open>Lançar</button>
        <div class="modal-scrim" id="launchModal">
            <div class="modal">
                <div class="flash-error" data-lm-error hidden><span data-lm-error-msg></span></div>
                <form action="/transactions" data-launch-form data-type="income"
                      data-store-action="/transactions" data-transfer-action="/transactions/transferir">
                    <input type="hidden" name="_token" value="t">
                    <input type="radio" id="lm-tt-income" name="type" value="income" checked>
                    <input type="radio" id="lm-tt-expense" name="type" value="expense">
                    <input type="radio" id="lm-tt-transfer" name="type" value="transfer">
                    <input type="text" inputmode="decimal" id="lm-amount" name="amount">
                    <input type="date" id="lm-date" name="date" value="2026-10-04">
                    <label for="lm-account" data-lm-account-label>Onde</label>
                    <select id="lm-account" name="account_id">
                        <optgroup label="Contas de banco" data-para="income transfer">
                            <option value="7" data-para="income transfer" data-card="0" data-cash="1" data-saldo="R$ 10,00" data-saldo-rotulo="disponível" data-negativo="0">Corrente (Nubank)</option>
                            <option value="8" data-para="income transfer" data-card="0" data-cash="1" data-saldo="R$ 5,00" data-saldo-rotulo="disponível" data-negativo="0">Poupança (Caixa)</option>
                        </optgroup>
                        ${metodos}
                    </select>
                    <span class="lm-saldo" data-lm-saldo hidden></span>
                    <div data-lm-transfer-only hidden><select id="lm-to-account" name="to_account_id" disabled>
                        <option value="7">Corrente (Nubank)</option><option value="8">Poupança (Caixa)</option>
                    </select></div>
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

function escolher(tipo) {
    const radio = document.getElementById(`lm-tt-${tipo}`);
    radio.checked = true;
    radio.dispatchEvent(new Event('change', { bubbles: true }));
}

const conta = () => document.getElementById('lm-account');
const habilitadas = () => Array.from(conta().options).filter((o) => !o.disabled).map((o) => o.textContent);
const grupoVisivel = (rotulo) => !conta().querySelector(`optgroup[label="${rotulo}"]`).hidden;

describe('modal Lançar: cada tipo só enxerga os métodos que aceita', () => {
    beforeEach(() => montar());

    it('RECEITA só entra em conta de banco', () => {
        escolher('income');

        expect(habilitadas()).toEqual(['Corrente (Nubank)', 'Poupança (Caixa)']);
        expect(grupoVisivel('Contas de banco')).toBe(true);
        expect(grupoVisivel('Pix e TED')).toBe(false);
        expect(document.querySelector('[data-lm-account-label]').textContent).toBe('Onde');
    });

    it('DESPESA só sai por um método, e a escolha cai na OPÇÃO do método (mesmo valor da conta)', () => {
        escolher('expense');

        expect(habilitadas()).toEqual(['Roxinho (Crédito)', 'Pix → Corrente']);
        expect(grupoVisivel('Contas de banco')).toBe(false);
        expect(conta().selectedOptions[0].disabled).toBe(false);
        expect(conta().selectedOptions[0].textContent).toBe('Roxinho (Crédito)');
        expect(document.querySelector('[data-lm-account-label]').textContent).toBe('Pagar com');

        // Pix → Corrente tem o mesmo value da Corrente: escolher por OPÇÃO mantém o Pix marcado.
        conta().options[3].selected = true;
        conta().dispatchEvent(new Event('change'));
        expect(conta().selectedOptions[0].textContent).toBe('Pix → Corrente');
        expect(conta().value).toBe('7');
    });

    it('TRANSFERÊNCIA só entre contas de banco', () => {
        escolher('expense');
        escolher('transfer');

        expect(habilitadas()).toEqual(['Corrente (Nubank)', 'Poupança (Caixa)']);
        expect(conta().selectedOptions[0].textContent).toBe('Corrente (Nubank)');
    });
});

describe('modal Lançar: o primeiro método tem o mesmo valor de uma conta de banco', () => {
    it('marca a opção do Pix, não a conta de banco desabilitada com o mesmo id', () => {
        montar({ semCredito: true });
        escolher('expense');

        expect(conta().selectedOptions[0].textContent).toBe('Pix → Corrente');
        expect(conta().selectedOptions[0].disabled).toBe(false);
    });
});

describe('modal Lançar: despesa sem método cadastrado', () => {
    it('explica o que falta em vez de deixar um select vazio sem motivo', () => {
        montar({ semMetodo: true });
        escolher('expense');

        expect(habilitadas()).toEqual([]);
        expect(document.querySelector('[data-lm-saldo]').textContent)
            .toBe('Para lançar uma despesa, cadastre um cartão, Pix ou TED em Contas e cartões.');
    });
});
