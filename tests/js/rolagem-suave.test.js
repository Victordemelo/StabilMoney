import { afterEach, describe, expect, it } from 'vitest';
import { curva, duracaoPara, initRolagemSuave } from '../../resources/js/sm/rolagem-suave.js';

/**
 * Rolagem suave da página inicial (out/2026): o menu desliza até a seção numa curva, em vez
 * de saltar seco; para quando a pessoa mexe; respeita "reduzir movimento".
 */

let atual = null;

afterEach(() => {
    atual?.desligar();
    atual = null;
    document.body.innerHTML = '';
    document.body.className = '';
    delete document.body.dataset.rolagemSuave;
});

/** Página inicial com um rolador falso: jsdom não calcula layout. */
function montar({ reduzir = false } = {}) {
    document.body.className = 'inicio-body';
    document.body.innerHTML = `
        <nav><a href="#recursos" id="ir">Recursos</a><a href="#nada">Nada</a><a href="/login">Entrar</a></nav>
        <section id="recursos" style="scroll-margin-top: 72px">Recursos</section>`;
    const el = document.documentElement;
    let topo = 0;
    Object.defineProperty(el, 'scrollTop', { configurable: true, get: () => topo, set: (v) => { topo = v; } });
    Object.defineProperty(el, 'scrollHeight', { configurable: true, get: () => 5000 });
    Object.defineProperty(el, 'clientHeight', { configurable: true, get: () => 800 });
    const secao = document.getElementById('recursos');
    secao.getBoundingClientRect = () => ({ top: 1272 - topo });

    let relogio = 0;
    const quadros = [];
    const r = initRolagemSuave(document, { agora: () => relogio, quadro: (fn) => quadros.push(fn), reduzir });
    atual = r;
    const rodar = (ms) => {
        relogio += ms;
        const fn = quadros.shift();
        if (fn) fn();
    };
    return { r, secao, quadros, rodar, topo: () => topo };
}

const clicar = (sel) => {
    const ev = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });
    document.querySelector(sel).dispatchEvent(ev);
    return ev;
};

describe('A curva', () => {
    it('começa e termina parada, e passa pelo meio', () => {
        expect(curva(0)).toBe(0);
        expect(curva(1)).toBe(1);
        expect(curva(0.5)).toBe(0.5);
        expect(curva(0.1)).toBeLessThan(0.1);
        expect(curva(0.9)).toBeGreaterThan(0.9);
    });

    it('a duração cresce com a distância, dentro de um teto', () => {
        expect(duracaoPara(100)).toBe(450);
        expect(duracaoPara(1200)).toBeGreaterThan(duracaoPara(600));
        expect(duracaoPara(-1200)).toBe(duracaoPara(1200));
        expect(duracaoPara(20000)).toBe(1100);
    });
});

describe('Na página inicial', () => {
    it('o clique no menu desliza até a seção, descontando o topo fixo', () => {
        const { quadros, rodar, topo, secao } = montar();
        const ev = clicar('#ir');

        expect(ev.defaultPrevented).toBe(true);
        expect(quadros).toHaveLength(1);
        rodar(100);
        const noMeio = topo();
        expect(noMeio).toBeGreaterThan(0);
        expect(noMeio).toBeLessThan(1200);
        for (let i = 0; i < 40 && quadros.length; i++) rodar(100);

        expect(topo()).toBe(1200); // 1272 − 72 do scroll-margin-top
        expect(location.hash).toBe('#recursos');
        expect(document.activeElement).toBe(secao);
    });

    it('para quando a pessoa mexe a roda no meio do caminho', () => {
        const { quadros, rodar, topo } = montar();
        clicar('#ir');
        rodar(100);
        const onde = topo();
        window.dispatchEvent(new Event('wheel'));
        rodar(100);
        expect(topo()).toBe(onde);
        expect(quadros).toHaveLength(0);
    });

    it('com "reduzir movimento" vai direto', () => {
        const { quadros, topo } = montar({ reduzir: true });
        clicar('#ir');
        expect(quadros).toHaveLength(0);
        expect(topo()).toBe(1200);
    });

    it('âncora sem seção e link comum ficam com o navegador', () => {
        montar();
        expect(clicar('a[href="#nada"]').defaultPrevented).toBe(false);
        expect(clicar('a[href="/login"]').defaultPrevented).toBe(false);
    });

    it('fora da página inicial não liga nada', () => {
        document.body.className = '';
        expect(initRolagemSuave(document)).toBeNull();
    });
});
