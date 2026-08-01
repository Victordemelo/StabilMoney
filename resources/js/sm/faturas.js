// Página de Faturas / Despesas (design v2): faturas por cartão, parcelas,
// despesas em conta e o modal "Lançar despesa".
//
// O app é server-routed — o lançamento é um <form> Laravel real (POST faturas.lancar)
// e a remoção de compra também (DELETE via form inline). Este módulo cuida só da UI:
//   • abrir/fechar o modal de lançar (.modal-scrim → classe .open), com Esc e clique no véu;
//   • o TOGGLE de modo (À vista / Parcelado / Recorrente): só faz sentido em cartão —
//     quando o método não é cartão, força "à vista" e desabilita os outros (espelha
//     lanc.syncMethod/syncMode do finance.js); o campo de parcelas aparece só em parcelado;
//   • o hint "Nx de R$ Y (sem juros)" recalculado ao mudar valor/parcelas (lanc.syncHint);
//   • reabrir o modal com os dados digitados quando a validação do servidor volta com erro.
//
// Só roda na tela de faturas (guard pelo modal de lançar da view).

import { enviarComFonte, respostaOk } from './funding';

const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));

// ---- Erro dentro de um modal de pagamento (a view não tem caixa de erro) ----
// textContent sempre: nunca interpolar mensagem do servidor em innerHTML.
function erroNoModal(modal, msg) {
    const body = modal.querySelector('.modal-body');
    if (!body) return;
    let box = modal.querySelector('[data-erro-pagamento]');
    if (!box) {
        box = document.createElement('div');
        box.className = 'flash-error';
        box.setAttribute('role', 'alert');
        box.dataset.erroPagamento = '1';
        body.prepend(box);
    }
    box.textContent = msg;
    box.hidden = false;
}

function limparErro(modal) {
    const box = modal.querySelector('[data-erro-pagamento]');
    if (box) box.hidden = true;
}

/**
 * Faz um formulário de pagamento (fatura ou conta fixa) enviar por AJAX,
 * passando pelo modal "de onde sai esse dinheiro?" quando o servidor responde
 * 409 — o mesmo caminho do modal global "Lançar".
 *
 * Antes eram POST comum: quem tinha cheque especial clicava em "Confirmar
 * pagamento", o servidor redirecionava com `fonteNecessaria` na sessão e a
 * tela não mostrava NADA (achado A-2 da auditoria de 27/07/2026).
 */
function ligarPagamentoAjax(form, modal, fechar) {
    if (!form || form.dataset.fundingBound) return;
    form.dataset.fundingBound = '1';

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        limparErro(modal);

        const botao = form.querySelector('button[type="submit"]');
        const rotulo = botao ? botao.textContent : '';
        const carregando = (on) => {
            if (!botao) return;
            botao.disabled = on;
            botao.textContent = on ? 'Pagando…' : rotulo;
        };

        carregando(true);

        const dados = new FormData(form);
        let resp;
        try {
            resp = await enviarComFonte(form.action, dados);
        } catch (_) {
            carregando(false);
            erroNoModal(modal, 'Sem conexão. Verifique sua internet e tente de novo.');
            return;
        }

        // null = o usuário fechou o modal de escolha; nada foi pago.
        if (resp === null) { carregando(false); return; }

        if (respostaOk(resp)) {
            fechar();
            // Recarrega para refletir saldo/fatura (e mostrar o flash do servidor).
            if (typeof window.smPjaxReload === 'function') window.smPjaxReload();
            else window.location.reload();
            return;
        }

        carregando(false);
        let msg = 'Não foi possível concluir o pagamento. Confira os campos e tente de novo.';
        if (resp.status === 422) {
            const data = await resp.json().catch(() => ({}));
            msg = Object.values(data?.errors || {})[0]?.[0] || data?.message || msg;
        } else if (resp.status === 419) {
            msg = 'Sua sessão expirou. Recarregue a página e tente de novo.';
        }
        erroNoModal(modal, msg);
    });
}

// Converte "1.234,56" / "R$ 80" em número (espelha parseMoney do protótipo).
function parseMoney(str) {
    if (str == null) return 0;
    const limpo = String(str).replace(/[^0-9,.-]/g, '').replace(/\.(?=\d{3}(\D|$))/g, '').replace(',', '.');
    const n = parseFloat(limpo);
    return Number.isFinite(n) ? n : 0;
}

// Formata número em R$ pt-BR com centavos (espelha BRL do protótipo).
function brl(v) {
    return (Number(v) || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export function initFaturas() {
    // A view de faturas tem sempre o modal de lançar; sem ele, não é esta tela.
    const modal = document.getElementById('lancarModal');
    if (!modal) return;

    const abrir = () => {
        modal.classList.add('open');
        const desc = document.getElementById('lanc-desc');
        if (desc) setTimeout(() => desc.focus(), 120);
    };
    const fechar = () => modal.classList.remove('open');

    // ---- Abrir (botão do topo + botão do estado vazio) ----
    ['lancarBtn', 'lancarBtnVazio'].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('click', abrir);
    });

    // ---- Fechar: véu, X e "Cancelar" ----
    modal.addEventListener('click', (e) => { if (e.target === modal) fechar(); });
    $$('[data-lancar-close]', modal).forEach((btn) => btn.addEventListener('click', fechar));
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') fechar(); });

    // ---- Elementos do modal ----
    const methodSel   = document.getElementById('lanc-method');
    const modeButtons = $$('#lancModes button');
    const modeInput   = document.getElementById('lanc-mode');         // hidden enviado ao servidor
    const modeHint    = modal.querySelector('[data-lanc-modehint]');
    const parcelasField = document.getElementById('lancParcelasField');
    const parcelasSel = document.getElementById('lanc-parcelas');
    const parcelaHint = document.getElementById('lancParcelaHint');
    const valorInput  = document.getElementById('lanc-valor');

    // Método selecionado é cartão? (data-card="1" na <option>)
    const metodoEhCartao = () => {
        const opt = methodSel ? methodSel.selectedOptions[0] : null;
        return !!(opt && opt.dataset.card === '1');
    };

    // Modo atual (lê do hidden; default "avista").
    const modoAtual = () => (modeInput ? modeInput.value : 'avista') || 'avista';

    // Pinta o botão ativo + mostra/esconde parcelas + atualiza hints.
    const syncMode = () => {
        const modo = modoAtual();
        modeButtons.forEach((b) => b.classList.toggle('active', b.dataset.m === modo));
        if (parcelasField) parcelasField.hidden = modo !== 'parcelado';
        syncHint();
    };

    // Habilita parcelado/recorrente só em cartão; fora de cartão, força "à vista".
    const syncMethod = () => {
        const isCard = metodoEhCartao();
        modeButtons.forEach((b) => {
            const soCartao = b.dataset.m !== 'avista';
            b.disabled = soCartao && !isCard;
            b.classList.toggle('is-disabled', soCartao && !isCard);
        });
        if (!isCard && modeInput) modeInput.value = 'avista';
        if (modeHint) {
            modeHint.textContent = isCard
                ? ''
                : 'Parcelado e recorrente disponíveis apenas para cartão de crédito.';
        }
        syncMode();
    };

    // Hint "Nx de R$ Y (sem juros)" no campo de parcelas.
    const syncHint = () => {
        if (!parcelaHint) return;
        if (modoAtual() !== 'parcelado') { parcelaHint.textContent = ''; return; }
        const total = parseMoney(valorInput ? valorInput.value : '');
        const n = parseInt(parcelasSel ? parcelasSel.value : '2', 10) || 2;
        parcelaHint.textContent = total > 0 ? `${n}x de R$ ${brl(total / n)} (sem juros)` : '';
    };

    // ---- Eventos ----
    if (methodSel) methodSel.addEventListener('change', syncMethod);
    modeButtons.forEach((b) => b.addEventListener('click', () => {
        if (b.disabled) return;
        if (modeInput) modeInput.value = b.dataset.m;
        syncMode();
    }));
    if (parcelasSel) parcelasSel.addEventListener('change', syncHint);
    if (valorInput) valorInput.addEventListener('input', syncHint);

    // Estado inicial (respeita old() após erro de validação).
    syncMethod();

    // ---- Modal "Pagar fatura" (marcar fatura do cartão como paga) ----
    const payModal = document.getElementById('payInvoiceModal');
    if (payModal) {
        const payForm = payModal.querySelector('[data-pay-form]');
        const payName = payModal.querySelector('[data-pay-name]');
        const payAmount = payModal.querySelector('[data-pay-amount]');
        const abrirPay = () => payModal.classList.add('open');
        const fecharPay = () => payModal.classList.remove('open');

        ligarPagamentoAjax(payForm, payModal, fecharPay);

        $$('[data-pay-open]').forEach((btn) => btn.addEventListener('click', () => {
            limparErro(payModal);
            if (payForm) payForm.setAttribute('action', btn.dataset.action || '');
            if (payName) payName.textContent = btn.dataset.name || '';
            if (payAmount) payAmount.textContent = btn.dataset.amount || '';
            abrirPay();
        }));
        payModal.addEventListener('click', (e) => { if (e.target === payModal) fecharPay(); });
        $$('[data-pay-close]', payModal).forEach((b) => b.addEventListener('click', fecharPay));
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') fecharPay(); });
    }

    // ---- Modal "Pagar conta fixa" (uma competência de uma conta mensal) ----
    const fixaModal = document.getElementById('fixaPagarModal');
    if (fixaModal) {
        const fixaForm = fixaModal.querySelector('[data-fixa-form]');
        const fixaNome = fixaModal.querySelector('[data-fixa-nome]');
        const fixaValor = fixaModal.querySelector('#fixa-valor');
        const fixaConta = fixaModal.querySelector('#fixa-conta');
        const fecharFixa = () => fixaModal.classList.remove('open');

        ligarPagamentoAjax(fixaForm, fixaModal, fecharFixa);

        $$('[data-fixa-pagar]').forEach((btn) => btn.addEventListener('click', () => {
            limparErro(fixaModal);
            if (fixaForm) fixaForm.setAttribute('action', btn.dataset.action || '');
            if (fixaNome) fixaNome.textContent = btn.dataset.nome || '';
            // Vem com o valor PREVISTO; o usuário ajusta se a conta veio diferente.
            if (fixaValor) fixaValor.value = btn.dataset.valor || '';
            // E já seleciona o método de pagamento padrão da conta fixa, se houver.
            if (fixaConta && btn.dataset.conta) fixaConta.value = btn.dataset.conta;
            // Piso da data de pagamento: o servidor recusa data anterior ao mês
            // anterior à competência (senão a despesa sumia do fluxo de caixa).
            const fixaData = fixaModal.querySelector('#fixa-data');
            if (fixaData && btn.dataset.min) fixaData.min = btn.dataset.min;
            fixaModal.classList.add('open');
        }));
        fixaModal.addEventListener('click', (e) => { if (e.target === fixaModal) fecharFixa(); });
        $$('[data-fixa-close]', fixaModal).forEach((b) => b.addEventListener('click', fecharFixa));
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') fecharFixa(); });
    }

    // ---- Modal "Nova conta fixa" ----
    const novaFixa = document.getElementById('fixaNovaModal');
    if (novaFixa) {
        const fecharNova = () => novaFixa.classList.remove('open');
        const btnNova = document.getElementById('novaContaFixaBtn');
        if (btnNova) btnNova.addEventListener('click', () => novaFixa.classList.add('open'));
        novaFixa.addEventListener('click', (e) => { if (e.target === novaFixa) fecharNova(); });
        $$('[data-fixanova-close]', novaFixa).forEach((b) => b.addEventListener('click', fecharNova));
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') fecharNova(); });
    }

    // ---- Reabrir o modal após erro de validação do servidor ----
    if (modal.dataset.reopen === '1') abrir();
}
