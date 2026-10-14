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

describe('duas colunas: a linha abre e fecha junta', () => {
    const grade = (largo = true) => {
        window.matchMedia = vi.fn((q) => ({ matches: q.includes('min-width') ? largo : false }));
        document.body.innerHTML = `
            <div class="faturas-colunas">
                <details data-suave open id="a"><summary>A</summary><div>a</div></details>
                <details data-suave id="b"><summary>B</summary><div>b</div></details>
                <details data-suave id="c"><summary>C</summary><div>c</div></details>
            </div>`;
        return ['a', 'b', 'c'].map((id) => document.getElementById(id));
    };

    afterEach(() => { delete window.matchMedia; });

    it('com duas colunas, o par nasce no mesmo estado e fecha junto', async () => {
        const { sincronizarPares, alternar, parDe } = await import('../../resources/js/sm/details-suave.js');
        const [a, b, c] = grade(true);
        expect(parDe(a)).toBe(b);
        expect(parDe(c)).toBeNull(); // o terceiro está sozinho na linha dele
        sincronizarPares(document);
        expect(b.open).toBe(true);

        alternar(b); // sem a API de animação: na hora
        expect(a.open).toBe(false);
        expect(b.open).toBe(false);
        expect(c.open).toBe(false);
    });

    it('numa coluna só, cada card é independente', async () => {
        const { sincronizarPares, alternar } = await import('../../resources/js/sm/details-suave.js');
        const [a, b] = grade(false);
        sincronizarPares(document);
        expect(b.open).toBe(false);
        alternar(a);
        expect(a.open).toBe(false);
        expect(b.open).toBe(false);
        alternar(b);
        expect(a.open).toBe(false);
        expect(b.open).toBe(true);
    });
});
