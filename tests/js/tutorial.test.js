import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { PASSOS, retomarTutorial } from '../../resources/js/sm/tutorial.js';

/**
 * Tutorial guiado (out/2026): passa por todas as telas destacando cada botão. O passo atual
 * fica no sessionStorage para o tour atravessar as telas.
 */
const TELAS_DO_APP = ['/', '/transactions', '/faturas', '/metas', '/investimentos', '/accounts', '/categories', '/dependentes', '/configuracoes'];

function irPara(caminho) {
    window.history.replaceState({}, '', caminho);
}

describe('tutorial guiado', () => {
    beforeEach(() => {
        sessionStorage.clear();
        document.body.innerHTML = '';
    });
    afterEach(() => {
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    });

    it('todo passo aponta para uma tela que existe e tem título e texto', () => {
        expect(PASSOS.length).toBeGreaterThan(15);
        for (const passo of PASSOS) {
            expect(TELAS_DO_APP).toContain(passo.tela);
            expect(passo.titulo.length).toBeGreaterThan(2);
            expect(passo.texto.length).toBeGreaterThan(20);
        }
        // Passa por todas as telas principais.
        expect(new Set(PASSOS.map((p) => p.tela))).toEqual(new Set(TELAS_DO_APP));
    });

    it('retoma o passo guardado na tela certa e escreve tudo por textContent', () => {
        irPara('/transactions');
        const indice = PASSOS.findIndex((p) => p.tela === '/transactions');
        sessionStorage.setItem('sm-tutorial-passo', String(indice));

        retomarTutorial();

        const balao = document.querySelector('.tour-balao');
        expect(balao.getAttribute('role')).toBe('dialog');
        expect(document.getElementById('tour-titulo').textContent).toBe(PASSOS[indice].titulo);
        expect(document.querySelector('.tour-passo').textContent).toBe(`Passo ${indice + 1} de ${PASSOS.length}`);
        // jsdom não mede elementos: sem alvo visível, o passo vai para o centro.
        expect(document.querySelector('.tour').classList.contains('sem-alvo')).toBe(true);
    });

    it('rola até o alvo SEM animação e o destaque acompanha qualquer rolagem', () => {
        irPara('/transactions');
        const indice = PASSOS.findIndex((p) => p.tela === '/transactions');
        const alvo = document.createElement('form');
        alvo.className = 'filter-bar';
        document.body.appendChild(alvo);
        let topo = 300;
        alvo.getBoundingClientRect = () => ({ top: topo, left: 100, width: 400, height: 60, bottom: topo + 60, right: 500 });
        const rolar = vi.fn();
        alvo.scrollIntoView = rolar;
        sessionStorage.setItem('sm-tutorial-passo', String(indice));

        retomarTutorial();

        // Com `scroll-behavior: smooth` no .content, o `auto` rolaria animado e a medida sairia
        // no meio do caminho (o destaque ficava longe do botão).
        expect(rolar).toHaveBeenCalledWith({ block: 'center', behavior: 'instant' });
        const foco = document.querySelector('.tour-foco');
        expect(foco.style.top).toBe('294px');

        topo = 120;
        document.dispatchEvent(new Event('scroll'));
        expect(foco.style.top).toBe('114px');
    });

    it('Esc encerra e esquece o passo', () => {
        irPara('/');
        sessionStorage.setItem('sm-tutorial-passo', '0');
        retomarTutorial();
        expect(document.querySelector('.tour')).not.toBeNull();

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

        expect(document.querySelector('.tour')).toBeNull();
        expect(sessionStorage.getItem('sm-tutorial-passo')).toBeNull();
    });

    it('passo de outra tela (saiu do tour pelo menu) encerra em silêncio', () => {
        irPara('/metas');
        sessionStorage.setItem('sm-tutorial-passo', '0'); // passo 0 é da Visão geral
        retomarTutorial();

        expect(document.querySelector('.tour')).toBeNull();
        expect(sessionStorage.getItem('sm-tutorial-passo')).toBeNull();
    });
});
