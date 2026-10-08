import { describe, expect, it } from 'vitest';
import { initSaldoGiro } from '../../resources/js/sm/saldo-giro.js';

/**
 * O saldo que gira de banco em banco no painel (out/2026): total → cada conta → total, a cada
 * 4 s, com o selo do banco acompanhando; toque no selo passa; "reduzir movimento" não gira.
 */

function montar(opcoes = {}) {
    document.body.innerHTML = `
        <div class="dashboard-balance-hero">
            <div class="saldo-giro" data-saldo-giro>
                <div class="value ativo" data-giro-item="0"><span class="num">1.030,40</span></div>
                <div class="value" data-giro-item="1" aria-hidden="true"><span class="valor-conta">800,00</span></div>
                <div class="value" data-giro-item="2" aria-hidden="true"><span class="valor-conta">230,40</span></div>
            </div>
            <div class="saldo-bancos" data-giro-selos role="button" tabindex="0">
                <span class="saldo-banco ativo" data-giro-item="0">Todas as contas</span>
                <span class="saldo-banco" data-giro-item="1" aria-hidden="true">Banco Itaú</span>
                <span class="saldo-banco" data-giro-item="2" aria-hidden="true">Banco Inter</span>
            </div>
        </div>`;
    const agendados = [];
    const [giro] = initSaldoGiro(document, {
        reduzir: false,
        agendar: (fn, ms) => { agendados.push({ fn, ms }); return agendados.length; },
        cancelar: () => {},
        ...opcoes,
    }).concat([null]);
    const passar = () => agendados.shift()?.fn();
    return { giro, agendados, passar };
}

const ativos = () => [...document.querySelectorAll('.ativo')].map((el) => el.textContent.trim());

describe('Saldo que gira de banco em banco', () => {
    it('começa no total e passa por cada conta a cada 4 segundos, voltando ao total', () => {
        const { agendados, passar } = montar();
        expect(ativos()).toEqual(['1.030,40', 'Todas as contas']);
        expect(agendados[0].ms).toBe(4000);

        passar();
        expect(ativos()).toEqual(['800,00', 'Banco Itaú']);
        passar();
        expect(ativos()).toEqual(['230,40', 'Banco Inter']);
        passar();
        expect(ativos()).toEqual(['1.030,40', 'Todas as contas']);
    });

    it('o que não está na tela fica escondido do leitor de tela', () => {
        const { passar } = montar();
        passar();
        expect(document.querySelector('.value[data-giro-item="0"]').getAttribute('aria-hidden')).toBe('true');
        expect(document.querySelector('.value[data-giro-item="1"]').hasAttribute('aria-hidden')).toBe(false);
    });

    it('tocar no selo passa para a próxima conta', () => {
        montar();
        document.querySelector('[data-giro-selos]').click();
        expect(ativos()).toEqual(['800,00', 'Banco Itaú']);
    });

    it('com "reduzir movimento" não gira sozinho, mas o toque funciona', () => {
        const { agendados } = montar({ reduzir: true });
        expect(agendados).toHaveLength(0);
        document.querySelector('[data-giro-selos]').click();
        expect(ativos()).toEqual(['800,00', 'Banco Itaú']);
    });

    it('com a tela trocada (pjax), para de girar', () => {
        const { agendados, passar } = montar();
        document.body.innerHTML = '';
        passar();
        expect(agendados).toHaveLength(0);
    });

    it('não liga duas vezes', () => {
        montar();
        expect(initSaldoGiro(document)).toEqual([]);
    });
});
