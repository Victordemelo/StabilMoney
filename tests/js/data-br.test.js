import { afterEach, describe, expect, it, vi } from 'vitest';
import { brParaIso, isoParaBr, mascarar, melhorarCamposDeData, problemaDaData } from '../../resources/js/sm/data-br.js';

/**
 * Datas no padrão brasileiro (out/2026): o campo de data do navegador segue o idioma do
 * aparelho — num Android em inglês, o "Meu perfil" pedia mm/dd/yyyy. O texto com máscara
 * mostra dd/mm/aaaa e o campo original (escondido, com o `name`) segue mandando AAAA-MM-DD.
 */

const digitar = (campo, texto) => {
    campo.value = texto;
    campo.dispatchEvent(new Event('input', { bubbles: true }));
};

afterEach(() => {
    document.body.innerHTML = '';
    delete HTMLInputElement.prototype.showPicker;
});

describe('Conversões', () => {
    it('ISO ↔ brasileiro, para data e para mês', () => {
        expect(isoParaBr('2003-12-22')).toBe('22/12/2003');
        expect(brParaIso('22/12/2003')).toBe('2003-12-22');
        expect(isoParaBr('2027-02', 'month')).toBe('02/2027');
        expect(brParaIso('02/2027', 'month')).toBe('2027-02');
        expect(isoParaBr('')).toBe('');
        expect(isoParaBr('lixo')).toBe('');
    });

    it('data que não existe não vira ISO', () => {
        expect(brParaIso('31/02/2026')).toBeNull();
        expect(brParaIso('29/02/2026')).toBeNull();
        expect(brParaIso('29/02/2028')).toBe('2028-02-29');
        expect(brParaIso('00/01/2026')).toBeNull();
        expect(brParaIso('10/13/2026')).toBeNull();
        expect(brParaIso('13/2026', 'month')).toBeNull();
        expect(brParaIso('22/12/20')).toBeNull();
    });

    it('a máscara põe as barras e para no tamanho certo', () => {
        expect(mascarar('22')).toBe('22');
        expect(mascarar('2212')).toBe('22/12');
        expect(mascarar('221220031')).toBe('22/12/2003');
        expect(mascarar('22/12/2003')).toBe('22/12/2003');
        expect(mascarar('a2b2')).toBe('22');
        expect(mascarar('022027', 'month')).toBe('02/2027');
    });

    it('diz o porquê de a data não valer, inclusive min/max', () => {
        expect(problemaDaData('', 'date')).toBe('');
        expect(problemaDaData('', 'date', { obrigatorio: true })).toBe('Preencha a data.');
        expect(problemaDaData('22/12', 'date')).toContain('dd/mm/aaaa');
        expect(problemaDaData('31/02/2026', 'date')).toBe('Essa data não existe.');
        expect(problemaDaData('01/01/1899', 'date', { min: '1900-01-01' })).toBe('A data não pode ser antes de 01/01/1900.');
        expect(problemaDaData('08/10/2026', 'date', { max: '2026-10-07' })).toBe('A data não pode ser depois de 07/10/2026.');
        expect(problemaDaData('07/10/2026', 'date', { max: '2026-10-07' })).toBe('');
    });
});

describe('O campo na página', () => {
    const montar = (html) => {
        document.body.innerHTML = `<form id="f">${html}</form>`;
        melhorarCamposDeData(document);
        const form = document.getElementById('f');
        return { form, visivel: form.querySelector('.data-br > input:not([name])'), original: form.querySelector('[name]') };
    };

    it('mostra dd/mm/aaaa e manda AAAA-MM-DD com o mesmo name', () => {
        const { form, visivel, original } = montar('<label for="nasc">Nascimento</label><input class="input" type="date" id="nasc" name="birth_date" value="2003-12-22" max="2026-10-07">');

        expect(visivel.value).toBe('22/12/2003');
        expect(visivel.placeholder).toBe('dd/mm/aaaa');
        expect(visivel.inputMode).toBe('numeric');
        expect(visivel.className).toBe('input');
        expect(original.hidden).toBe(true);
        expect(document.querySelector('label').htmlFor).toBe(visivel.id);

        digitar(visivel, '01011990');
        expect(visivel.value).toBe('01/01/1990');
        expect(new FormData(form).get('birth_date')).toBe('1990-01-01');
        expect([...new FormData(form).keys()]).toEqual(['birth_date']);
    });

    it('data inválida ou fora do limite não chega ao servidor e marca o campo', () => {
        const { form, visivel } = montar('<input type="date" name="d" value="2026-10-01" max="2026-10-07">');

        digitar(visivel, '31/02/2026');
        expect(new FormData(form).get('d')).toBe('');
        expect(visivel.validationMessage).toBe('Essa data não existe.');

        digitar(visivel, '08/10/2026');
        expect(visivel.validationMessage).toBe('A data não pode ser depois de 07/10/2026.');

        digitar(visivel, '06/10/2026');
        expect(visivel.validationMessage).toBe('');
        expect(new FormData(form).get('d')).toBe('2026-10-06');
    });

    it('o min trocado por código depois (Contas a pagar) vale na hora', () => {
        const { visivel, original } = montar('<input type="date" name="paid_on">');
        original.min = '2026-09-01';

        digitar(visivel, '31/08/2026');
        expect(visivel.validationMessage).toBe('A data não pode ser antes de 01/09/2026.');
    });

    it('obrigatório continua obrigatório (no campo que a pessoa vê)', () => {
        const { form, visivel, original } = montar('<input type="date" name="date" required>');
        expect(visivel.required).toBe(true);
        expect(original.required).toBe(false);
        expect(form.checkValidity()).toBe(false);
        digitar(visivel, '07/10/2026');
        expect(form.checkValidity()).toBe(true);
    });

    it('valor posto por código aparece no texto, e quem ouve o original fica sabendo da digitação', () => {
        const { visivel, original } = montar('<input type="date" name="date">');
        original.value = '2026-10-07';
        expect(visivel.value).toBe('07/10/2026');

        const ouvido = vi.fn();
        original.addEventListener('change', ouvido);
        digitar(visivel, '05/10/2026');
        expect(ouvido).toHaveBeenCalledTimes(1);
        expect(original.value).toBe('2026-10-05');
    });

    it('form.reset() volta o texto ao valor do servidor', async () => {
        const { form, visivel } = montar('<input type="date" name="date" value="2026-10-07">');
        digitar(visivel, '01/01/2026');
        form.reset();
        await new Promise((r) => setTimeout(r));
        expect(visivel.value).toBe('07/10/2026');
        expect(new FormData(form).get('date')).toBe('2026-10-07');
    });

    it('campo de mês vira mm/aaaa', () => {
        const { form, visivel } = montar('<input type="month" name="target_date" value="2027-02">');
        expect(visivel.value).toBe('02/2027');
        expect(visivel.placeholder).toBe('mm/aaaa');
        digitar(visivel, '122028');
        expect(new FormData(form).get('target_date')).toBe('2028-12');
    });

    it('rodar de novo (troca por pjax) não embrulha duas vezes', () => {
        montar('<input type="date" name="date">');
        melhorarCamposDeData(document);
        expect(document.querySelectorAll('.data-br')).toHaveLength(1);
    });

    it('com o calendário do navegador disponível, o botão o abre e a escolha preenche o texto', () => {
        const abrir = vi.fn();
        HTMLInputElement.prototype.showPicker = abrir;
        const { form, visivel } = montar('<input type="date" name="date" min="2026-01-01">');
        const botao = document.querySelector('.data-br-cal');
        const nativo = document.querySelector('.data-br-nativo');

        expect(botao.getAttribute('aria-label')).toBe('Abrir o calendário');
        expect(nativo.name).toBe('');
        botao.click();
        expect(abrir).toHaveBeenCalledTimes(1);
        expect(nativo.min).toBe('2026-01-01');

        nativo.value = '2026-03-15';
        nativo.dispatchEvent(new Event('change'));
        expect(visivel.value).toBe('15/03/2026');
        expect(new FormData(form).get('date')).toBe('2026-03-15');
    });

    it('sem o calendário do navegador, fica só a digitação (sem botão quebrado)', () => {
        montar('<input type="date" name="date">');
        expect(document.querySelector('.data-br-cal')).toBeNull();
    });
});
