/* ============ StabilMoney — Modal global "Lançar" (nova transação) ============ */
// Abre/fecha com a animação do .modal-scrim (open E close suaves). Type-toggle
// (receita/despesa) filtra as categorias; envia por AJAX (fetch → JSON) com
// spinner no "Salvar"; sucesso recarrega a página (via pjax, se houver) para
// refletir a transação; erro treme o modal e mostra a mensagem. Sem reload no erro.

import { pedirFonte } from './funding';

export function initLaunch() {
    const modal = document.getElementById('launchModal');
    if (!modal) return;

    const card = modal.querySelector('.modal');
    const form = modal.querySelector('[data-launch-form]');
    const errorBox = modal.querySelector('[data-lm-error]');
    const errorMsg = modal.querySelector('[data-lm-error-msg]');
    const saveBtn = modal.querySelector('[data-lm-save]');

    const hideError = () => { if (errorBox) errorBox.hidden = true; };
    const setSaving = (on) => {
        if (!saveBtn) return;
        saveBtn.classList.toggle('is-loading', on);
        saveBtn.disabled = on;
    };
    const shake = () => {
        if (!card) return;
        card.classList.remove('shake');
        void card.offsetWidth; // reflow p/ reiniciar
        card.classList.add('shake');
    };
    const showError = (msg) => {
        if (errorMsg) errorMsg.textContent = msg;
        if (errorBox) errorBox.hidden = false;
        shake();
    };

    const open = () => {
        hideError();
        setSaving(false);
        modal.classList.add('open');
        const amount = modal.querySelector('#lm-amount');
        if (amount) setTimeout(() => amount.focus(), 80);
    };
    const close = () => modal.classList.remove('open');

    // Abrir: botão "Lançar" (topbar) e FAB (bottom-nav) — o href fica de fallback.
    document.querySelectorAll('[data-launch-open]').forEach((a) => {
        a.addEventListener('click', (e) => { e.preventDefault(); open(); });
    });

    // Fechar: clique no fundo, botões de fechar, Esc — tudo com transição do scrim.
    modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
    modal.querySelectorAll('[data-close-btn]').forEach((b) => b.addEventListener('click', close));
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal.classList.contains('open')) close();
    });
    if (card) {
        card.addEventListener('animationend', (e) => {
            if (e.animationName === 'sm-shake') card.classList.remove('shake');
        });
    }

    if (!form) return; // estado "crie uma conta primeiro" não tem form

    // Type-toggle: cor da pílula/accent + filtro das categorias E das contas
    // pelo tipo escolhido (escopado ao modal).
    const radios = form.querySelectorAll('input[name="type"]');
    const select = form.querySelector('#lm-category');
    const contaSel = form.querySelector('#lm-account');
    const applyType = () => {
        const marcado = form.querySelector('input[name="type"]:checked');
        const tipo = marcado ? marcado.value : 'expense';
        form.dataset.type = tipo;
        if (card) card.dataset.type = tipo;

        if (select) {
            select.querySelectorAll('optgroup').forEach((g) => {
                const ativo = g.dataset.type === tipo;
                g.hidden = !ativo;
                g.querySelectorAll('option').forEach((opt) => {
                    opt.hidden = !ativo;
                    opt.disabled = !ativo;
                    if (!ativo && opt.selected) select.value = '';
                });
            });
        }

        // RECEITA não entra em cartão de crédito — some as opções de cartão e,
        // se uma delas estava escolhida, cai na primeira conta válida.
        if (contaSel) {
            let trocar = false;
            contaSel.querySelectorAll('option').forEach((opt) => {
                const soDespesa = opt.dataset.card === '1' && tipo === 'income';
                opt.hidden = soDespesa;
                opt.disabled = soDespesa;
                if (soDespesa && opt.selected) trocar = true;
            });
            if (trocar) {
                const valida = Array.from(contaSel.options).find((o) => !o.disabled);
                if (valida) contaSel.value = valida.value;
            }
        }
    };
    radios.forEach((r) => r.addEventListener('change', applyType));
    applyType();

    // Idempotência: o mesmo lançamento pode ser reenviado (duplo toque, retry de
    // rede, confirmação da escolha de fonte). O servidor deduplica por
    // client_uuid, então geramos um por ABERTURA do modal e só trocamos depois
    // de um envio bem-sucedido.
    let clientUuid = novoUuid();

    function novoUuid() {
        if (window.crypto?.randomUUID) return window.crypto.randomUUID();
        // Fallback p/ navegador sem randomUUID (contexto não-seguro).
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
            const r = (Math.random() * 16) | 0;
            return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
        });
    }

    // Envio por AJAX.
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        hideError();
        setSaving(true);

        const payload = new FormData(form);
        payload.set('client_uuid', clientUuid);

        const enviar = () => fetch(form.action, {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: payload,
        });

        let resp;
        try {
            resp = await enviar();
        } catch (_) {
            setSaving(false);
            showError('Sem conexão. Verifique sua internet e tente de novo.');
            return;
        }

        // 409 = o saldo não cobre, mas há fonte. Pergunta e reenvia a MESMA
        // requisição (mesmo client_uuid) com a escolha do usuário.
        if (resp.status === 409) {
            setSaving(false);
            const dados = await resp.json().catch(() => ({}));
            const escolha = await pedirFonte(dados.fonte);
            if (!escolha) return; // cancelou

            Object.entries(escolha).forEach(([k, v]) => payload.set(k, v));
            setSaving(true);
            try {
                resp = await enviar();
            } catch (_) {
                setSaving(false);
                showError('Sem conexão. Verifique sua internet e tente de novo.');
                return;
            }
        }

        if (resp.ok) {
            // Salvou: o próximo lançamento é outro, então renova a chave.
            clientUuid = novoUuid();
            // Transação criada — recarrega a página atual p/ refletir os novos dados.
            if (typeof window.smPjaxReload === 'function') window.smPjaxReload();
            else window.location.reload();
            return;
        }

        setSaving(false);
        let msg = 'Não foi possível salvar. Confira os campos e tente de novo.';
        if (resp.status === 422) {
            const data = await resp.json().catch(() => ({}));
            const errs = data?.errors || {};
            msg = Object.values(errs)[0]?.[0] || data?.message || msg;
        }
        showError(msg);
    });
}
