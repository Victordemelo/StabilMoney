/* ============ StabilMoney — Configurações › Segurança ============ */
// Mostrar/ocultar senha, medidor de força e checagem ao vivo dos requisitos
// no card de troca de senha. Só roda quando #passwordForm existe na página.
// O medidor usa a MESMA régua do cadastro (sm/auth.js) para ser consistente.

const STRENGTH_LABELS = ['', 'Senha fraca', 'Senha razoável', 'Senha boa', 'Senha forte'];

// 0–4: comprimento, maiúscula+minúscula, dígito, símbolo
function strengthScore(p) {
    if (p.length === 0) return 0;
    let s = 0;
    if (p.length >= 8) s++;
    if (/[a-z]/.test(p) && /[A-Z]/.test(p)) s++;
    if (/\d/.test(p)) s++;
    if (/[^\w\s]/.test(p)) s++;
    return s;
}

const RULES = {
    len: (p) => p.length >= 8,
    case: (p) => /[a-z]/.test(p) && /[A-Z]/.test(p),
    num: (p) => /\d/.test(p),
    sym: (p) => /[^\w\s]/.test(p),
};

export function initSecurity() {
    const form = document.getElementById('passwordForm');
    if (!form) return;

    // Mostrar/ocultar senha (cada botão aponta para o id do seu input)
    form.querySelectorAll('.pw-toggle[data-toggle]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const input = document.getElementById(btn.dataset.toggle);
            if (!input) return;
            const oculta = input.type === 'password';
            input.type = oculta ? 'text' : 'password';
            btn.classList.toggle('on', oculta);
            btn.setAttribute('aria-label', oculta ? 'Ocultar senha' : 'Mostrar senha');
        });
    });

    const nova = document.getElementById('new_password');
    const confirma = document.getElementById('new_password_confirmation');
    const meter = form.querySelector('[data-strength]');
    const label = form.querySelector('.pw-strength-label');
    const hints = form.querySelector('[data-hints]');
    const match = form.querySelector('[data-match]');

    if (nova && meter && label && hints) {
        nova.addEventListener('input', () => {
            const valor = nova.value;
            const vazio = valor.length === 0;

            meter.hidden = vazio;
            hints.hidden = vazio;

            const s = strengthScore(valor);
            meter.dataset.score = String(s);
            label.textContent = STRENGTH_LABELS[s] || '';

            hints.querySelectorAll('[data-rule]').forEach((li) => {
                li.classList.toggle('ok', RULES[li.dataset.rule]?.(valor) ?? false);
            });

            atualizaMatch();
        });
    }

    function atualizaMatch() {
        if (!confirma || !match) return;
        if (confirma.value.length === 0) {
            match.hidden = true;
            return;
        }
        const igual = confirma.value === (nova ? nova.value : '');
        match.hidden = false;
        match.textContent = igual ? 'As senhas conferem.' : 'As senhas não conferem.';
        match.classList.toggle('ok', igual);
    }

    if (confirma) confirma.addEventListener('input', atualizaMatch);
}
