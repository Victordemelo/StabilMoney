import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { PASSOS, posicaoDoBalao, retomarTutorial } from '../../resources/js/sm/tutorial.js';

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

    it('o balão nunca cobre o alvo: embaixo, em cima, ao lado — ou no canto, se o alvo é enorme', () => {
        const [vw, vh, bw, bh] = [1440, 900, 360, 200];
        const cobre = (p, a) => !(p.left + bw <= a.left || p.left >= a.right || p.top + bh <= a.top || p.top >= a.bottom);

        const botao = { top: 90, left: 1240, bottom: 140, right: 1420 }; // "Nova conta", no alto à direita
        const embaixo = posicaoDoBalao(botao, bw, bh, vw, vh);
        expect(embaixo.top).toBe(140 + 14);
        expect(cobre(embaixo, botao)).toBe(false);
        expect(embaixo.left + bw).toBeLessThanOrEqual(vw - 12);
        // Alinhado pela direita do alvo (o lado que costuma estar livre).
        expect(embaixo.left).toBe(1420 - bw);

        // Cabeçalho largo do grupo "Contas": embaixo, mas no canto direito — não em cima dos cartões.
        const cabecalho = { top: 145, left: 254, bottom: 184, right: 1418 };
        expect(posicaoDoBalao(cabecalho, bw, bh, vw, vh).left).toBe(1418 - bw);

        const rodape = { top: 760, left: 400, bottom: 860, right: 800 };
        const emCima = posicaoDoBalao(rodape, bw, bh, vw, vh);
        expect(emCima.top + bh).toBe(760 - 14);

        // O grupo "Contas" inteiro (largo e alto): o balão vai para o lado livre, à direita.
        const grupo = { top: 120, left: 340, bottom: 880, right: 1000 };
        const lado = posicaoDoBalao(grupo, bw, bh, vw, vh);
        expect(lado.left).toBe(1000 + 14);
        expect(cobre(lado, grupo)).toBe(false);

        // Alvo do tamanho da tela: nenhum lado livre, vai para o canto.
        const tela = { top: 4, left: 4, bottom: 896, right: 1436 };
        expect(posicaoDoBalao(tela, bw, bh, vw, vh)).toEqual({ top: vh - bh - 12, left: vw - bw - 12 });
    });

    it('os alvos grandes demais foram trocados por partes que cabem na tela', () => {
        const alvos = PASSOS.flatMap((p) => p.alvo || []);
        expect(alvos).toContain('.fatura-card .fatura-head');
        expect(alvos).toContain('.acct-grupo-head');
        expect(alvos).toContain('.cat-col-head');
        expect(alvos).toContain('.tx-list .tx:first-child');
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
