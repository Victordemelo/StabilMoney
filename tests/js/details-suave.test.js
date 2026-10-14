import { afterEach, describe, expect, it, vi } from 'vitest';
import { alternar, initDetailsSuave } from '../../resources/js/sm/details-suave.js';

/**
 * Abrir e fechar suave dos blocos de Contas a pagar (13/10/2026 — "muito seco"). A altura desliza
 * pela Web Animations; sem ela (ou com "reduzir movimento") fica o abrir/fechar nativo.
 */

const montar = (aberto = false) => {
    document.body.innerHTML = `<details data-suave ${aberto ? 'open' : ''}><summary>Cartão</summary><div>compras</div></details>`;
    return document.querySelector('details');
};

const animacaoFalsa = () => {
    const anim = { onfinish: null, oncancel: null };
    HTMLElement.prototype.animate = vi.fn(() => anim);
    return anim;
};

afterEach(() => {
    document.body.innerHTML = '';
    delete HTMLElement.prototype.animate;
    delete document.documentElement.dataset.detailsSuave;
});

describe('details suave', () => {
    it('sem a API de animação, abre e fecha na hora (como o nativo)', () => {
        const d = montar();
        expect(alternar(d)).toBeNull();
        expect(d.open).toBe(true);
        alternar(d);
        expect(d.open).toBe(false);
    });

    it('abrindo, já fica aberto e anima a altura', () => {
        const anim = animacaoFalsa();
        const d = montar();
        alternar(d);
        expect(d.open).toBe(true);
        expect(HTMLElement.prototype.animate).toHaveBeenCalled();
        expect(d.dataset.animando).toBe('1');
        anim.onfinish();
        expect(d.dataset.animando).toBeUndefined();
        expect(d.style.overflow).toBe('');
    });

    it('fechando, só fecha quando a animação termina (o conteúdo some deslizando)', () => {
        const anim = animacaoFalsa();
        const d = montar(true);
        alternar(d);
        expect(d.open).toBe(true);
        anim.onfinish();
        expect(d.open).toBe(false);
    });

    it('clique no resumo passa pelo deslizar; clique num link dentro dele, não', () => {
        animacaoFalsa();
        document.body.innerHTML = '<details data-suave><summary>Cartão <a href="#x">ver</a></summary><div>compras</div></details>';
        initDetailsSuave(document);
        const d = document.querySelector('details');

        const clique = new MouseEvent('click', { bubbles: true, cancelable: true });
        document.querySelector('summary').dispatchEvent(clique);
        expect(clique.defaultPrevented).toBe(true);
        expect(d.dataset.animando).toBe('1');

        delete d.dataset.animando;
        const noLink = new MouseEvent('click', { bubbles: true, cancelable: true });
        document.querySelector('a').dispatchEvent(noLink);
        expect(noLink.defaultPrevented).toBe(false);
    });
});
