import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * Ordem das camadas (z-index) entre barra de cookies, popovers e modais.
 *
 * O defeito (A-9 da auditoria de 05/09, P-5 de 06/09): a barra de cookies e o véu dos
 * modais tinham o MESMO z-index. O desempate é a ordem no HTML, e o cookie-consent entra
 * no layout depois dos modais — a barra ficava por cima do modal "Lançar" na primeira
 * visita e o clique no "Salvar" caía nela. Verificado num Chromium de verdade com o CSS
 * do projeto: `elementFromPoint` no centro do botão devolvia a barra.
 *
 * jsdom não calcula empilhamento, então o que se trava aqui é o CONTRATO dos números,
 * lido do CSS de verdade: popovers < barra de cookies < modais, e nunca empate.
 */
const css = readFileSync(resolve(__dirname, '../../resources/css/design-system.css'), 'utf8');

/** z-index da regra cujo seletor é exatamente `seletor`. */
function zIndexDe(seletor) {
    const escapado = seletor.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const regra = css.match(new RegExp(`(?:^|\\})\\s*${escapado}\\s*\\{([^}]*)\\}`, 'm'));

    if (!regra) throw new Error(`Regra "${seletor}" não encontrada no design-system.css.`);

    const z = regra[1].match(/z-index:\s*(-?\d+)/);
    if (!z) throw new Error(`Regra "${seletor}" não declara z-index.`);

    return Number(z[1]);
}

describe('camadas: barra de cookies × popovers × modais', () => {
    const barra = () => zIndexDe('.cookie-bar');
    const modal = () => zIndexDe('.modal-scrim');
    const popovers = () => zIndexDe('.profile-pop, .notif-pop');

    it('a barra de cookies fica ABAIXO dos modais (senão cobre o "Salvar")', () => {
        expect(barra()).toBeLessThan(modal());
    });

    it('barra e modais nunca empatam: no empate quem decide é a ordem do HTML', () => {
        expect(barra()).not.toBe(modal());
    });

    it('a barra de cookies continua ACIMA dos popovers de perfil e notificações', () => {
        expect(barra()).toBeGreaterThan(popovers());
    });
});
