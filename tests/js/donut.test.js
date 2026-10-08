import { describe, expect, it } from 'vitest';
import { buildDonut, donutTotalLabel } from '../../resources/js/sm/charts.js';

/**
 * "Gastos por categoria" (out/2026): o app inteiro trabalha com duas casas decimais, e o
 * donut mostrava "R$ 476" e "R$ 414" — o valor real da fatura ficava escondido.
 */
describe('Donut de gastos por categoria', () => {
    const montar = (cats) => {
        document.body.innerHTML = '<svg id="d" viewBox="0 0 120 120"></svg><div id="l"></div>';
        buildDonut(document.getElementById('d'), document.getElementById('l'), cats);
        return {
            centro: document.querySelector('.dc-amt'),
            valores: [...document.querySelectorAll('.cat-row .cv')].map((v) => v.textContent),
        };
    };

    it('o total e a legenda mostram os centavos', () => {
        const { centro, valores } = montar([
            { name: 'Transporte', value: 414.2, color: '#4C7BC0' },
            { name: 'Saúde', value: 38, color: '#4BB3B8' },
            { name: 'Outros', value: 24, color: '#777' },
            { name: 'Moradia', value: 0, color: '#B9A' },
        ]);

        expect(centro.textContent).toBe('R$ 476,20');
        expect(valores).toEqual(['R$ 414,20', 'R$ 38,00', 'R$ 24,00', 'R$ 0,00']);
    });

    it('valor grande não vira "6,1k": aparece inteiro, com a fonte menor para caber no anel', () => {
        expect(donutTotalLabel(6123.45)).toBe('R$ 6.123,45');
        const { centro } = montar([{ name: 'Moradia', value: 12345.67, color: '#123' }]);
        expect(centro.textContent).toBe('R$ 12.345,67');
        expect(parseFloat(centro.style.fontSize)).toBeLessThan(17);
    });

    it('passar o mouse numa categoria mostra o valor dela com centavos', () => {
        montar([
            { name: 'Transporte', value: 414.2, color: '#4C7BC0' },
            { name: 'Saúde', value: 38.5, color: '#4BB3B8' },
        ]);
        document.querySelectorAll('.cat-row')[1].dispatchEvent(new Event('mouseenter'));
        expect(document.querySelector('.dc-amt').textContent).toBe('R$ 38,50');
        expect(document.querySelector('.dc-lbl').textContent).toBe('Saúde');
    });
});

describe('Categoria do donut leva às Movimentações (out/2026)', () => {
    it('a linha com url vira link e o clique navega pelo pjax', () => {
        const ir = [];
        window.smPjaxIr = (url) => ir.push(url);
        document.body.innerHTML = '<svg id="d" viewBox="0 0 120 120"></svg><div id="l"></div>';
        buildDonut(document.getElementById('d'), document.getElementById('l'), [
            { name: 'Transporte', value: 540.87, color: '#4C7BC0', url: '/transactions?type=expense&category=7&de=2026-10-01&ate=2026-10-31' },
            { name: 'Outros', value: 256.37, color: '#777', url: null },
        ]);

        const [transporte, outros] = document.querySelectorAll('.cat-row');
        expect(transporte.tagName).toBe('A');
        expect(transporte.getAttribute('href')).toContain('category=7');
        expect(outros.tagName).toBe('DIV');

        transporte.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
        document.querySelector('.donut-seg[data-i="0"]').dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
        expect(ir).toEqual([
            '/transactions?type=expense&category=7&de=2026-10-01&ate=2026-10-31',
            '/transactions?type=expense&category=7&de=2026-10-01&ate=2026-10-31',
        ]);
        delete window.smPjaxIr;
    });
});
