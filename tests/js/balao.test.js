import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mostrarBalao, promoverFlashes } from '../../resources/js/sm/balao.js';

/**
 * Balões de aviso (out/2026): o "salvo/atualizado" vira um balão flutuante no canto superior
 * direito e some sozinho. O texto entra sempre por textContent.
 */
describe('balões de aviso', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        document.body.innerHTML = '';
    });
    afterEach(() => vi.useRealTimers());

    it('o aviso do servidor ([data-flash]) vai para a pilha no body e some sozinho', () => {
        document.body.innerHTML = `<main id="content">
            <div class="flash" data-flash role="status"><svg></svg><span class="sm-balao-txt">Perfil atualizado com sucesso.</span></div>
        </main>`;

        promoverFlashes();

        const pilha = document.getElementById('sm-baloes');
        expect(pilha.parentElement).toBe(document.body);
        expect(pilha.getAttribute('role')).toBe('status');
        const balao = pilha.querySelector('.sm-balao.ok');
        expect(balao.textContent).toContain('Perfil atualizado com sucesso.');
        expect(document.querySelector('#content .flash')).toBeNull();
        expect(balao.hasAttribute('data-flash')).toBe(false);

        vi.advanceTimersByTime(4500 + 300);
        expect(pilha.querySelector('.sm-balao')).toBeNull();
    });

    it('o botão fecha na hora, e passar o mouse segura o balão', () => {
        const balao = mostrarBalao('Conta salva.');
        balao.dispatchEvent(new Event('mouseenter'));
        vi.advanceTimersByTime(10000);
        expect(balao.isConnected).toBe(true);

        balao.querySelector('.sm-balao-x').click();
        vi.advanceTimersByTime(300);
        expect(balao.isConnected).toBe(false);
    });

    it('texto com marcação não vira HTML', () => {
        const balao = mostrarBalao('<img src=x onerror=alert(1)> salvo', { tipo: 'info' });
        expect(balao.querySelector('img')).toBeNull();
        expect(balao.querySelector('.sm-balao-txt').textContent).toBe('<img src=x onerror=alert(1)> salvo');
        expect(balao.classList.contains('info')).toBe(true);
    });
});
