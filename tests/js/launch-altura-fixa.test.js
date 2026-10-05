import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Modal "Lançar" com a MESMA altura nos três tipos (out/2026).
 *
 * Em receita a linha do saldo sumia (`hidden`) e em despesa/transferência voltava: o modal
 * crescia e encolhia a cada troca de tipo, e a barra de rolagem ia e vinha. Agora, em receita,
 * a linha fica com o espaço reservado (`.reservado` = visibility: hidden). E o rótulo do autor
 * acompanha o tipo: "Quem recebeu" / "Quem fez a compra" / "Quem fez a transferência".
 */

vi.mock('../../resources/js/sm/funding.js', () => ({ pedirFonte: vi.fn() }));

import { initLaunch } from '../../resources/js/sm/launch.js';

function montar() {
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
                        <option value="7" data-card="0" data-cash="1" data-saldo="R$ 10,00"
                                data-saldo-rotulo="disponível" data-negativo="0">Corrente (Nubank)</option>
                        <option value="8" data-card="0" data-cash="1" data-saldo="R$ 5,00"
                                data-saldo-rotulo="disponível" data-negativo="0">Poupança (Caixa)</option>
                    </select>
                    <span class="lm-saldo" data-lm-saldo hidden></span>
                    <div data-lm-transfer-only hidden><select id="lm-to-account" name="to_account_id" disabled>
                        <option value="7">Corrente (Nubank)</option><option value="8">Poupança (Caixa)</option>
                    </select></div>
                    <select id="lm-category" name="category_id"><option value="">—</option></select>
                    <label for="lm-author" data-lm-author-label>Quem recebeu</label>
                    <select id="lm-author" name="made_by_user_id"><option value="1">Victor</option><option value="2" selected>Maria</option></select>
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

const saldo = () => document.querySelector('[data-lm-saldo]');
const rotuloAutor = () => document.querySelector('[data-lm-author-label]').textContent;

describe('modal Lançar com altura fixa', () => {
    beforeEach(montar);

    it('em RECEITA a linha do saldo fica com o espaço reservado, sem sumir', () => {
        escolher('income');

        expect(saldo().hidden).toBe(false);
        expect(saldo().classList.contains('reservado')).toBe(true);
        expect(saldo().textContent).toBe('');
        expect(rotuloAutor()).toBe('Quem recebeu');
    });

    it('em DESPESA e TRANSFERÊNCIA a linha mostra o saldo, e o rótulo do autor acompanha', () => {
        escolher('expense');
        expect(saldo().classList.contains('reservado')).toBe(false);
        expect(saldo().textContent).toBe('R$ 10,00 disponível');
        expect(rotuloAutor()).toBe('Quem fez a compra');

        escolher('transfer');
        expect(saldo().hidden).toBe(false);
        expect(saldo().classList.contains('reservado')).toBe(false);
        expect(rotuloAutor()).toBe('Quem fez a transferência');

        escolher('income');
        expect(saldo().classList.contains('reservado')).toBe(true);
    });

    it('abre com quem está logado como autor (o selected do servidor), não a primeira pessoa', () => {
        expect(document.getElementById('lm-author').value).toBe('2');
    });
});
