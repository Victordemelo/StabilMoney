// Página de Investimentos (design v2): carteira com donut de alocação,
// lista de ativos e projeção de rentabilidade.
//
// O app é server-routed — os formulários (criar/editar/excluir/aportar/resgatar)
// são <form> Laravel reais. Este módulo cuida só da camada de UI:
//   • abrir/fechar os modais (.modal-scrim → classe .open), com Esc e clique no véu;
//   • no "Aportar"/"Resgatar" (modais COMPARTILHADOS), setar o `action` do form e
//     preencher nome/posição a partir dos data-* do botão clicado;
//   • a PREVIEW de rentabilidade do "Novo investimento": recalcula bruto/líquido
//     e a projeção em 12 meses ao mudar indexador/taxa/valor (porte do investModal
//     do design v2 → finance.js; líquido ≈ bruto * 0.85);
//   • reabrir o modal certo quando a validação do servidor volta com erro.
//
// Só roda na tela de investimentos (guard pelo modal de criação da view).

const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));

// Converte "1.234,56" / "R$ 110" / "110%" em número (espelha parseMoney do protótipo).
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

// Rentabilidade bruta projetada por indexador (espelha grossRate/IDX_BASE do finance.js).
// idxBase = { CDI, Selic, 'IPCA+', Prefixado } vem do data-idx-base do modal.
function grossRate(indexador, taxa, idxBase) {
    if (indexador === 'CDI' || indexador === 'Selic') return (idxBase[indexador] || 0) * (taxa / 100);
    if (indexador === 'IPCA+') return (idxBase['IPCA+'] || 0) + taxa;
    if (indexador === 'Prefixado') return taxa;
    // Não indexado (RV/cripto/fundos): usa a própria taxa informada como rentab. observada.
    return taxa;
}

export function initInvestimentos() {
    // A view de investimentos tem sempre o modal de criação; sem ele, não é esta tela.
    const createModal = document.getElementById('invCreateModal');
    if (!createModal) return;

    const todosModais = $$('.modal-scrim[data-inv-modal]');

    const abrir = (modal) => {
        if (!modal) return;
        modal.classList.add('open');
        // Foca o primeiro campo editável (ignora os pickers de radio).
        const campo = modal.querySelector('input[type="text"], input[type="date"], select');
        if (campo) setTimeout(() => campo.focus(), 120);
    };

    const fecharTodos = () => todosModais.forEach((m) => m.classList.remove('open'));

    // Fechar: clique no véu, no X ou nos botões "Cancelar" ([data-inv-close]).
    todosModais.forEach((modal) => {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) modal.classList.remove('open');
        });
        $$('[data-inv-close]', modal).forEach((btn) =>
            btn.addEventListener('click', () => modal.classList.remove('open'))
        );
    });

    // Esc fecha qualquer modal aberto.
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') fecharTodos();
    });

    // ---- Abrir "Novo investimento" (botão do topo, estado vazio e "Novo aporte" inline) ----
    ['invNovoBtn', 'invNovoBtnVazio', 'invAddInline'].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('click', () => abrir(createModal));
    });

    // ---- Editar / Excluir (modais por ativo, abertos pelo id no data-id) ----
    $$('[data-inv-edit]').forEach((btn) => {
        btn.addEventListener('click', () => abrir(document.getElementById(`invEditModal-${btn.dataset.id}`)));
    });
    $$('[data-inv-del]').forEach((btn) => {
        btn.addEventListener('click', () => abrir(document.getElementById(`invDeleteModal-${btn.dataset.id}`)));
    });

    // ---- Aportar (modal compartilhado) ----
    const aporteModal = document.getElementById('invAporteModal');
    if (aporteModal) {
        const form = aporteModal.querySelector('[data-aporte-form]');
        const nomeEl = aporteModal.querySelector('[data-aporte-name]');
        const aplicEl = aporteModal.querySelector('[data-aporte-aplicado]');
        const actionField = aporteModal.querySelector('[data-action-field]');
        // base da URL: /investimentos/__ID__/aportes — trocamos o __ID__ pelo id clicado.
        $$('[data-inv-aporte]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const url = aporteModal.dataset.actionBase.replace('__ID__', btn.dataset.id);
                if (form) form.action = url;
                if (actionField) actionField.value = url; // p/ reabrir após erro
                if (nomeEl) nomeEl.textContent = btn.dataset.name || '';
                if (aplicEl) aplicEl.textContent = btn.dataset.aplicado || '—';
                const amount = aporteModal.querySelector('[name="amount"]');
                if (amount) amount.value = '';
                abrir(aporteModal);
            });
        });
    }

    // ---- Resgatar (modal compartilhado) ----
    const resgateModal = document.getElementById('invResgateModal');
    if (resgateModal) {
        const form = resgateModal.querySelector('[data-resgate-form]');
        const nomeEl = resgateModal.querySelector('[data-resgate-name]');
        const aplicEl = resgateModal.querySelector('[data-resgate-aplicado]');
        const actionField = resgateModal.querySelector('[data-action-field]');
        $$('[data-inv-resgatar]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const url = resgateModal.dataset.actionBase.replace('__ID__', btn.dataset.id);
                if (form) form.action = url;
                if (actionField) actionField.value = url;
                if (nomeEl) nomeEl.textContent = btn.dataset.name || '';
                if (aplicEl) aplicEl.textContent = btn.dataset.aplicado || '—';
                const amount = resgateModal.querySelector('[name="amount"]');
                if (amount) amount.value = '';
                abrir(resgateModal);
            });
        });
    }

    // ---- Preview de rentabilidade do "Novo investimento" ----
    // Recalcula bruto/líquido (≈ bruto * 0.85, ~15% IR/IOF) e a projeção em 12 meses
    // ao mudar indexador/taxa/valor. Porte do upd() do investModal (finance.js).
    {
        let idxBase = {};
        try { idxBase = JSON.parse(createModal.dataset.idxBase || '{}'); } catch (_) { idxBase = {}; }

        const idxSel = createModal.querySelector('[data-inv-idx]');
        const taxaInput = createModal.querySelector('[data-inv-taxa]');
        const taxaLabel = createModal.querySelector('[data-inv-taxa-label]');
        const valorInput = createModal.querySelector('[data-inv-valor]');
        const preview = createModal.querySelector('[data-inv-preview]');

        const TAXA_LBLS = {
            CDI: '% do CDI',
            Selic: '% da Selic',
            'IPCA+': 'IPCA + (% a.a.)',
            Prefixado: 'Taxa fixa (% a.a.)',
            '': 'Rentab. (% a.a.)',
        };

        const atualizar = () => {
            if (!preview) return;
            const indexador = idxSel ? idxSel.value : '';
            const taxa = parseMoney(taxaInput ? taxaInput.value : '');
            const valor = parseMoney(valorInput ? valorInput.value : '');

            if (taxaLabel) {
                const sufixo = (TAXA_LBLS[indexador] || '% a.a.');
                taxaLabel.innerHTML = `Taxa <span class="hint">(${sufixo})</span>`;
            }

            const bruto = grossRate(indexador, taxa, idxBase);
            const liquido = bruto * 0.85; // ~15% IR/IOF estimado

            if (valor > 0) {
                preview.innerHTML =
                    `<div class="ivp-row"><span>Rentabilidade bruta estimada</span><b>${bruto.toFixed(2).replace('.', ',')}% a.a.</b></div>` +
                    `<div class="ivp-row"><span>Líquido (após IR/IOF ~15%)</span><b class="pos">${liquido.toFixed(2).replace('.', ',')}% a.a.</b></div>` +
                    `<div class="ivp-row"><span>Projeção em 12 meses</span><b>R$ ${brl(valor * (1 + liquido / 100))}</b></div>`;
            } else {
                preview.innerHTML = `<div class="ivp-hint">Preencha o valor para ver a projeção de rendimento.</div>`;
            }
        };

        if (idxSel) idxSel.addEventListener('change', atualizar);
        if (taxaInput) taxaInput.addEventListener('input', atualizar);
        if (valorInput) valorInput.addEventListener('input', atualizar);
        atualizar();
    }

    // ---- Reabrir o modal correto após erro de validação do servidor ----
    // Editar/criar usam data-reopen="1" diretamente. Para aportar/resgatar,
    // o backend não sabe o ativo — então reabrimos o modal compartilhado com
    // o action que veio do hidden _action (data-reopen-action), se houver.
    todosModais.forEach((modal) => {
        if (modal.dataset.reopen !== '1') return;
        const acao = modal.dataset.reopenAction; // URL exata vinda do hidden _action
        if (acao) {
            const form = modal.querySelector('[data-aporte-form], [data-resgate-form]');
            if (form) {
                form.action = acao;
                const actionField = form.querySelector('[data-action-field]');
                if (actionField) actionField.value = acao;
            }
        }
        abrir(modal);
    });
}
