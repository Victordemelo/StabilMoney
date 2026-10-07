import { beforeAll, beforeEach, describe, expect, it } from 'vitest';
import { initMostrarSenha } from '../../resources/js/sm/mostrar-senha.js';

/**
 * O "olho" dos campos de senha (out/2026 — pedido do Victor para o modal de dependente).
 * Um ouvinte delegado no document: vale para conteúdo que chega depois (pjax, modais).
 */

const campo = (id = 'senha') => `
    <form id="f">
        <div class="input-pw">
            <input type="password" id="${id}" name="password" value="segredo123">
            <button type="button" class="pw-toggle" data-toggle="${id}" aria-label="Mostrar senha">olho</button>
        </div>
    </form>`;

describe('mostrar/ocultar senha', () => {
    beforeAll(() => {
        initMostrarSenha();
        initMostrarSenha(); // ligar de novo não pode dobrar o ouvinte
    });
    beforeEach(() => { document.body.innerHTML = campo(); });

    const botao = () => document.querySelector('.pw-toggle');
    const input = () => document.getElementById('senha');

    it('um clique mostra, outro esconde, e o botão diz o que faz', () => {
        botao().click();
        expect(input().type).toBe('text');
        expect(botao().classList.contains('on')).toBe(true);
        expect(botao().getAttribute('aria-label')).toBe('Ocultar senha');
        expect(botao().getAttribute('aria-pressed')).toBe('true');

        botao().click();
        expect(input().type).toBe('password');
        expect(botao().getAttribute('aria-label')).toBe('Mostrar senha');
        expect(botao().getAttribute('aria-pressed')).toBe('false');
    });

    it('vale para campo que chegou depois (pjax, modal de cada dependente)', () => {
        document.body.insertAdjacentHTML('beforeend', campo('dep-edit-password-7'));
        document.querySelector('[data-toggle="dep-edit-password-7"]').click();
        expect(document.getElementById('dep-edit-password-7').type).toBe('text');
        expect(input().type).toBe('password'); // o outro campo não muda
    });

    it('ao enviar o formulário, a senha volta a ficar oculta', () => {
        botao().click();
        document.getElementById('f').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        expect(input().type).toBe('password');
        expect(botao().classList.contains('on')).toBe(false);
    });

    it('botão apontando para um campo que não existe não quebra nada', () => {
        document.body.innerHTML = '<button type="button" class="pw-toggle" data-toggle="nao-existe">olho</button>';
        expect(() => document.querySelector('.pw-toggle').click()).not.toThrow();
    });
});
