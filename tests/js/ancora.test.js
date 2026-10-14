import { afterEach, describe, expect, it, vi } from 'vitest';
import { irParaAncora, initAncora } from '../../resources/js/sm/ancora.js';

/**
 * Os avisos do sino levam ao bloco que cobram (14/10/2026): `/faturas#cartao-3` abre o cartão,
 * `/faturas#conta-fixa-7-2026-10` abre a linha da conta fixa — inclusive dentro do "Ver as outras".
 */

afterEach(() => {
    document.body.innerHTML = '';
    delete document.documentElement.dataset.ancora;
    delete window.smPjaxIr;
    delete window.scrollTo;
    vi.useRealTimers();
});

describe('âncora dos avisos', () => {
    it('abre o cartão recolhido, rola até ele, foca o resumo e destaca', () => {
        vi.useFakeTimers();
        document.body.innerHTML = `
            <details class="fatura-card" id="cartao-3"><summary>Nubank</summary><div>compras</div></details>`;
        const card = document.getElementById('cartao-3');
        window.scrollTo = vi.fn();

        expect(irParaAncora('#cartao-3')).toBe(true);
        expect(card.open).toBe(true);
        expect(window.scrollTo).toHaveBeenCalled();
        expect(document.activeElement).toBe(card.querySelector('summary'));
        expect(card.classList.contains('alvo-destacado')).toBe(true);
        vi.advanceTimersByTime(2500);
        expect(card.classList.contains('alvo-destacado')).toBe(false);
    });

    it('a conta fixa dentro do "Ver as outras": abre os dois níveis', () => {
        document.body.innerHTML = `
            <details class="fixas-recolhe"><summary>Contas fixas</summary><div>
                <details class="fixas-outras"><summary>Ver as outras</summary>
                    <div class="fatura-items"><div class="fatura-item" id="conta-fixa-7-2026-10">Aluguel</div></div>
                </details>
            </div></details>`;
        const linha = document.getElementById('conta-fixa-7-2026-10');
        window.scrollTo = vi.fn();

        expect(irParaAncora('#conta-fixa-7-2026-10')).toBe(true);
        expect(document.querySelector('.fixas-recolhe').open).toBe(true);
        expect(document.querySelector('.fixas-outras').open).toBe(true);
        expect(linha.getAttribute('tabindex')).toBe('-1');
        expect(document.activeElement).toBe(linha);
    });

    it('rola só o contêiner que rola (o .content), centralizando o alvo — nunca a página por baixo', () => {
        document.body.innerHTML = `<main class="content" style="overflow-y:auto"><div class="fatura-item" id="conta-fixa-1-2026-10">x</div></main>`;
        const content = document.querySelector('.content');
        const linha = document.getElementById('conta-fixa-1-2026-10');
        Object.defineProperty(content, 'scrollHeight', { value: 3000 });
        Object.defineProperty(content, 'clientHeight', { value: 800 });
        content.getBoundingClientRect = () => ({ top: 0, height: 800 });
        linha.getBoundingClientRect = () => ({ top: 1500, height: 60 });
        content.scrollTo = vi.fn();
        window.scrollTo = vi.fn();

        irParaAncora('#conta-fixa-1-2026-10');
        // O meio da linha (1530) no meio do contêiner (400): rola 1130.
        expect(content.scrollTo).toHaveBeenCalledWith(expect.objectContaining({ top: 1130 }));
        expect(window.scrollTo).not.toHaveBeenCalled();
    });

    it('perto do fim, a rolagem para no limite do contêiner (não passa para a página)', () => {
        document.body.innerHTML = `<main class="content" style="overflow-y:auto"><details id="cartao-9"><summary>x</summary></details></main>`;
        const content = document.querySelector('.content');
        const card = document.getElementById('cartao-9');
        Object.defineProperty(content, 'scrollHeight', { value: 1200 });
        Object.defineProperty(content, 'clientHeight', { value: 800 });
        content.getBoundingClientRect = () => ({ top: 0, height: 800 });
        card.getBoundingClientRect = () => ({ top: 900, height: 600 }); // alto: vai com o topo a 16px
        content.scrollTo = vi.fn();
        window.scrollTo = vi.fn();

        irParaAncora('#cartao-9');
        expect(content.scrollTo).toHaveBeenCalledWith(expect.objectContaining({ top: 400 })); // 884, limitado a 1200 − 800
        expect(window.scrollTo).not.toHaveBeenCalled();
    });

    it('sem o alvo na tela (ou sem âncora), não faz nada', () => {
        expect(irParaAncora('#cartao-99')).toBe(false);
        expect(irParaAncora('')).toBe(false);
        expect(irParaAncora('#%E0%A4%A')).toBe(false); // âncora malformada não derruba nada
    });

    it('o clique num aviso navega pelo pjax (os avisos são trocados pelo pjax: ouvinte no documento)', () => {
        window.smPjaxIr = vi.fn();
        initAncora(document);
        document.body.innerHTML = '<div id="notifPop"><a class="notif-item" href="/faturas#cartao-3" data-ir-para>Fatura</a></div>';
        const clique = new MouseEvent('click', { bubbles: true, cancelable: true });
        document.querySelector('a').dispatchEvent(clique);
        expect(window.smPjaxIr).toHaveBeenCalledWith('/faturas#cartao-3');
        expect(clique.defaultPrevented).toBe(true);
    });

    it('Ctrl/Cmd + clique abre em outra aba, como qualquer link', () => {
        window.smPjaxIr = vi.fn();
        initAncora(document);
        document.body.innerHTML = '<a href="/faturas#cartao-3" data-ir-para>Fatura</a>';
        const clique = new MouseEvent('click', { bubbles: true, cancelable: true, ctrlKey: true });
        document.querySelector('a').dispatchEvent(clique);
        expect(window.smPjaxIr).not.toHaveBeenCalled();
        expect(clique.defaultPrevented).toBe(false);
    });
});
