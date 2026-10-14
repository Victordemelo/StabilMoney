import { afterEach, describe, expect, it } from 'vitest';
import { atualizarIcone, initSelectIcone } from '../../resources/js/sm/select-icone.js';

/**
 * O ícone ao lado dos selects de conta (14/10/2026 — "o ícone do lado do Pagar com; a mesma
 * coisa na receita e na transferência"): o quadrado do banco/Pix/TED ou o cartãozinho do cartão.
 */

function montar() {
    document.body.innerHTML = `
        <form>
            <div class="sel-ico">
                <select class="input" id="conta">
                    <option value="1" data-icone="/assets/banks/icones/pix.png" data-icone-forma="quadrado">Pix Itaú</option>
                    <option value="2" data-icone="/assets/banks/nubank.png?v=2" data-icone-forma="cartao">Roxinho</option>
                    <option value="3" data-icone="">Carteira</option>
                </select>
            </div>
        </form>`;
    return document.getElementById('conta');
}

afterEach(() => {
    document.body.innerHTML = '';
    delete document.documentElement.dataset.selectIcone;
});

describe('ícone ao lado do select', () => {
    it('desenha o ícone da opção escolhida ao abrir a tela', () => {
        const select = montar();
        initSelectIcone(document);
        const img = document.querySelector('.sel-ico-img');
        expect(img.getAttribute('src')).toBe('/assets/banks/icones/pix.png');
        expect(img.getAttribute('aria-hidden')).toBe('true');
        expect(select.closest('.sel-ico').dataset.forma).toBe('quadrado');
        expect(select.closest('.sel-ico').classList.contains('com-icone')).toBe(true);
    });

    it('trocar a escolha troca o desenho (o cartão vira o cartãozinho)', () => {
        const select = montar();
        initSelectIcone(document);
        select.value = '2';
        select.dispatchEvent(new Event('change', { bubbles: true }));
        expect(document.querySelector('.sel-ico-img').getAttribute('src')).toBe('/assets/banks/nubank.png?v=2');
        expect(select.closest('.sel-ico').dataset.forma).toBe('cartao');
    });

    it('opção sem ícone deixa o campo normal', () => {
        const select = montar();
        initSelectIcone(document);
        select.value = '3';
        atualizarIcone(select);
        const img = document.querySelector('.sel-ico-img');
        expect(img.hidden).toBe(true);
        expect(img.hasAttribute('src')).toBe(false);
        expect(select.closest('.sel-ico').classList.contains('com-icone')).toBe(false);
    });

    it('opção desabilitada (fora do tipo escolhido) não mostra ícone', () => {
        const select = montar();
        select.options[0].disabled = true;
        atualizarIcone(select);
        expect(document.querySelector('.sel-ico-img').hidden).toBe(true);
    });

    it('o reset do formulário volta o ícone ao da primeira opção', async () => {
        const select = montar();
        initSelectIcone(document);
        select.value = '2';
        select.dispatchEvent(new Event('change', { bubbles: true }));
        select.form.reset();
        await new Promise((r) => { setTimeout(r, 5); });
        expect(document.querySelector('.sel-ico-img').getAttribute('src')).toBe('/assets/banks/icones/pix.png');
    });
});
