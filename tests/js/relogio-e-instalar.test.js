import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Relógio da topbar (fuso escolhido) e "Instalar o app" (PWA) — out/2026.
 */
import { formatarHora } from '../../resources/js/sm/relogio.js';

describe('relógio', () => {
    it('formata a hora no fuso pedido, não no do aparelho', () => {
        const instante = new Date('2026-10-04T18:30:00Z');
        expect(formatarHora(instante, 'America/Sao_Paulo')).toBe('15:30');
        expect(formatarHora(instante, 'America/Manaus')).toBe('14:30');
        expect(formatarHora(instante, 'UTC')).toBe('18:30');
    });

    it('fuso desconhecido não quebra: devolve null e a hora do servidor fica', () => {
        expect(formatarHora(new Date(), 'Marte/Olimpo')).toBeNull();
    });
});

describe('instalar o app', () => {
    beforeEach(() => {
        vi.resetModules();
        document.body.innerHTML = `
            <button data-instalar-app hidden>Instalar o app</button>
            <span data-instalar-ios hidden>No iPhone…</span>
            <span data-instalar-android hidden>No Chrome: ⋮</span>
            <span data-instalado hidden>Já instalado</span>`;
        window.matchMedia = vi.fn(() => ({ matches: false }));
    });

    const botao = () => document.querySelector('[data-instalar-app]');

    it('o botão só aparece quando o navegador oferece a instalação, e o clique abre a janela dele', async () => {
        const { initInstalar } = await import('../../resources/js/sm/instalar.js');
        initInstalar();
        expect(botao().hidden).toBe(true);

        const convite = new Event('beforeinstallprompt', { cancelable: true });
        convite.prompt = vi.fn();
        convite.userChoice = Promise.resolve({ outcome: 'accepted' });
        window.dispatchEvent(convite);

        expect(convite.defaultPrevented).toBe(true);
        expect(botao().hidden).toBe(false);

        botao().click();
        expect(convite.prompt).toHaveBeenCalledTimes(1);
        await Promise.resolve();
        await Promise.resolve();
        expect(botao().hidden).toBe(true); // o convite só serve uma vez
    });

    it('aberto como app instalado mostra "já instalado" e esconde o botão', async () => {
        window.matchMedia = vi.fn(() => ({ matches: true }));
        const { initInstalar } = await import('../../resources/js/sm/instalar.js');
        initInstalar();

        expect(document.querySelector('[data-instalado]').hidden).toBe(false);
        expect(botao().hidden).toBe(true);
    });

    it('no Android sem o convite do navegador o botão aparece e o toque mostra o caminho pelo menu', async () => {
        const ua = vi.spyOn(window.navigator, 'userAgent', 'get').mockReturnValue('Mozilla/5.0 (Linux; Android 14) Chrome/130 Mobile');
        const { initInstalar } = await import('../../resources/js/sm/instalar.js');
        initInstalar();

        expect(botao().hidden).toBe(false);
        expect(document.querySelector('[data-instalar-android]').hidden).toBe(true);
        botao().click();
        expect(document.querySelector('[data-instalar-android]').hidden).toBe(false);
        ua.mockRestore();
    });

    it('no computador sem convite o botão continua escondido', async () => {
        const { initInstalar } = await import('../../resources/js/sm/instalar.js');
        initInstalar();
        expect(botao().hidden).toBe(true);
    });
});
