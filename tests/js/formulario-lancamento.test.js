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
