import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { initFunding, pedirFonte } from '../../resources/js/sm/funding.js';
import { liberarDialogosOrfaos } from '../../resources/js/sm/dialogo.js';

/**
 * Modal "De onde sai esse dinheiro?" (`resources/js/sm/funding.js`) — o que ele devolve ao
 * reenvio. O teto `funding_max_amount` é o faltante que a tela MOSTROU: o servidor recalcula
 * na hora de gravar e, se passar dele, pergunta de novo em vez de resgatar ou usar de cheque
 * especial mais do que a pessoa aprovou. Até 24/09/2026 só o resgate levava o teto; o cheque
 * especial seguia sem limite (TetoDoChequeEspecialAprovadoTest, no lado PHP).
 *
 * O DOM imita `partials/funding-modal.blade.php`.
 */

function montarModal() {
    document.body.innerHTML = `
        <div id="content"><button type="button" id="salvar">Salvar</button></div>
        <div class="modal-scrim" id="fundingModal">
            <div class="modal" role="dialog" aria-modal="true" aria-labelledby="fundingModal-titulo">
                <h3 id="fundingModal-titulo">De onde sai esse dinheiro?</h3>
                <p data-funding-resumo></p>
                <button type="button" data-funding-close aria-label="Fechar">x</button>
                <div data-funding-opcoes role="radiogroup"></div>
                <div data-funding-sem-saida hidden><span data-funding-sem-saida-msg></span></div>
                <button type="button" data-funding-close>Cancelar</button>
                <button type="button" data-funding-confirm>Confirmar</button>
            </div>
        </div>`;
}

const payload = () => ({
    conta: { id: 1, nome: 'Corrente' },
    valor: 300,
    disponivel: 100,
    faltante: 200,
    fontes: [
        {
            id: 'cheque_especial', rotulo: 'Usar o cheque especial', cobre: true, teto: 1000,
            detalhe: 'Sua conta fica em −R$ 200,00 — o limite é R$ 1.000,00.',
        },
        {
            id: 'resgate_investimento', rotulo: 'Resgatar de um investimento', cobre: true,
            teto: 900, total: 900, detalhe: 'Vamos resgatar R$ 200,00.',
            itens: [{ id: 7, nome: 'CDB', aplicado: 900, cobre: true }],
        },
    ],
});

function escolher(fonte) {
    const radio = document.querySelector(`input[name="sm-funding-source"][value="${fonte}"]`);
    radio.checked = true;
    document.querySelector('[data-funding-confirm]').click();
}

describe('funding.js — o teto aprovado vai junto da escolha', () => {
    beforeEach(() => {
        montarModal();
        initFunding();
    });

    afterEach(() => {
        liberarDialogosOrfaos();
        document.body.innerHTML = '';
    });

    it('cheque especial leva o faltante mostrado como teto', async () => {
        const escolha = pedirFonte(payload(), { retorno: document.getElementById('salvar') });
        escolher('cheque_especial');

        await expect(escolha).resolves.toEqual({
            funding_source: 'cheque_especial',
            funding_max_amount: '200.00',
        });
    });

    it('resgate leva o investimento e o mesmo teto', async () => {
        const escolha = pedirFonte(payload(), { retorno: document.getElementById('salvar') });
        escolher('resgate_investimento');

        await expect(escolha).resolves.toEqual({
            funding_source: 'resgate_investimento',
            funding_investment_id: '7',
            funding_max_amount: '200.00',
        });
    });

    it('cancelar não devolve escolha nenhuma', async () => {
        const escolha = pedirFonte(payload(), { retorno: document.getElementById('salvar') });
        document.querySelector('[data-funding-close]').click();

        await expect(escolha).resolves.toBeNull();
    });
});
