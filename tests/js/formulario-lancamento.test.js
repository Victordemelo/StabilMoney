import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { beforeEach, describe, expect, it } from 'vitest';

/**
 * Página cheia de lançamento (`transactions/_form.blade.php`): o script inline que filtra o
 * select "Onde" pelo tipo (receita/transferência só em conta de banco; despesa só por um
 * método). Executa o script REAL, lido da view — ele não tem Blade dentro.
 *
 * Achado do e2e (out/2026): quem só tem conta corrente abre a página em DESPESA, onde nenhuma
 * opção vale, e o select fica sem escolha. Ao trocar para RECEITA a conta voltava a valer, mas
 * continuava sem seleção — e o `required` segurava o envio sem dizer por quê.
 */

const aqui = dirname(fileURLToPath(import.meta.url));
const view = readFileSync(resolve(aqui, '../../resources/views/transactions/_form.blade.php'), 'utf8');
const script = view.split('<script nonce="{{ Vite::cspNonce() }}">')[1].split('</script>')[0];

function montar(opcoes) {
    document.body.innerHTML = `
        <form data-tx-form>
            <input type="radio" id="tt-income" name="type" value="income">
            <input type="radio" id="tt-expense" name="type" value="expense" checked>
            <select id="account_id" name="account_id" required>${opcoes}</select>
            <select id="category_id" name="category_id"><option value="">Selecione</option></select>
        </form>`;
    new Function(script)();
}

const conta = () => document.getElementById('account_id');
function escolher(tipo) {
    const radio = document.getElementById(`tt-${tipo}`);
    radio.checked = true;
    radio.dispatchEvent(new Event('change', { bubbles: true }));
}

describe('select "Onde" da página cheia', () => {
    describe('só com conta corrente', () => {
        beforeEach(() => montar(`
            <optgroup label="Contas de banco" data-para="income transfer">
                <option value="9" data-para="income transfer">Conta Smoke</option>
            </optgroup>`));

        it('em despesa nada vale e o select fica vazio', () => {
            expect(conta().selectedIndex).toBe(-1);
        });

        it('trocando para receita, a conta volta a valer E fica escolhida', () => {
            escolher('income');

            expect(conta().value).toBe('9');
            expect(conta().selectedOptions[0].disabled).toBe(false);
            expect(conta().checkValidity()).toBe(true);
        });
    });

    it('com conta e cartão: voltar para despesa troca a escolha para o método', () => {
        montar(`
            <optgroup label="Contas de banco" data-para="income transfer">
                <option value="9" data-para="income transfer">Corrente</option>
            </optgroup>
            <optgroup label="Cartões de crédito" data-para="expense">
                <option value="12" data-para="expense">Roxinho</option>
            </optgroup>`);

        expect(conta().value).toBe('12');
        escolher('income');
        expect(conta().value).toBe('9');
        escolher('expense');
        expect(conta().value).toBe('12');
    });
});

describe('parcelas na página cheia (out/2026)', () => {
    function montarComParcelas() {
        const opcoes = Array.from({ length: 24 }, (_, i) => `<option value="${i + 1}">${i + 1}x</option>`).join('');
        document.body.innerHTML = `
            <form data-tx-form>
                <input type="radio" id="tt-income" name="type" value="income">
                <input type="radio" id="tt-expense" name="type" value="expense" checked>
                <label for="amount" data-tx-valor-rotulo>Valor (R$)</label>
                <div class="lm-valor" data-tx-valor>
                    <input type="text" id="amount" name="amount" value="500,00">
                    <select id="installments" name="installments" data-tx-parcelas hidden disabled>${opcoes}</select>
                </div>
                <select id="account_id" name="account_id" required>
                    <optgroup label="Contas correntes" data-para="income transfer">
                        <option value="9" data-para="income transfer" data-card="0">Corrente</option>
                    </optgroup>
                    <optgroup label="Cartões de crédito" data-para="expense">
                        <option value="12" data-para="expense" data-card="1">Roxinho</option>
                    </optgroup>
                    <optgroup label="Pix e TED" data-para="expense">
                        <option value="9" data-para="expense" data-card="0">Pix → Corrente</option>
                    </optgroup>
                </select>
                <select id="category_id" name="category_id"><option value="">Selecione</option></select>
            </form>`;
        new Function(script)();
    }
    const parcelas = () => document.getElementById('installments');

    it('despesa no cartão mostra as parcelas com o valor de cada uma', () => {
        montarComParcelas();
        expect(parcelas().hidden).toBe(false);
        expect(parcelas().disabled).toBe(false);
        parcelas().value = '12';
        parcelas().dispatchEvent(new Event('change'));
        expect(parcelas().selectedOptions[0].textContent).toBe('12x de R$ 41,67');
        expect(document.querySelector('[data-tx-valor-rotulo]').textContent).toBe('Valor total (R$)');
        expect(new FormData(document.querySelector('form')).get('installments')).toBe('12');
    });

    it('no Pix ou em receita as parcelas somem e não vão no envio', () => {
        montarComParcelas();
        const sel = document.getElementById('account_id');
        [...sel.options].find((o) => o.textContent === 'Pix → Corrente').selected = true;
        sel.dispatchEvent(new Event('change'));
        expect(parcelas().hidden).toBe(true);
        expect(new FormData(document.querySelector('form')).has('installments')).toBe(false);

        escolher('income');
        expect(parcelas().hidden).toBe(true);
        expect(parcelas().value).toBe('1');
    });
});
