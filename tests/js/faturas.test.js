import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { flush } from './helpers/flush.js';

import { initFaturas } from '../../resources/js/sm/faturas.js';
import { abrirDialogo, fecharDialogo, liberarDialogosOrfaos } from '../../resources/js/sm/dialogo.js';

/**
 * Tela Pagar despesas (`resources/js/sm/faturas.js`) — os cinco modais viraram DIÁLOGOS
 * (achado A-2 da auditoria de acessibilidade, a última tela que faltava).
 *
 * Antes cada modal abria e fechava pela classe `.open`: o foco ficava na página por trás
 * do véu, o Tab passeava pela sidebar e pela barra de cookies, e cada modal prendia um
 * ouvinte de Esc no DOCUMENTO — um a mais a cada visita pelo pjax — que fechava tudo de
 * uma vez, inclusive o "Pagar fatura" que estava por baixo do "De onde sai esse dinheiro?".
 * O de editar conta fixa morava num script inline da view, com o mesmo ouvinte.
 *
 * Agora todos passam por `abrirDialogo`/`fecharDialogo` (sm/dialogo.js): foco dentro,
 * página inerte, Esc fecha só o do topo e o foco volta ao botão que abriu. O DOM imita o
 * `faturas/index.blade.php`: os gatilhos no #content e os cinco modais logo depois, fora
 * da `.card`. O jsdom não implementa `inert` nem o Tab do navegador — o que se confere é o
 * atributo e para onde o foco vai.
 */

function modal(id, titulo, corpo, rodape) {
    return `
        <div class="modal-scrim" id="${id}">
            <div class="modal" role="dialog" aria-modal="true" aria-labelledby="${id}-titulo">
                <div class="modal-head">
                    <h3 id="${id}-titulo">${titulo}</h3>
                    ${rodape.x}
                </div>
                ${corpo}
            </div>
        </div>`;
}

function montarPagina({ reabrirLancar = false } = {}) {
    document.body.innerHTML = `
        <div class="app" id="app">
            <aside class="sidebar" id="sidebar"><a href="/metas" id="linkSidebar">Metas</a></aside>
            <main class="content" id="content">
                <section class="view">
                    <button class="btn-primary" type="button" id="lancarBtn">Lançar despesa</button>
                    <button class="btn primary" type="button" id="pagarFatura" data-pay-open
                            data-action="/faturas/cartao/7/pagar" data-name="Nubank" data-amount="R$ 300,00"
                            data-ciclo="aberto" data-min="2026-09-01">Marcar como paga</button>
                    <button class="btn primary" type="button" id="pagarFechada" data-pay-open
                            data-action="/faturas/cartao/7/pagar" data-name="Nubank" data-amount="R$ 900,00"
                            data-ciclo="fechado" data-min="">Pagar fatura vencida</button>
                    <button class="btn primary" type="button" id="pagarFixa" data-fixa-pagar
                            data-action="/contas-fixas/3/pagar/2026-09" data-nome="Condomínio" data-valor="470,00"
                            data-conta="2" data-min="2026-08-01">Pagar</button>
                    <button class="fi-rm fi-ed" type="button" id="editarFixa" data-fixa-editar aria-label="Editar conta fixa"
                            data-action="/contas-fixas/3" data-nome="Condomínio" data-valor="470,00" data-dia="5"
                            data-conta="2" data-categoria="9" data-inicio="2026-09-01" data-fim="2027-08-31">✎</button>
                    <button class="btn ghost" type="button" id="novaContaFixaBtn">Nova conta fixa</button>
                </section>

                ${modal('lancarModal', 'Lançar despesa', `
                    <form method="POST" action="/faturas/lancar" id="lancarForm">
                        <div class="modal-body">
                            <input class="input" type="text" id="lanc-desc" name="description">
                            <input class="input" type="text" id="lanc-valor" name="amount" inputmode="decimal">
                            <select class="input" id="lanc-method" name="account_id">
                                <option value="7" data-card="1">Nubank</option>
                                <option value="2" data-card="0">Corrente</option>
                            </select>
                            <div class="pay-mode" id="lancModes">
                                <button type="button" class="active" data-m="avista">À vista</button>
                                <button type="button" data-m="parcelado">Parcelado</button>
                                <button type="button" data-m="recorrente">Recorrente</button>
                            </div>
                            <input type="hidden" name="mode" id="lanc-mode" value="avista">
                            <small data-lanc-modehint></small>
                            <div id="lancParcelasField" hidden>
                                <select id="lanc-parcelas" name="installments"><option value="2">2x</option><option value="3">3x</option></select>
                                <small id="lancParcelaHint"></small>
                            </div>
                        </div>
                        <div class="modal-foot">
                            <button class="btn ghost" type="button" data-lancar-close id="lancarCancelar">Cancelar</button>
                            <button class="btn primary" type="submit">Lançar despesa</button>
                        </div>
                    </form>`, { x: '<button class="modal-x" type="button" data-lancar-close id="lancarX">×</button>' })}

                ${modal('fixaPagarModal', 'Pagar conta fixa', `
                    <form method="POST" action="" data-fixa-form>
                        <div class="modal-body">
                            <p><b data-fixa-nome></b> — o valor sai da conta escolhida.</p>
                            <input class="input" type="text" id="fixa-valor" name="amount" inputmode="decimal">
                            <input class="input" type="date" id="fixa-data" name="paid_on" value="2026-09-20" max="2026-09-20">
                            <select class="input" id="fixa-conta" name="account_id">
                                <option value="1">Poupança</option>
                                <option value="2">Corrente</option>
                            </select>
                        </div>
                        <div class="modal-foot">
                            <button class="btn ghost" type="button" data-fixa-close id="fixaCancelar">Cancelar</button>
                            <button class="btn primary" type="submit">Confirmar pagamento</button>
                        </div>
                    </form>`, { x: '<button class="modal-x" type="button" data-fixa-close>×</button>' })}

                ${modal('fixaNovaModal', 'Nova conta fixa', `
                    <form method="POST" action="/contas-fixas">
                        <div class="modal-body">
                            <input class="input" type="text" id="cf-nome" name="name">
                            <input class="input" type="text" id="cf-valor" name="amount" inputmode="decimal">
                        </div>
                        <div class="modal-foot">
                            <button class="btn ghost" type="button" data-fixanova-close id="novaCancelar">Cancelar</button>
                            <button class="btn primary" type="submit">Cadastrar</button>
                        </div>
                    </form>`, { x: '<button class="modal-x" type="button" data-fixanova-close>×</button>' })}

                ${modal('fixaEditarModal', 'Editar conta fixa', `
                    <form method="POST" action="" data-fixaedit-form>
                        <input type="hidden" name="_method" value="PATCH">
                        <div class="modal-body">
                            <input class="input" type="text" id="cfe-nome" name="name">
                            <input class="input" type="text" id="cfe-valor" name="amount" inputmode="decimal">
                            <input class="input" type="number" id="cfe-dia" name="due_day">
                            <select class="input" id="cfe-conta" name="account_id">
                                <option value="">Escolher na hora</option><option value="2">Corrente</option>
                            </select>
                            <select class="input" id="cfe-cat" name="category_id">
                                <option value="">Sem categoria</option><option value="9">Moradia</option>
                            </select>
                            <input class="input" type="date" id="cfe-inicio" name="starts_on">
                            <input class="input" type="date" id="cfe-fim" name="ends_on">
                        </div>
                        <div class="modal-foot">
                            <button class="btn ghost" type="button" data-fixaedit-close id="editarCancelar">Cancelar</button>
                            <button class="btn primary" type="submit">Salvar alterações</button>
                        </div>
                    </form>`, { x: '<button class="modal-x" type="button" data-fixaedit-close id="editarX">×</button>' })}

                ${modal('payInvoiceModal', 'Pagar fatura', `
                    <form method="POST" action="" data-pay-form>
                        <input type="hidden" name="_token" value="token">
                        <input type="hidden" name="ciclo" value="aberto" data-pay-ciclo>
                        <div class="modal-body">
                            <p class="pay-summary">Fatura de <b data-pay-name></b> — <b data-pay-amount></b></p>
                            <select class="input" id="pay-account" name="pay_account_id">
                                <option value="2">Corrente</option>
                            </select>
                            <input class="input" type="date" id="pay-data" name="paid_on" value="2026-09-20" max="2026-09-20">
                        </div>
                        <div class="modal-foot">
                            <button class="btn ghost" type="button" data-pay-close id="payCancelar">Cancelar</button>
                            <button class="btn primary" type="submit" id="payConfirmar">Confirmar pagamento</button>
                        </div>
                    </form>`, { x: '<button class="modal-x" type="button" data-pay-close id="payX">×</button>' })}
            </main>
        </div>
        <div class="modal-scrim" id="fundingModal">
            <div class="modal" role="dialog" aria-modal="true" aria-labelledby="fundingModal-titulo">
                <h3 id="fundingModal-titulo">De onde sai esse dinheiro?</h3>
                <div class="modal-foot"><button type="button" id="fonteCancelar">Cancelar</button></div>
            </div>
        </div>`;

    // Erro de validação do servidor: a view marca o modal de lançar para reabrir.
    document.getElementById('lancarModal').dataset.reopen = reabrirLancar ? '1' : '';
    initFaturas();
}

const $ = (id) => document.getElementById(id);
const aberto = (id) => $(id).classList.contains('open');

/** Tecla no elemento com foco, borbulhando até o documento (como o navegador). */
function tecla(key) {
    const alvo = document.activeElement || document.body;
    alvo.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }));
}

function enviar(form) {
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
}

beforeEach(() => {
    montarPagina();
});

afterEach(() => {
    document.querySelectorAll('.modal-scrim.open').forEach((s) => fecharDialogo(s, { devolverFoco: false }));
    liberarDialogosOrfaos();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    delete window.smPjaxReload;
});

describe('os cinco modais abrem e fecham como diálogos', () => {
    it('"Lançar despesa": foco na descrição, página inerte, Esc fecha e devolve o foco ao botão', () => {
        $('lancarBtn').click();

        expect(aberto('lancarModal')).toBe(true);
        expect(document.activeElement).toBe($('lanc-desc'));
        expect($('sidebar').hasAttribute('inert')).toBe(true);
        // O modal está DENTRO do #content: os irmãos dele ficam inertes, ele não.
        expect(document.querySelector('.view').hasAttribute('inert')).toBe(true);
        expect($('lancarModal').closest('[inert]')).toBeNull();

        tecla('Escape');

        expect(aberto('lancarModal')).toBe(false);
        expect(document.activeElement).toBe($('lancarBtn'));
        expect(document.querySelectorAll('[inert]')).toHaveLength(0);
    });

    it('"Marcar como paga": preenche o modal, foca "Debitar de" e o X devolve o foco ao botão', () => {
        $('pagarFatura').click();

        const modal = $('payInvoiceModal');
        expect(aberto('payInvoiceModal')).toBe(true);
        expect(modal.querySelector('[data-pay-form]').getAttribute('action')).toBe('/faturas/cartao/7/pagar');
        expect(modal.querySelector('[data-pay-name]').textContent).toBe('Nubank');
        expect(modal.querySelector('[data-pay-amount]').textContent).toBe('R$ 300,00');
        expect(modal.querySelector('[data-pay-ciclo]').value).toBe('aberto');
        expect($('pay-data').min).toBe('2026-09-01');
        expect(document.activeElement).toBe($('pay-account'));

        $('payX').click();

        expect(aberto('payInvoiceModal')).toBe(false);
        expect(document.activeElement).toBe($('pagarFatura'));
    });

    it('o mesmo modal pagando a fatura VENCIDA troca o ciclo e o piso da data', () => {
        $('pagarFatura').click();
        $('payCancelar').click();

        $('pagarFechada').click();

        expect($('payInvoiceModal').querySelector('[data-pay-ciclo]').value).toBe('fechado');
        expect($('pay-data').hasAttribute('min')).toBe(false);
        expect($('payInvoiceModal').querySelector('[data-pay-amount]').textContent).toBe('R$ 900,00');

        tecla('Escape');
        expect(document.activeElement).toBe($('pagarFechada'));
    });

    it('"Pagar" a conta fixa: foco no valor pago (já com o previsto); o clique no véu fecha', () => {
        $('pagarFixa').click();

        expect(aberto('fixaPagarModal')).toBe(true);
        expect(document.activeElement).toBe($('fixa-valor'));
        expect($('fixa-valor').value).toBe('470,00');
        expect($('fixa-conta').value).toBe('2');
        expect($('fixaPagarModal').querySelector('[data-fixa-nome]').textContent).toBe('Condomínio');

        $('fixaPagarModal').dispatchEvent(new MouseEvent('click', { bubbles: true }));

        expect(aberto('fixaPagarModal')).toBe(false);
        expect(document.activeElement).toBe($('pagarFixa'));
    });

    it('"Nova conta fixa": foco no nome; "Cancelar" fecha e devolve o foco', () => {
        $('novaContaFixaBtn').click();

        expect(aberto('fixaNovaModal')).toBe(true);
        expect(document.activeElement).toBe($('cf-nome'));

        $('novaCancelar').click();

        expect(aberto('fixaNovaModal')).toBe(false);
        expect(document.activeElement).toBe($('novaContaFixaBtn'));
    });

    it('lápis "Editar conta fixa": preenche tudo pelos data-* do botão e o Esc devolve o foco a ele', () => {
        $('editarFixa').click();

        const modal = $('fixaEditarModal');
        expect(aberto('fixaEditarModal')).toBe(true);
        expect(modal.querySelector('[data-fixaedit-form]').getAttribute('action')).toBe('/contas-fixas/3');
        expect([
            $('cfe-nome').value, $('cfe-valor').value, $('cfe-dia').value, $('cfe-conta').value,
            $('cfe-cat').value, $('cfe-inicio').value, $('cfe-fim').value,
        ]).toEqual(['Condomínio', '470,00', '5', '2', '9', '2026-09-01', '2027-08-31']);
        expect(document.activeElement).toBe($('cfe-nome'));

        tecla('Escape');

        expect(aberto('fixaEditarModal')).toBe(false);
        expect(document.activeElement).toBe($('editarFixa'));
    });
});

describe('o Esc é do utilitário de diálogo', () => {
    it('com o "De onde sai esse dinheiro?" por cima, o primeiro Esc fecha SÓ ele', () => {
        $('pagarFatura').click();
        abrirDialogo($('fundingModal'), { retorno: $('payConfirmar') });

        tecla('Escape');

        expect(aberto('fundingModal')).toBe(false);
        expect(aberto('payInvoiceModal'), 'Antes o ouvinte de Esc da tela fechava o de baixo junto.').toBe(true);
        expect(document.activeElement).toBe($('payConfirmar'));

        tecla('Escape');
        expect(aberto('payInvoiceModal')).toBe(false);
    });

    it('visitar a tela de novo (pjax) não empilha ouvintes de Esc no documento', () => {
        const noDocumento = vi.spyOn(document, 'addEventListener');

        montarPagina();
        montarPagina();

        const deTeclado = noDocumento.mock.calls.filter(([tipo]) => tipo === 'keydown');
        expect(deTeclado).toHaveLength(0);
    });
});

describe('reabrir depois de erro de validação', () => {
    it('o modal de lançar reabre sozinho — e fechar devolve o foco ao botão do topo, não ao <body>', () => {
        document.querySelectorAll('.modal-scrim.open').forEach((s) => fecharDialogo(s, { devolverFoco: false }));
        montarPagina({ reabrirLancar: true });

        expect(aberto('lancarModal')).toBe(true);
        expect(document.activeElement).toBe($('lanc-desc'));

        $('lancarCancelar').click();
        expect(document.activeElement).toBe($('lancarBtn'));
    });
});

describe('pagamento por AJAX dentro do diálogo', () => {
    it('deu certo: fecha o diálogo e recarrega o conteúdo pelo pjax', async () => {
        window.smPjaxReload = vi.fn();
        const chamadas = [];
        vi.stubGlobal('fetch', vi.fn(async (url, init) => {
            chamadas.push([new URL(url, window.location.href).pathname, init.body.get('ciclo')]);
            return { status: 0, ok: false, type: 'opaqueredirect' };
        }));

        $('pagarFatura').click();
        enviar($('payInvoiceModal').querySelector('[data-pay-form]'));
        await flush();

        expect(chamadas).toEqual([['/faturas/cartao/7/pagar', 'aberto']]);
        expect(aberto('payInvoiceModal')).toBe(false);
        expect(window.smPjaxReload).toHaveBeenCalledTimes(1);
        expect(document.querySelectorAll('[inert]')).toHaveLength(0);
    });

    it('recusado (422): o diálogo fica aberto, com a mensagem do servidor como TEXTO', async () => {
        const msg = 'A data do pagamento não pode ser anterior à compra mais antiga da fatura.';
        vi.stubGlobal('fetch', vi.fn(async () => ({
            status: 422, ok: false, type: 'basic', json: async () => ({ errors: { paid_on: [msg] } }),
        })));

        $('pagarFatura').click();
        enviar($('payInvoiceModal').querySelector('[data-pay-form]'));
        await flush();

        expect(aberto('payInvoiceModal')).toBe(true);
        const erro = $('payInvoiceModal').querySelector('[data-erro-pagamento]');
        expect(erro.textContent).toBe(msg);
        expect(erro.getAttribute('role')).toBe('alert');
        expect($('payConfirmar').disabled).toBe(false);
    });
});
