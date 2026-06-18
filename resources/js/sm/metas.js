// Página de Metas (design v2): objetivos de poupança com anel de progresso.
//
// O app é server-routed — os formulários (criar/editar/excluir/aportar/resgatar)
// são <form> Laravel reais. Este módulo cuida só da camada de UI:
//   • abrir/fechar os modais (.modal-scrim → classe .open), com Esc e clique no véu;
//   • no "Aportar"/"Resgatar" (modais COMPARTILHADOS), setar o `action` do form e
//     preencher nome/guardado/faltam a partir dos data-* do botão clicado;
//   • reabrir o modal certo quando a validação do servidor volta com erro
//     (cada .modal-scrim com data-reopen="1" reabre sozinho).
//
// Só roda na tela de metas (guard pelo container da view + presença de modais).

const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));

export function initMetas() {
    // A view de metas tem sempre o modal de criação; sem ele, não é esta tela.
    const createModal = document.getElementById('metaCreateModal');
    if (!createModal) return;

    const todosModais = $$('.modal-scrim[data-meta-modal]');

    const abrir = (modal) => {
        if (!modal) return;
        modal.classList.add('open');
        // Foca o primeiro campo editável (ignora os pickers de radio).
        const campo = modal.querySelector('input[type="text"], input[type="month"], input[type="date"], select');
        if (campo) setTimeout(() => campo.focus(), 120);
    };

    const fecharTodos = () => todosModais.forEach((m) => m.classList.remove('open'));

    // Fechar: clique no véu, no X ou nos botões "Cancelar" ([data-meta-close]).
    todosModais.forEach((modal) => {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) modal.classList.remove('open');
        });
        $$('[data-meta-close]', modal).forEach((btn) =>
            btn.addEventListener('click', () => modal.classList.remove('open'))
        );
    });

    // Esc fecha qualquer modal aberto.
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') fecharTodos();
    });

    // ---- Abrir "Nova meta" (botão do topo, botão do estado vazio e card tracejado) ----
    ['metaNovaBtn', 'metaNovaBtnVazio', 'metaAddCard'].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('click', () => abrir(createModal));
    });

    // ---- Editar / Excluir (modais por meta, abertos pelo id no data-id) ----
    $$('[data-meta-edit]').forEach((btn) => {
        btn.addEventListener('click', () => abrir(document.getElementById(`metaEditModal-${btn.dataset.id}`)));
    });
    $$('[data-meta-del]').forEach((btn) => {
        btn.addEventListener('click', () => abrir(document.getElementById(`metaDeleteModal-${btn.dataset.id}`)));
    });

    // ---- Aportar (modal compartilhado) ----
    const aporteModal = document.getElementById('metaAporteModal');
    if (aporteModal) {
        const form = aporteModal.querySelector('[data-aporte-form]');
        const nomeEl = aporteModal.querySelector('[data-aporte-name]');
        const savedEl = aporteModal.querySelector('[data-aporte-saved]');
        const remainEl = aporteModal.querySelector('[data-aporte-remaining]');

        const actionField = aporteModal.querySelector('[data-action-field]');
        // base da URL: /metas/__ID__/aportes — trocamos o __ID__ pelo da meta clicada.
        $$('[data-meta-aporte]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const url = aporteModal.dataset.actionBase.replace('__ID__', btn.dataset.id);
                if (form) form.action = url;
                if (actionField) actionField.value = url; // p/ reabrir após erro
                if (nomeEl) nomeEl.textContent = btn.dataset.name || '';
                if (savedEl) savedEl.textContent = btn.dataset.saved || '—';
                if (remainEl) remainEl.textContent = btn.dataset.remaining || '—';
                const amount = aporteModal.querySelector('[name="amount"]');
                if (amount) amount.value = '';
                abrir(aporteModal);
            });
        });
    }

    // ---- Resgatar (modal compartilhado) ----
    const resgateModal = document.getElementById('metaResgateModal');
    if (resgateModal) {
        const form = resgateModal.querySelector('[data-resgate-form]');
        const nomeEl = resgateModal.querySelector('[data-resgate-name]');
        const savedEl = resgateModal.querySelector('[data-resgate-saved]');

        const actionField = resgateModal.querySelector('[data-action-field]');
        $$('[data-meta-resgatar]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const url = resgateModal.dataset.actionBase.replace('__ID__', btn.dataset.id);
                if (form) form.action = url;
                if (actionField) actionField.value = url;
                if (nomeEl) nomeEl.textContent = btn.dataset.name || '';
                if (savedEl) savedEl.textContent = btn.dataset.saved || '—';
                const amount = resgateModal.querySelector('[name="amount"]');
                if (amount) amount.value = '';
                abrir(resgateModal);
            });
        });
    }

    // ---- Reabrir o modal correto após erro de validação do servidor ----
    // Editar/criar usam data-reopen="1" diretamente. Para aportar/resgatar,
    // o backend não sabe a meta — então só reabrimos o modal compartilhado com
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
