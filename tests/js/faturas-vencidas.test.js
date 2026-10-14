import { afterEach, describe, expect, it } from 'vitest';
import { atualizarFaturasVencidas, initFaturasVencidas } from '../../resources/js/sm/faturas-vencidas.js';

/**
 * "Já paguei essa fatura fora do app" (13/10/2026): a caixa só aparece em DESPESA no CARTÃO com a
 * data até o corte das faturas vencidas (`data-corte-vencidas` na opção). Escondida, fica
 * desabilitada — não vai no envio.
 */

const montar = ({ tipo = 'expense', data = '2026-08-15', modo = null } = {}) => {
    document.body.innerHTML = `
        <form id="f">
            ${tipo === 'hidden' ? '<input type="hidden" name="type" value="expense">' : `
            <input type="radio" name="type" value="income" ${tipo === 'income' ? 'checked' : ''}>
            <input type="radio" name="type" value="expense" ${tipo === 'expense' ? 'checked' : ''}>`}
            <select id="conta" name="account_id">
                <option value="1">Conta Itaú</option>
                <option value="2" data-corte-vencidas="2026-10-02" selected>Mercado Pago</option>
            </select>
            <input id="data" name="date" value="${data}">
            ${modo ? `<input type="hidden" id="modo" name="mode" value="${modo}">` : ''}
            <div data-vencidas-pagas data-conta="conta" data-data="data" ${modo ? 'data-modo="modo"' : ''} hidden>
                <input type="checkbox" name="faturas_vencidas_pagas" value="1" checked disabled>
            </div>
        </form>`;
    const form = document.getElementById('f');
    return { form, bloco: form.querySelector('[data-vencidas-pagas]'), caixa: form.querySelector('[name="faturas_vencidas_pagas"]') };
};

afterEach(() => { document.body.innerHTML = ''; delete document.documentElement.dataset.vencidasLigado; });

describe('A caixa das faturas vencidas', () => {
    it('aparece, marcada, numa despesa no cartão com data numa fatura vencida, e vai no envio', () => {
        const { form, bloco, caixa } = montar();
        atualizarFaturasVencidas(document);
        expect(bloco.hidden).toBe(false);
        expect(caixa.disabled).toBe(false);
        expect(new FormData(form).get('faturas_vencidas_pagas')).toBe('1');
    });

    it('some (e não vai no envio) com data numa fatura que ainda não venceu, fora do cartão ou em receita', () => {
        const casos = [
            () => montar({ data: '2026-10-03' }),
            () => { const m = montar(); m.form.querySelector('#conta').value = '1'; return m; },
            () => montar({ tipo: 'income' }),
            () => montar({ data: '' }),
            () => montar({ modo: 'recorrente' }),
        ];
        casos.forEach((caso) => {
            const { form, bloco } = caso();
            atualizarFaturasVencidas(document);
            expect(bloco.hidden).toBe(true);
            expect(new FormData(form).get('faturas_vencidas_pagas')).toBeNull();
        });
    });

    it('sem seletor de tipo (Contas a pagar só lança despesa) vale como despesa', () => {
        const { bloco } = montar({ tipo: 'hidden', modo: 'parcelado' });
        atualizarFaturasVencidas(document);
        expect(bloco.hidden).toBe(false);
    });

    it('acompanha a data digitada e volta MARCADA quando reaparece', () => {
        const { bloco, caixa } = montar({ data: '2026-10-10' });
        initFaturasVencidas(document);
        expect(bloco.hidden).toBe(true);

        const data = document.getElementById('data');
        data.value = '2026-09-01';
        data.dispatchEvent(new Event('input', { bubbles: true }));
        expect(bloco.hidden).toBe(false);

        caixa.checked = false; // a pessoa desmarca: não pagou
        data.value = '2026-09-02';
        data.dispatchEvent(new Event('input', { bubbles: true }));
        expect(caixa.checked).toBe(false); // seguiu visível: a escolha fica

        data.value = '2026-10-10';
        data.dispatchEvent(new Event('change', { bubbles: true }));
        expect(bloco.hidden).toBe(true);
        data.value = '2026-08-01';
        data.dispatchEvent(new Event('change', { bubbles: true }));
        expect(caixa.checked).toBe(true);
    });
});
