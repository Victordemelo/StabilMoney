/* ============ StabilMoney — Modal global "Lançar" (nova transação) ============ */
// Abre/fecha com a animação do .modal-scrim (open E close suaves). Type-toggle
// (receita/despesa) filtra as categorias; envia por AJAX (fetch → JSON) com
// spinner no "Salvar"; sucesso recarrega a página (via pjax, se houver) para
// refletir a transação; erro treme o modal e mostra a mensagem. Sem reload no erro.

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

    // Type-toggle: cor da pílula/accent + filtro das categorias pelo tipo (escopado ao modal).
    const radios = form.querySelectorAll('input[name="type"]');
    const select = form.querySelector('#lm-category');
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
    };
    radios.forEach((r) => r.addEventListener('change', applyType));
    applyType();

    // Envio por AJAX.
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        hideError();
        setSaving(true);

        let resp;
        try {
            resp = await fetch(form.action, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: new FormData(form),
            });
        } catch (_) {
            setSaving(false);
            showError('Sem conexão. Verifique sua internet e tente de novo.');
            return;
        }

        if (resp.ok) {
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
