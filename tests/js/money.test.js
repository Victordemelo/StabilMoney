import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { beforeEach, describe, expect, it } from 'vitest';

import { initMoney } from '../../resources/js/sm/money.js';

/**
 * Contrato da máscara monetária (`resources/js/sm/money.js`).
 *
 * O módulo exporta só `initMoney()` — `soDigitos` e `formatarDigitos` são
 * internas. Testar pelo DOM (montar o input, chamar `initMoney()`, disparar
 * `input`/`blur` e ler `el.value`) exercita o contrato REAL que as telas usam,
 * e não obriga a abrir a API do módulo só para o teste enxergar.
 */

/** Monta um campo no DOM e devolve o elemento (sem chamar `initMoney` ainda). */
function montarCampo({ valor = '', inputmode = 'decimal', semMoeda = false } = {}) {
    const el = document.createElement('input');
    el.type = 'text';
    if (inputmode !== null) el.setAttribute('inputmode', inputmode);
    if (semMoeda) el.setAttribute('data-no-money', '');
    el.value = valor;
    document.body.append(el);

    return el;
}

/** Atalho: campo já ligado à máscara. */
function campoLigado(opcoes = {}) {
    const el = montarCampo(opcoes);
    initMoney();

    return el;
}

/**
 * Simula digitação tecla a tecla: cada caractere é acrescentado ao FIM do que
 * está na tela e dispara um `input`, exatamente como o navegador faz com o
 * cursor no fim do campo (que é onde a máscara o deixa).
 */
function digitar(el, teclas) {
    for (const tecla of String(teclas)) {
        el.value += tecla;
        el.dispatchEvent(new Event('input', { bubbles: true }));
    }

    return el.value;
}

/** Simula colar/autofill: o conteúdo inteiro de uma vez, com um `input` só. */
function colar(el, texto) {
    el.value = texto;
    el.dispatchEvent(new Event('input', { bubbles: true }));

    return el.value;
}

/** Simula apagar `n` caracteres do fim (backspace). */
function apagar(el, n = 1) {
    for (let i = 0; i < n; i++) {
        el.value = el.value.slice(0, -1);
        el.dispatchEvent(new Event('input', { bubbles: true }));
    }

    return el.value;
}

beforeEach(() => {
    document.body.innerHTML = '';
});

describe('quais campos a máscara adota', () => {
    it('liga em input[inputmode="decimal"]', () => {
        const el = campoLigado();

        expect(el.dataset.moneyBound).toBe('1');
        expect(digitar(el, '123')).toBe('1,23');
    });

    it('ignora campo com data-no-money (taxa em %, que não é moeda)', () => {
        const el = campoLigado({ semMoeda: true });

        expect(el.dataset.moneyBound).toBeUndefined();
        expect(digitar(el, '110')).toBe('110');
    });

    it('ignora campo de outro inputmode', () => {
        const el = campoLigado({ inputmode: 'numeric' });

        expect(el.dataset.moneyBound).toBeUndefined();
        expect(digitar(el, '123')).toBe('123');
    });

    it('ignora campo sem inputmode nenhum', () => {
        const el = campoLigado({ inputmode: null });

        expect(el.dataset.moneyBound).toBeUndefined();
        expect(digitar(el, '123')).toBe('123');
    });

    it('adota todos os campos da página numa chamada só', () => {
        const a = montarCampo();
        const b = montarCampo();
        initMoney();

        expect(digitar(a, '5')).toBe('0,05');
        expect(digitar(b, '5')).toBe('0,05');
    });

    it('não liga o listener duas vezes no mesmo campo', () => {
        // O `data-money-bound` faz `initMoney()` sair cedo. Como a máscara é
        // idempotente, o efeito visível do guard é justamente ESTE: a segunda
        // chamada não repete nem a normalização de abertura.
        const el = campoLigado({ valor: '1.234,56' });

        el.value = '99';
        initMoney();

        expect(el.value).toBe('99');
        // Mas o listener da primeira chamada continua de pé (e é só um).
        expect(digitar(el, '9')).toBe('9,99');
    });

    it('adota campo novo inserido depois (pjax/modal) sem mexer nos antigos', () => {
        const antigo = campoLigado({ valor: '1.234,56' });
        const novo = montarCampo();
        initMoney();

        expect(antigo.value).toBe('1.234,56');
        expect(digitar(novo, '789')).toBe('7,89');
    });
});

describe('os dígitos entram pelos centavos', () => {
    it.each([
        ['1', '0,01'],
        ['12', '0,12'],
        ['123', '1,23'],
        ['1300', '13,00'],
        ['130000', '1.300,00'],
    ])('digitar %s resulta em %s', (teclas, esperado) => {
        expect(digitar(campoLigado(), teclas)).toBe(esperado);
    });

    it('mostra cada passo do caminho, sem valor "pulando" no fim', () => {
        const el = campoLigado();
        const passos = [...'130000'].map((tecla) => digitar(el, tecla));

        expect(passos).toEqual(['0,01', '0,13', '1,30', '13,00', '130,00', '1.300,00']);
    });

    it('apagar volta pelo mesmo caminho, dígito a dígito', () => {
        const el = campoLigado();
        digitar(el, '130000');

        expect(apagar(el, 1)).toBe('130,00');
        expect(apagar(el, 1)).toBe('13,00');
        expect(apagar(el, 1)).toBe('1,30');
        expect(apagar(el, 1)).toBe('0,13');
        expect(apagar(el, 1)).toBe('0,01');
    });

    it('⚠️ backspace EMPACA em 0,00 — só selecionar tudo esvazia o campo', () => {
        // Comportamento REAL, diferente do que "campo vazio continua vazio"
        // sugere: chegando a "0,00", o backspace apaga o último "0", sobra
        // "0,0" → dígitos "00" → o corta-zeros-à-esquerda devolve "0" → a
        // máscara reescreve "0,00". O campo se regenera para sempre.
        const el = campoLigado();
        digitar(el, '1');

        expect(apagar(el, 1)).toBe('0,00');
        expect(apagar(el, 20)).toBe('0,00');

        // A saída que existe é apagar a seleção inteira de uma vez.
        expect(colar(el, '')).toBe('');
    });
});

describe('separador de milhar', () => {
    it.each([
        ['99999', '999,99'],
        ['100000', '1.000,00'],
        ['1234567890', '12.345.678,90'],
        ['99999999999999', '999.999.999.999,99'],
    ])('%s vira %s', (digitos, esperado) => {
        expect(colar(campoLigado(), digitos)).toBe(esperado);
    });

    it('só aparece a partir de mil reais', () => {
        expect(colar(campoLigado(), '99999')).toBe('999,99');
        expect(colar(campoLigado(), '99999').includes('.')).toBe(false);
        expect(colar(campoLigado(), '100000')).toContain('.');
    });
});

describe('o que a máscara descarta', () => {
    it('descarta o sinal de menos (o sinal vem do type da transação)', () => {
        expect(colar(campoLigado(), '-50')).toBe('0,50');
        expect(colar(campoLigado(), '−1.234,56')).toBe('1.234,56');
        expect(digitar(campoLigado(), '-1-2-3')).toBe('1,23');
    });

    it('descarta letras, símbolo de moeda e espaços', () => {
        expect(colar(campoLigado(), 'R$ 1.234,56 ')).toBe('1.234,56');
        expect(colar(campoLigado(), 'abc')).toBe('');
        expect(colar(campoLigado(), '  ')).toBe('');
    });

    it('descarta notação científica — "1e12" não vira um trilhão', () => {
        // Espelha a regra do servidor (`NormalizesMoneyInput`): `numeric`
        // sozinho aceitaria 1e12; aqui sobram os dígitos "112".
        expect(colar(campoLigado(), '1e12')).toBe('1,12');
    });

    it('descarta separadores digitados — a casa decimal é fixa', () => {
        expect(digitar(campoLigado(), '1234,56')).toBe('1.234,56');
        expect(digitar(campoLigado(), '1.234.56')).toBe('1.234,56');
        expect(colar(campoLigado(), ',.')).toBe('');
    });

    it('colar "1.234,56" e "1234.56" dá o mesmo resultado', () => {
        expect(colar(campoLigado(), '1.234,56')).toBe(colar(campoLigado(), '1234.56'));
        expect(colar(campoLigado(), '1234.56')).toBe('1.234,56');
    });

    it('descarta zeros à esquerda', () => {
        expect(colar(campoLigado(), '000123')).toBe('1,23');
        expect(colar(campoLigado(), '0000000000000000001')).toBe('0,01');
    });

    it('mas mantém o zero sozinho, que é um valor legítimo', () => {
        expect(colar(campoLigado(), '0')).toBe('0,00');
        expect(digitar(campoLigado(), '000')).toBe('0,00');
    });
});

describe('campo vazio continua vazio', () => {
    it('não vira 0,00 na abertura da tela', () => {
        // Se virasse, todo formulário abriria preenchido e o `required` do HTML
        // pararia de proteger.
        expect(campoLigado({ valor: '' }).value).toBe('');
    });

    it('não vira 0,00 no blur de um campo intocado', () => {
        const el = campoLigado();
        el.dispatchEvent(new Event('blur'));

        expect(el.value).toBe('');
    });

    it('volta a vazio quando o usuário apaga tudo', () => {
        const el = campoLigado();
        digitar(el, '123');

        expect(colar(el, '')).toBe('');
    });

    it('esvazia quando sobra só pontuação', () => {
        const el = campoLigado();
        digitar(el, '123');

        expect(colar(el, ',')).toBe('');
    });
});

describe('valor que vem do servidor (edição)', () => {
    it('normaliza uma vez na abertura, no formato que a digitação produz', () => {
        expect(campoLigado({ valor: '1.234,56' }).value).toBe('1.234,56');
        expect(campoLigado({ valor: '0,00' }).value).toBe('0,00');
        expect(campoLigado({ valor: '1234.56' }).value).toBe('1.234,56');
    });

    it('normaliza no blur o que foi preenchido por script (autofill não dispara input)', () => {
        const el = campoLigado();
        el.value = '1234.56';
        el.dispatchEvent(new Event('blur'));

        expect(el.value).toBe('1.234,56');
    });

    it('reaplicar a máscara em valor já formatado não muda nada (idempotente)', () => {
        const el = campoLigado({ valor: '1.234,56' });

        expect(colar(el, '1.234,56')).toBe('1.234,56');
        el.dispatchEvent(new Event('blur'));
        expect(el.value).toBe('1.234,56');
    });
});

describe('teto de dígitos', () => {
    const TETO = '999.999.999.999,99';

    it('aceita 14 dígitos — o valor máximo do servidor', () => {
        expect(colar(campoLigado(), '9'.repeat(14))).toBe(TETO);
    });

    it('ignora o dígito que passa do teto: o campo simplesmente não muda', () => {
        const el = campoLigado();
        digitar(el, '9'.repeat(14));

        expect(digitar(el, '9')).toBe(TETO);
        expect(digitar(el, '1234')).toBe(TETO);
    });

    it('corta pela ESQUERDA ao colar um número grande demais', () => {
        // `slice(0, 14)` guarda os PRIMEIROS dígitos, então o que sobra é o
        // começo do número colado — e não os centavos dele.
        expect(colar(campoLigado(), '12345678901234567890')).toBe('123.456.789.012,34');
    });

    it('zeros à esquerda não consomem a cota de dígitos', () => {
        // Os zeros caem ANTES do corte, então 4 zeros + 14 noves ainda cabem.
        expect(colar(campoLigado(), '0000'.concat('9'.repeat(14)))).toBe(TETO);
    });

    it('o teto é o mesmo TETO_MONETARIO do servidor', () => {
        // Guarda de regressão dos dois lados: se o PHP subir/baixar o teto e a
        // máscara ficar para trás, a tela passa a aceitar (ou recusar) um valor
        // que o servidor trata ao contrário.
        // Caminho a partir da raiz do projeto — é de lá que o Vitest roda.
        const php = readFileSync(
            resolve(process.cwd(), 'app/Http/Requests/Concerns/NormalizesMoneyInput.php'),
            'utf8',
        );
        const teto = php.match(/TETO_MONETARIO\s*=\s*'([\d.]+)'/)?.[1];

        expect(teto).toBeDefined();
        expect(colar(campoLigado(), teto.replace('.', ''))).toBe(
            Number(teto).toLocaleString('pt-BR', { minimumFractionDigits: 2 }),
        );
        expect(TETO).toBe(Number(teto).toLocaleString('pt-BR', { minimumFractionDigits: 2 }));
    });
});

describe('cursor', () => {
    it('vai para o fim depois de cada tecla', () => {
        // A máscara reescreve a string inteira; manter a posição antiga deixaria
        // o cursor no meio de um separador que acabou de se mover.
        const el = campoLigado();
        digitar(el, '130000');

        expect(el.value).toBe('1.300,00');
        expect(el.selectionStart).toBe(el.value.length);
        expect(el.selectionEnd).toBe(el.value.length);
    });

    it('não quebra quando o campo não suporta seleção de texto', () => {
        // `setSelectionRange?.()` existe por causa disto: em input `type=number`
        // o método não está disponível (ou lança), e um erro aqui mataria o
        // resto do listener.
        const el = montarCampo();
        el.setSelectionRange = undefined;
        initMoney();

        expect(digitar(el, '123')).toBe('1,23');
    });
});
