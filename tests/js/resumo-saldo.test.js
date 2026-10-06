import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Resumo do saldo nas janelas de pagamento (out/2026 — pedido do Victor): "mostrar o saldo
 * inicial, o desconto (o que a gente vai pagar ou enviar) e depois o subtotal".
 */

vi.mock('../../resources/js/sm/funding.js', () => ({ pedirFonte: vi.fn() }));

import { desenharResumo, formatarBrl, lerValorDoCampo } from '../../resources/js/sm/resumo-saldo.js';
import { initLaunch } from '../../resources/js/sm/launch.js';

const RESUMO = `
    <div class="resumo-saldo" data-resumo-saldo hidden>
        <span data-rs-rotulo-atual></span><b data-rs-atual></b>
        <span data-rs-operador></span>
        <span data-rs-rotulo-valor></span><b data-rs-valor></b>
        <span data-rs-rotulo-depois></span><b data-rs-depois></b>
        <p data-rs-aviso hidden></p>
    </div>`;

const texto = (nome) => document.querySelector(`[data-rs-${nome}]`).textContent;

describe('desenharResumo', () => {
    beforeEach(() => { document.body.innerHTML = RESUMO; });

    it('saldo atual − valor = saldo depois, no formato do app', () => {
        desenharResumo(document.querySelector('[data-resumo-saldo]'), { saldo: 1000, valor: 300.5, rotuloValor: 'Esta fatura' });

        expect(document.querySelector('[data-resumo-saldo]').hidden).toBe(false);
        expect(texto('rotulo-atual')).toBe('Saldo atual');
        expect(texto('atual')).toBe('R$ 1.000,00');
        expect(texto('operador')).toBe('−');
        expect(texto('rotulo-valor')).toBe('Esta fatura');
        expect(texto('valor')).toBe('R$ 300,50');
        expect(texto('depois')).toBe('R$ 699,50');
        expect(document.querySelector('[data-rs-aviso]').hidden).toBe(true);
    });

    it('ficando negativo, o depois sai em vermelho com o sinal antes do símbolo e um aviso', () => {
        desenharResumo(document.querySelector('[data-resumo-saldo]'), { saldo: 100, valor: 250 });

        expect(texto('depois')).toBe('−R$ 150,00');
        expect(document.querySelector('[data-rs-depois]').classList.contains('neg')).toBe(true);
        expect(document.querySelector('[data-rs-aviso]').hidden).toBe(false);
    });

    it('no cartão fala em limite livre; receita soma', () => {
        desenharResumo(document.querySelector('[data-resumo-saldo]'), { saldo: 2000, valor: 500, cartao: true });
        expect(texto('rotulo-atual')).toBe('Limite livre agora');
        expect(texto('rotulo-depois')).toBe('Limite livre depois');

        desenharResumo(document.querySelector('[data-resumo-saldo]'), { saldo: 10, valor: 0.1, sinal: 1 });
        expect(texto('operador')).toBe('+');
        expect(texto('depois')).toBe('R$ 10,10');
    });

    it('sem conta escolhida, o resumo some', () => {
        desenharResumo(document.querySelector('[data-resumo-saldo]'), { saldo: null, valor: 10 });
        expect(document.querySelector('[data-resumo-saldo]').hidden).toBe(true);
    });

    it('lê o campo mascarado como centavos e formata negativo como o @brl', () => {
        expect(lerValorDoCampo({ value: '1.234,56' })).toBe(1234.56);
        expect(lerValorDoCampo({ value: '' })).toBe(0);
        expect(formatarBrl(-1234.5)).toBe('−R$ 1.234,50');
    });
});

describe('modal Lançar com o resumo', () => {
    beforeEach(() => {
        document.head.innerHTML = '<meta name="csrf-token" content="t">';
        document.body.innerHTML = `
            <button type="button" data-launch-open>Lançar</button>
            <div class="modal-scrim" id="launchModal">
                <div class="modal">
                    <div class="flash-error" data-lm-error hidden><span data-lm-error-msg></span></div>
                    <form action="/transactions" data-launch-form data-type="income"
                          data-store-action="/transactions" data-transfer-action="/transactions/transferir">
                        <input type="radio" id="lm-tt-income" name="type" value="income" checked>
                        <input type="radio" id="lm-tt-expense" name="type" value="expense">
                        <input type="text" inputmode="decimal" id="lm-amount" name="amount">
                        <input type="date" id="lm-date" name="date" value="2026-10-04">
                        <label for="lm-account" data-lm-account-label>Onde</label>
                        <select id="lm-account" name="account_id">
                            <option value="7" data-card="0" data-cash="1" data-saldo="R$ 1.000,00" data-saldo-valor="1000.00"
                                    data-saldo-rotulo="disponível" data-negativo="0">Corrente</option>
                        </select>
                        <span class="lm-saldo" data-lm-saldo hidden></span>
                        <select id="lm-category" name="category_id"><option value="">—</option></select>
                        ${RESUMO}
                        <button type="button" data-close-btn>Cancelar</button>
                        <button type="submit" data-lm-save><span class="btn-label">Salvar</span></button>
                    </form>
                </div>
            </div>`;
        initLaunch();
        document.querySelector('[data-launch-open]').click();
    });

    const escolher = (tipo) => {
        const radio = document.getElementById(`lm-tt-${tipo}`);
        radio.checked = true;
        radio.dispatchEvent(new Event('change', { bubbles: true }));
    };
    const digitar = (valor) => {
        const campo = document.getElementById('lm-amount');
        campo.value = valor;
        campo.dispatchEvent(new Event('input', { bubbles: true }));
    };

    it('em despesa: saldo atual − valor digitado = depois, e a linha de saldo não repete o número', () => {
        escolher('expense');
        digitar('300,00');

        expect(texto('atual')).toBe('R$ 1.000,00');
        expect(texto('rotulo-valor')).toBe('Esta despesa');
        expect(texto('depois')).toBe('R$ 700,00');
        expect(document.querySelector('[data-lm-saldo]').hidden).toBe(true);
    });

    it('em receita: soma, e o resumo continua no lugar (a altura não muda)', () => {
        escolher('income');
        digitar('50,00');

        expect(document.querySelector('[data-resumo-saldo]').hidden).toBe(false);
        expect(texto('operador')).toBe('+');
        expect(texto('rotulo-valor')).toBe('Esta receita');
        expect(texto('depois')).toBe('R$ 1.050,00');
    });
});
