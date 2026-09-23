import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { initConfirmar } from '../../resources/js/sm/confirmar.js';

/**
 * "Tem certeza?" antes de excluir (`resources/js/sm/confirmar.js`).
 *
 * Desde a CSP com nonce (05/08/2026), os `onsubmit="return confirm(...)"` das telas eram
 * BLOQUEADOS pelo navegador — atributo de evento não aceita nonce —, e excluir categoria,
 * conta ou dependente passou a acontecer sem pergunta nenhuma. O substituto é o atributo
 * `data-confirmar` no formulário e um ouvinte de `submit` delegado no documento.
 *
 * O que se observa aqui é o evento: se o envio foi cancelado (`defaultPrevented`) e se os
 * ouvintes do próprio formulário chegaram a rodar.
 */

function formulario(mensagem, id = 'excluir') {
    const atributo = mensagem === null ? '' : `data-confirmar="${mensagem}"`;
    return `
        <form method="POST" action="/categories/1" id="${id}" ${atributo}>
            <input type="hidden" name="_method" value="DELETE">
            <button type="submit">Excluir</button>
        </form>`;
}

/** Dispara o submit como o navegador: borbulhando e cancelável. Devolve o evento. */
function enviar(form) {
    const evento = new Event('submit', { bubbles: true, cancelable: true });
    form.dispatchEvent(evento);
    return evento;
}

let pergunta;

beforeEach(() => {
    pergunta = vi.spyOn(window, 'confirm');
    document.body.innerHTML = `<main id="content">${formulario('Excluir a categoria Mercado? As transações dela ficarão sem categoria.')}</main>`;
    initConfirmar();
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('form[data-confirmar]', () => {
    it('desistir NÃO envia — e os ouvintes do próprio formulário nem chegam a rodar', () => {
        pergunta.mockReturnValue(false);
        const form = document.getElementById('excluir');
        const ouvinteDoForm = vi.fn();
        form.addEventListener('submit', ouvinteDoForm);

        const evento = enviar(form);

        expect(pergunta).toHaveBeenCalledWith('Excluir a categoria Mercado? As transações dela ficarão sem categoria.');
        expect(evento.defaultPrevented).toBe(true);
        // Ex.: o "trava o botão durante o envio" das metas não pode travar um envio que
        // não vai acontecer.
        expect(ouvinteDoForm).not.toHaveBeenCalled();
    });

    it('confirmar deixa o envio seguir', () => {
        pergunta.mockReturnValue(true);
        const form = document.getElementById('excluir');
        const ouvinteDoForm = vi.fn();
        form.addEventListener('submit', ouvinteDoForm);

        const evento = enviar(form);

        expect(evento.defaultPrevented).toBe(false);
        expect(ouvinteDoForm).toHaveBeenCalledTimes(1);
    });

    it('a pergunta é o TEXTO do atributo — um apóstrofo no nome não quebra nada', () => {
        pergunta.mockReturnValue(false);
        // O `confirm('Remover {{ $nome }}?')` de antes punha o nome numa string JS: com
        // "D'Ávila", o `&#039;` voltava a ser `'` na leitura do HTML e fechava a string.
        document.getElementById('content').innerHTML = formulario('Remover Ana D&#039;Ávila? O acesso dela será excluído.', 'dependente');

        enviar(document.getElementById('dependente'));

        expect(pergunta).toHaveBeenCalledWith("Remover Ana D'Ávila? O acesso dela será excluído.");
    });

    it('formulário sem o atributo não pergunta nada', () => {
        document.getElementById('content').innerHTML = formulario(null, 'salvar');

        const evento = enviar(document.getElementById('salvar'));

        expect(pergunta).not.toHaveBeenCalled();
        expect(evento.defaultPrevented).toBe(false);
    });

    it('o formulário que chega pelo pjax (o #content trocado inteiro) também pergunta', () => {
        pergunta.mockReturnValue(false);
        document.getElementById('content').innerHTML = formulario('Excluir Corrente Itaú? Contas com transações não podem ser excluídas.', 'conta');

        const evento = enviar(document.getElementById('conta'));

        expect(pergunta).toHaveBeenCalledWith('Excluir Corrente Itaú? Contas com transações não podem ser excluídas.');
        expect(evento.defaultPrevented).toBe(true);
    });

    it('ligar de novo (outro init) não faz perguntar duas vezes', () => {
        pergunta.mockReturnValue(true);
        initConfirmar();
        initConfirmar();

        enviar(document.getElementById('excluir'));

        expect(pergunta).toHaveBeenCalledTimes(1);
    });
});
