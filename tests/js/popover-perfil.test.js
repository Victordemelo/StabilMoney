import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { beforeEach, describe, expect, it } from 'vitest';
import { initShell } from '../../resources/js/sm/shell.js';

/*
 * Popover do perfil FECHADO fora da ordem do Tab (A-1 da auditoria de acessibilidade de
 * 07/09/2026).
 *
 * Fechado, ele era só `opacity: 0; pointer-events: none`: "Meu perfil", "Configurações" e
 * "Sair" recebiam foco invisíveis em toda tela (Tabs 10-12), e um Enter cego no 12º Tab fazia
 * logout. Agora o fechado é `visibility: hidden` (a mesma regra do `.modal-scrim`), e o foco
 * que estava DENTRO dele volta para o botão que o abriu antes de ele sumir.
 */

const css = readFileSync(resolve(__dirname, '../../resources/css/design-system.css'), 'utf8');

/** Corpo da regra cujo seletor é exatamente `seletor`. */
function regra(seletor) {
    const escapado = seletor.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const achada = css.match(new RegExp(`(?:^|\\})\\s*${escapado}\\s*\\{([^}]*)\\}`, 'm'));
    if (!achada) throw new Error(`Regra "${seletor}" não encontrada no design-system.css.`);

    return achada[1];
}

describe('popover do perfil: CSS', () => {
    it('fechado, sai da ordem do Tab e da árvore de acessibilidade (visibility: hidden)', () => {
        expect(regra('.profile-pop, .notif-pop')).toMatch(/visibility:\s*hidden/);
    });

    it('aberto, volta a ser visível na hora', () => {
        expect(regra('.profile-pop.open, .notif-pop.open')).toMatch(/visibility:\s*visible/);
    });
});

describe('popover do perfil: foco', () => {
    let botao;
    let pop;

    beforeEach(() => {
        document.body.innerHTML = `
            <input id="fora" type="text">
            <button id="profileBtn" type="button" aria-expanded="false">Victor</button>
            <div id="profilePop" class="profile-pop" aria-hidden="true">
                <a href="/meu-perfil" id="meuPerfil">Meu perfil</a>
                <a href="/configuracoes" id="config">Configurações</a>
            </div>`;
        initShell();
        botao = document.getElementById('profileBtn');
        pop = document.getElementById('profilePop');
    });

    it('Esc com o foco dentro do popover devolve o foco ao botão', () => {
        botao.click();
        expect(pop.classList.contains('open')).toBe(true);

        document.getElementById('config').focus();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

        expect(pop.classList.contains('open')).toBe(false);
        expect(document.activeElement).toBe(botao);
    });

    it('clique fora não rouba o foco de onde a pessoa clicou', () => {
        botao.click();
        const fora = document.getElementById('fora');

        fora.focus();
        fora.click();

        expect(pop.classList.contains('open')).toBe(false);
        expect(document.activeElement).toBe(fora);
    });
});
