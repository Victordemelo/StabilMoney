import { describe, expect, it } from 'vitest';
import { ROTEIRO, aplicar, iniciarVitrine, numeroBrl, valorComSinal } from '../../resources/js/sm/vitrine.js';

/**
 * A prévia viva do app no topo da página inicial (out/2026): a cada passo entra um lançamento
 * no topo da lista e o saldo acompanha. O roteiro soma zero, para a volta poder repetir sem o
 * saldo ir parar no infinito.
 */

const tela = () => `
    <figure data-vitrine data-corrente="3018.3" data-poupanca="1200" data-receitas="5200" data-despesas="1981.7">
        <span data-vt-saldo>4.218,30</span>
        <span data-vt-corrente>3.018,30</span>
        <span data-vt-gasto>1.981,70</span>
        <span data-vt-renda>5.200,00</span>
        <i data-vt-barra></i>
        <ul data-vt-lista>
            <li class="vt-item"><b>Salário</b></li>
            <li class="vt-item"><b>Aluguel</b></li>
            <li class="vt-item"><b>Mercado</b></li>
        </ul>
    </figure>`;

const texto = (sel) => document.querySelector(sel).textContent;
const nomes = () => [...document.querySelectorAll('[data-vt-lista] li')].map((li) => li.querySelector('b').textContent);

/** Sem relógio e sem contagem: cada `passo()` escreve o resultado direto. */
function ligar() {
    document.body.innerHTML = tela();
    const agendados = [];
    const vitrine = iniciarVitrine(document.querySelector('[data-vitrine]'), {
        duracao: 0,
        reduzir: false,
        agendar: (fn, ms) => agendados.push({ fn, ms }),
        cancelar: () => {},
    });
    return { vitrine, agendados };
}

describe('Dinheiro da vitrine', () => {
    it('escreve no formato do app, com o sinal antes do símbolo', () => {
        expect(numeroBrl(4218.3)).toBe('4.218,30');
        expect(valorComSinal(250)).toBe('+R$ 250,00');
        expect(valorComSinal(-182.4)).toBe('−R$ 182,40');
    });

    it('o roteiro soma zero: depois de uma volta o saldo é o mesmo', () => {
        const centavos = ROTEIRO.reduce((soma, l) => soma + Math.round(l.valor * 100), 0);
        expect(centavos).toBe(0);
    });

    it('receita soma em receitas, despesa em despesas, e as duas mexem na conta corrente', () => {
        const base = { corrente: 100, poupanca: 0, receitas: 500, despesas: 200 };
        expect(aplicar(base, { valor: -30.1 })).toEqual({ corrente: 69.9, poupanca: 0, receitas: 500, despesas: 230.1 });
        expect(aplicar(base, { valor: 50 })).toEqual({ corrente: 150, poupanca: 0, receitas: 550, despesas: 200 });
    });
});

describe('A vitrine na página', () => {
    it('cada passo põe o lançamento novo no topo, mantém três e atualiza o saldo', () => {
        const { vitrine } = ligar();

        vitrine.passo(); // Mercado do bairro, −182,40
        expect(nomes()).toEqual(['Mercado do bairro', 'Salário', 'Aluguel']);
        expect(texto('[data-vt-saldo]')).toBe('4.035,90');
        expect(texto('[data-vt-corrente]')).toBe('2.835,90');
        expect(texto('[data-vt-gasto]')).toBe('2.164,10');
        expect(document.querySelector('[data-vt-lista] li').textContent).toContain('−R$ 182,40');

        vitrine.passo(); // Pix recebido de Ana, +250
        expect(nomes()[0]).toBe('Pix recebido de Ana');
        expect(texto('[data-vt-saldo]')).toBe('4.285,90');
        expect(texto('[data-vt-renda]')).toBe('5.450,00');
    });

    it('depois de uma volta inteira, saldo e mês voltam ao começo', () => {
        const { vitrine } = ligar();
        ROTEIRO.forEach(() => vitrine.passo());

        expect(texto('[data-vt-saldo]')).toBe('4.218,30');
        expect(texto('[data-vt-gasto]')).toBe('1.981,70');
        expect(texto('[data-vt-renda]')).toBe('5.200,00');
        expect(document.querySelectorAll('[data-vt-lista] li')).toHaveLength(3);
    });

    it('o nome do lançamento entra como texto, nunca como HTML', () => {
        const { vitrine } = ligar();
        vitrine.passo();
        expect(document.querySelector('[data-vt-lista] li b').children).toHaveLength(0);
    });

    it('agenda a troca a cada poucos segundos e não liga duas vezes', () => {
        const { agendados } = ligar();
        expect(agendados).toHaveLength(1);
        expect(agendados[0].ms).toBeGreaterThanOrEqual(3000);
        expect(iniciarVitrine(document.querySelector('[data-vitrine]'))).toBeNull();
    });

    it('com "reduzir movimento" nada é agendado e o estado do servidor fica', () => {
        document.body.innerHTML = tela();
        const agendados = [];
        iniciarVitrine(document.querySelector('[data-vitrine]'), { reduzir: true, agendar: (fn) => agendados.push(fn) });

        expect(agendados).toHaveLength(0);
        expect(texto('[data-vt-saldo]')).toBe('4.218,30');
        expect(nomes()).toEqual(['Salário', 'Aluguel', 'Mercado']);
    });

    it('sem a vitrine na página, não faz nada', () => {
        document.body.innerHTML = '<main></main>';
        expect(iniciarVitrine(document.querySelector('[data-vitrine]'))).toBeNull();
    });
});
