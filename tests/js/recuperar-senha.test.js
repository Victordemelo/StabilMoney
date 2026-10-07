import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { initRecuperarSenha } from '../../resources/js/sm/recuperar-senha.js';

/** "Reenviar" do "Recuperar senha": desligado contando os segundos que o servidor mandou. */

const tela = (espera) => `
    <form data-reenviar-link data-espera="${espera}">
        <button type="submit" data-reenviar-botao><span data-reenviar-rotulo>Reenviar em ${espera} s</span></button>
        <p data-reenviar-anuncio></p>
    </form>
    <p data-outro-email ${espera > 0 ? 'hidden' : ''}><a href="?outro=1">Usar outro e-mail</a></p>`;

describe('Reenviar o link', () => {
    beforeEach(() => { vi.useFakeTimers(); vi.setSystemTime(new Date('2026-10-07T12:00:00Z')); });
    afterEach(() => { vi.useRealTimers(); });

    const botao = () => document.querySelector('[data-reenviar-botao]');
    const rotulo = () => document.querySelector('[data-reenviar-rotulo]').textContent;
    const outro = () => document.querySelector('[data-outro-email]');

    it('desliga o botão e conta os segundos até liberar', () => {
        document.body.innerHTML = tela(60);
        initRecuperarSenha();
        expect(botao().disabled).toBe(true);
        expect(rotulo()).toBe('Reenviar em 60 s');

        vi.advanceTimersByTime(20_000);
        expect(rotulo()).toBe('Reenviar em 40 s');
        expect(outro().hidden).toBe(true);

        vi.advanceTimersByTime(40_000);
        expect(botao().disabled).toBe(false);
        expect(rotulo()).toBe('Reenviar o link');
        expect(outro().hidden).toBe(false);
        expect(document.querySelector('[data-reenviar-anuncio]').textContent).toBe('Você já pode reenviar o link.');
    });

    it('conta pelo relógio: aba parada não atrasa o fim da espera', () => {
        document.body.innerHTML = tela(30);
        initRecuperarSenha();
        vi.setSystemTime(new Date('2026-10-07T12:00:31Z')); // timers atrasados (aba em segundo plano)
        vi.advanceTimersByTime(250);
        expect(botao().disabled).toBe(false);
    });

    it('sem espera (já passou dos 60 s), nasce liberado e sem anúncio', () => {
        document.body.innerHTML = tela(0);
        expect(initRecuperarSenha()).toBe(null);
        expect(botao().disabled).toBe(false);
        expect(outro().hidden).toBe(false);
        expect(document.querySelector('[data-reenviar-anuncio]').textContent).toBe('');
    });

    it('fora da tela de recuperação não faz nada', () => {
        document.body.innerHTML = '<form></form>';
        expect(initRecuperarSenha()).toBe(null);
    });
});
