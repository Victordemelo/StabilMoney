import { afterEach, describe, expect, it } from 'vitest';
import { definirOculto, estaOculto, initOcultarValores, marcarValores } from '../../resources/js/sm/ocultar-valores.js';

/**
 * O "olho" que esconde os valores (out/2026): marca todo valor em R$ com `.sm-valor`, liga e
 * desliga com um toque e guarda a escolha neste aparelho.
 */

afterEach(() => {
    document.body.innerHTML = '';
    document.documentElement.removeAttribute('data-valores-ocultos');
    document.documentElement.removeAttribute('data-valores-prontos');
    delete document.documentElement.dataset.ocultarLigado;
    localStorage.clear();
});

const marcados = () => [...document.querySelectorAll('.sm-valor')].map((el) => el.textContent.replace(/\s+/g, ' ').trim());

describe('Marcar os valores', () => {
    it('marca o valor inteiro, inclusive quando "R$" e o número são spans irmãos', () => {
        document.body.innerHTML = `
            <div class="value" id="hero"><span class="sign"></span><span class="cur">R$</span><span class="num">1.030,40</span></div>
            <p>Saldo atual <b id="resumo">R$ 0,00</b></p>
            <span id="neg">−R$ 150,00</span>
            <label>Valor (R$)</label>
            <input value="R$ 12,00">
            <select><option>Cartão R$ 10,00</option></select>
            <p id="frase">Com 24 parcelas, o valor precisa ser de pelo menos R$ 0,24, então ajuste o valor e tente de novo agora.</p>`;
        marcarValores(document.body);

        expect(document.getElementById('hero').classList.contains('sm-valor')).toBe(true);
        expect(document.getElementById('resumo').classList.contains('sm-valor')).toBe(true);
        expect(document.getElementById('neg').classList.contains('sm-valor')).toBe(true);
        // O rótulo "Valor (R$)" não é valor; campo e opção nunca são tocados; frase longa também não.
        expect(document.querySelector('label').classList.contains('sm-valor')).toBe(false);
        expect(document.getElementById('frase').classList.contains('sm-valor')).toBe(false);
        expect(marcados()).toHaveLength(3);
    });
});

describe('O botão', () => {
    const montar = () => {
        document.body.innerHTML = `
            <button data-ocultar-valores aria-pressed="false">olho</button>
            <div id="saldo">R$ 4.218,30</div>`;
        initOcultarValores(document);
    };

    it('liga, guarda a escolha e desliga', () => {
        montar();
        const btn = document.querySelector('[data-ocultar-valores]');
        expect(document.getElementById('saldo').classList.contains('sm-valor')).toBe(true);
        expect(document.documentElement.hasAttribute('data-valores-prontos')).toBe(true);

        btn.click();
        expect(estaOculto()).toBe(true);
        expect(localStorage.getItem('sm-ocultar-valores')).toBe('1');
        expect(btn.getAttribute('aria-pressed')).toBe('true');
        expect(btn.getAttribute('aria-label')).toBe('Mostrar os valores');

        btn.click();
        expect(estaOculto()).toBe(false);
        expect(localStorage.getItem('sm-ocultar-valores')).toBe('0');
    });

    it('o que o <head> decidiu é respeitado ao ligar', () => {
        document.documentElement.setAttribute('data-valores-ocultos', '');
        montar();
        expect(document.querySelector('[data-ocultar-valores]').getAttribute('aria-pressed')).toBe('true');
    });

    it('valor que chega depois (troca de tela, modal) também é marcado', async () => {
        montar();
        const novo = document.createElement('div');
        novo.innerHTML = '<span class="cur">R$</span><span>77,00</span>';
        document.body.appendChild(novo);
        await Promise.resolve();
        await Promise.resolve();
        expect(novo.classList.contains('sm-valor')).toBe(true);
    });

    it('definirOculto atualiza todos os botões (celular e computador)', () => {
        document.body.innerHTML = '<button data-ocultar-valores></button><button data-ocultar-valores></button>';
        definirOculto(true);
        document.querySelectorAll('[data-ocultar-valores]').forEach((b) => expect(b.getAttribute('aria-pressed')).toBe('true'));
    });
});
