// Página de Metas (design v2): objetivos de poupança com anel de progresso.
//
// O app é server-routed — os formulários (criar/editar/excluir/aportar/resgatar)
// são <form> Laravel reais. Este módulo cuida só da camada de UI:
//   • abrir/fechar os modais como DIÁLOGOS (sm/dialogo.js: foco dentro, Tab preso,
//     Esc, resto da página inerte e o foco de volta a quem abriu), com clique no véu;
//   • no "Aportar"/"Resgatar" (modais COMPARTILHADOS), setar o `action` do form e
//     preencher nome/guardado/faltam a partir dos data-* do botão clicado;
//   • reabrir o modal certo quando a validação do servidor volta com erro
//     (cada .modal-scrim com data-reopen="1" reabre sozinho).
//
// Só roda na tela de metas (guard pelo container da view + presença de modais).

import { abrirDialogo, fecharDialogo } from './dialogo';

const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));

// "1.234,56" / "R$ 80" → 1234.56 (mesma régua do resto do app)
function parseMoney(str) {
    if (str == null) return 0;
    const limpo = String(str).replace(/[^0-9,.-]/g, '').replace(/\.(?=\d{3}(\D|$))/g, '').replace(',', '.');
    const n = parseFloat(limpo);
    return Number.isFinite(n) ? Math.abs(n) : 0;
}

function brl(v) {
    return (Number(v) || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// Uuid novo para o `client_uuid` do formulário (idempotência no servidor): um
// duplo clique ou o reenvio de um POST que já chegou não grava de novo.
function novoUuid() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
    // Fallback (contexto sem `randomUUID`, ex.: http em rede local): v4 pela API antiga.
    const b = new Uint8Array(16);
    window.crypto.getRandomValues(b);
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    const h = Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');
    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

// Troca o uuid do form a cada ABERTURA do modal: cada abertura é uma intenção
// nova; dentro da mesma abertura, reenviar é duplicata.
function renovarUuid(form) {
    const campo = form ? form.querySelector('[data-client-uuid]') : null;
    if (campo) campo.value = novoUuid();
}

// Desabilita o botão de enviar enquanto o POST está em voo: é a primeira
// barreira contra o duplo clique (o uuid é a segunda, no servidor). Volta a
// habilitar se a página for restaurada do bfcache (botão "voltar").
function travarAoEnviar(form) {
    if (!form || form.dataset.travaEnvio) return;
    form.dataset.travaEnvio = '1';
    form.addEventListener('submit', () => {
        $$('button[type="submit"]', form).forEach((btn) => {
            btn.disabled = true;
            btn.setAttribute('aria-busy', 'true');
        });
    });
    window.addEventListener('pageshow', () => {
        $$('button[type="submit"]', form).forEach((btn) => {
            btn.disabled = false;
            btn.removeAttribute('aria-busy');
        });
    });
}

/**
 * Quantos meses até o prazo (input type="month", "AAAA-MM"), contando o mês
 * atual como um aporte possível — quem tem prazo em dezembro e está em julho
 * consegue guardar em jul, ago, set, out, nov e dez = 6 vezes.
 * Devolve 0 quando não há prazo, e null se o prazo já passou.
 */
function mesesAte(valorMonth) {
    if (!valorMonth) return 0;
    const [ano, mes] = valorMonth.split('-').map(Number);
    if (!ano || !mes) return 0;
    const hoje = new Date();
    const diff = (ano - hoje.getFullYear()) * 12 + (mes - (hoje.getMonth() + 1)) + 1;
    return diff > 0 ? diff : null;
}

/**
 * Mostra, ao vivo, quanto guardar por mês para bater a meta no prazo:
 * (valor alvo − o que já está guardado) ÷ meses restantes.
 * Sem prazo ou sem valor, o texto some.
 */
function planoDeAporte(scope) {
    const valor = scope.querySelector('[name="target_amount"]');
    const prazo = scope.querySelector('[name="target_date"]');
    const saida = scope.querySelector('[data-meta-plan]');
    if (!valor || !prazo || !saida) return;

    const jaGuardado = parseFloat(saida.dataset.saved || '0') || 0;

    const atualizar = () => {
        const alvo = parseMoney(valor.value);
        const meses = mesesAte(prazo.value);

        if (!alvo) { saida.hidden = true; return; }

        if (meses === null) {
            saida.hidden = false;
            saida.className = 'meta-plan is-late';
            saida.textContent = 'O prazo escolhido já passou — escolha um mês futuro.';
            return;
        }
        if (!meses) { saida.hidden = true; return; } // sem prazo: nada a calcular

        const falta = Math.max(0, alvo - jaGuardado);
        saida.hidden = false;
        saida.className = 'meta-plan';

        if (falta <= 0) {
            saida.innerHTML = '🎉 Você já guardou o valor todo desta meta.';
            return;
        }

        saida.innerHTML =
            `Guardando <b>R$ ${brl(falta / meses)}</b> por mês você chega lá em ` +
            `<b>${meses} ${meses === 1 ? 'mês' : 'meses'}</b>` +
            (jaGuardado > 0 ? ` (faltam R$ ${brl(falta)}).` : '.');
    };

    valor.addEventListener('input', atualizar);
    prazo.addEventListener('change', atualizar);
    prazo.addEventListener('input', atualizar);
    atualizar();
}

export function initMetas() {
    // A view de metas tem sempre o modal de criação; sem ele, não é esta tela.
    const createModal = document.getElementById('metaCreateModal');
    if (!createModal) return;

    const todosModais = $$('.modal-scrim[data-meta-modal]');

    /**
     * Abre como diálogo, com o foco no primeiro campo editável (os pickers de radio
     * ficam de fora; num "Excluir?" sem campo, o utilitário cai no Cancelar).
     * `gatilho` recebe o foco de volta ao fechar.
     */
    const abrir = (modal, gatilho = null) => {
        if (!modal) return;
        const campo = modal.querySelector('input[type="text"], input[type="month"], input[type="date"], select');
        abrirDialogo(modal, { foco: campo, retorno: gatilho });
    };

    const fechar = (modal) => fecharDialogo(modal);

    // Fechar: clique no véu, no X ou nos botões "Cancelar" ([data-meta-close]). O Esc
    // é do utilitário de diálogo, que fecha só o de cima — o ouvinte antigo, preso ao
    // documento, somava um a cada visita pelo pjax e fechava os modais por fora dele.
    todosModais.forEach((modal) => {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) fechar(modal);
        });
        $$('[data-meta-close]', modal).forEach((btn) =>
            btn.addEventListener('click', () => fechar(modal))
        );
    });

    // ---- "Quanto guardar por mês" nos formulários de criar/editar ----
    $$('[data-meta-plan]').forEach((el) => {
        const form = el.closest('form');
        if (form) planoDeAporte(form);
    });

    // ---- Abrir "Nova meta" (botão do topo, botão do estado vazio e card tracejado) ----
    ['metaNovaBtn', 'metaNovaBtnVazio', 'metaAddCard'].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('click', () => abrir(createModal, el));
    });

    // ---- Editar / Excluir (modais por meta, abertos pelo id no data-id) ----
    $$('[data-meta-edit]').forEach((btn) => {
        btn.addEventListener('click', () => abrir(document.getElementById(`metaEditModal-${btn.dataset.id}`), btn));
    });
    $$('[data-meta-del]').forEach((btn) => {
        btn.addEventListener('click', () => abrir(document.getElementById(`metaDeleteModal-${btn.dataset.id}`), btn));
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
                renovarUuid(form);
                abrir(aporteModal, btn);
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
                renovarUuid(form);
                abrir(resgateModal, btn);
            });
        });
    }

    // ---- Um envio por vez em todo formulário de modal (aportar/resgatar/criar/editar) ----
    todosModais.forEach((modal) => $$('form', modal).forEach(travarAoEnviar));

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
        abrir(modal, gatilhoDaReabertura(modal, acao));
    });
}

/**
 * Quem "abriu" um modal que a tela reabriu sozinha (erro de validação): o botão que o
 * teria aberto. Sem ele, fechar o modal jogaria o foco no <body>, e quem usa teclado
 * recomeçaria da primeira linha da página.
 */
function gatilhoDaReabertura(modal, acao) {
    const id = modal.id;
    if (id === 'metaCreateModal') {
        return ['metaNovaBtn', 'metaNovaBtnVazio', 'metaAddCard']
            .map((x) => document.getElementById(x))
            .find(Boolean) || null;
    }

    const porMeta = /^metaEditModal-(\d+)$/.exec(id);
    if (porMeta) return document.querySelector(`[data-meta-edit][data-id="${porMeta[1]}"]`);

    // Aportar/resgatar: o botão cuja URL montada é a que o servidor devolveu.
    const seletor = { metaAporteModal: '[data-meta-aporte]', metaResgateModal: '[data-meta-resgatar]' }[id];
    if (seletor && acao && modal.dataset.actionBase) {
        return $$(seletor).find((btn) => modal.dataset.actionBase.replace('__ID__', btn.dataset.id) === acao) || null;
    }
    return null;
}
