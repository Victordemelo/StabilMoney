import { afterEach, describe, expect, it, vi } from 'vitest';
import { limitarLista, initListasRolaveis } from '../../resources/js/sm/lista-rolavel.js';
import { alternar } from '../../resources/js/sm/details-suave.js';

/**
 * Listas longas de Contas a pagar rolam por dentro (14/10/2026): até 8 itens nas contas fixas e
 * nas despesas em conta, até 10 nos cartões. O jsdom não faz layout: cada item recebe 50px de
 * altura num retângulo falso, e a lista começa em 100px.
 */

function montar(qtd, maximo, dentroDeDetails = false) {
    const itens = Array.from({ length: qtd }, (_, i) => `<div class="fatura-item">item ${i + 1}</div>`).join('');
    const lista = `<div class="fatura-items" data-rolagem="${maximo}">${itens}</div>`;
    document.body.innerHTML = dentroDeDetails
        ? `<details data-suave><summary>Cartão</summary><div>${lista}</div></details>`
        : lista;
    const el = document.querySelector('[data-rolagem]');
    const visivel = () => !dentroDeDetails || document.querySelector('details').open;
    el.getBoundingClientRect = () => ({ top: visivel() ? 100 : 0, bottom: 0 });
    Array.from(el.children).forEach((item, i) => {
        item.getBoundingClientRect = () => (visivel()
            ? { top: 100 + i * 50, bottom: 150 + i * 50 }
            : { top: 0, bottom: 0 });
    });
    return el;
}

afterEach(() => {
    document.body.innerHTML = '';
    delete HTMLElement.prototype.animate;
    delete document.documentElement.dataset.listasRolaveis;
});

describe('lista rolável', () => {
    it('até o limite, a lista fica inteira', () => {
        const lista = montar(8, 8);
        expect(limitarLista(lista)).toBe(false);
        expect(lista.style.maxHeight).toBe('');
        expect(lista.classList.contains('rolando')).toBe(false);
    });

    it('passou do limite: fica da altura dos N primeiros e rola', () => {
        const lista = montar(9, 8);
        expect(limitarLista(lista)).toBe(true);
        expect(lista.style.maxHeight).toBe('400px'); // 8 × 50
        expect(lista.classList.contains('rolando')).toBe(true);
    });

    it('o cartão mostra até 10', () => {
        const lista = montar(15, 10);
        limitarLista(lista);
        expect(lista.style.maxHeight).toBe('500px');
    });

    it('voltou a caber (um item saiu): deixa de rolar', () => {
        const lista = montar(9, 8);
        limitarLista(lista);
        lista.lastElementChild.remove();
        expect(limitarLista(lista)).toBe(false);
        expect(lista.style.maxHeight).toBe('');
        expect(lista.classList.contains('rolando')).toBe(false);
    });

    it('dentro de um details fechado não mede nada; mede ao abrir', () => {
        const lista = montar(12, 8, true);
        initListasRolaveis(document);
        expect(lista.style.maxHeight).toBe('');
        alternar(document.querySelector('details')); // sem Web Animations: abre na hora
        expect(lista.style.maxHeight).toBe('400px');
    });

    it('abrindo com animação, a lista já tem a altura de rolagem quando a animação é medida', () => {
        const lista = montar(12, 8, true);
        let alturaNaMedida = null;
        HTMLElement.prototype.animate = vi.fn(function () {
            if (this.matches('details')) alturaNaMedida = lista.style.maxHeight;
            return { onfinish: null, oncancel: null };
        });
        alternar(document.querySelector('details'));
        expect(alturaNaMedida).toBe('400px');
    });
});
