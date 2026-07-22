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

const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));

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

        $$('[data-pay-open]').forEach((btn) => btn.addEventListener('click', () => {
            if (payForm) payForm.setAttribute('action', btn.dataset.action || '');
            if (payName) payName.textContent = btn.dataset.name || '';
            if (payAmount) payAmount.textContent = btn.dataset.amount || '';
            abrirPay();
        }));
        payModal.addEventListener('click', (e) => { if (e.target === payModal) fecharPay(); });
        $$('[data-pay-close]', payModal).forEach((b) => b.addEventListener('click', fecharPay));
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') fecharPay(); });
    }

    // ---- Reabrir o modal após erro de validação do servidor ----
    if (modal.dataset.reopen === '1') abrir();
}
