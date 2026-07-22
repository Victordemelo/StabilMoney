/* ============ StabilMoney — Telas de auth (login/cadastro) ============ */
// Vídeo de fundo, toggle de mostrar/ocultar senha e medidor de força.
// Só roda nas páginas que têm o shell .auth (login/cadastro); cada bloco
// ainda checa os próprios elementos antes de agir.

// Rótulos do medidor de força (mesma régua do protótipo)
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

/**
 * Login por AJAX (tela v2): sem reload. Ao enviar, o botão entra em estado de
 * carregamento; se as credenciais forem aceitas, redireciona para o destino que
 * o servidor devolve; se forem negadas (ou throttle), o card "treme" e o erro
 * aparece num banner. O servidor devolve JSON: 200 {redirect} no sucesso, 422
 * {errors} na falha (ValidationException do LoginRequest).
 */
function initAjaxLogin(root) {
    const form = root.querySelector('form[data-ajax-login]');
    if (!form) return;

    const btn = form.querySelector('[data-login-btn]');
    const card = form.closest('.auth-card');
    const errorBox = root.querySelector('[data-login-error]');
    const errorMsg = root.querySelector('[data-login-error-msg]');

    const setLoading = (on) => {
        if (!btn) return;
        btn.classList.toggle('is-loading', on);
        btn.disabled = on;
    };

    const hideError = () => { if (errorBox) errorBox.hidden = true; };

    const showError = (msg) => {
        if (errorMsg) errorMsg.textContent = msg;
        if (errorBox) errorBox.hidden = false;
        if (card) {
            card.classList.remove('shake');
            void card.offsetWidth; // força reflow p/ reiniciar a animação
            card.classList.add('shake');
        }
    };

    if (card) {
        card.addEventListener('animationend', (e) => {
            if (e.animationName === 'sm-shake') card.classList.remove('shake');
        });
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        hideError();
        setLoading(true);

        let resp;
        try {
            resp = await fetch(form.action, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: new FormData(form),
            });
        } catch (_) {
            setLoading(false);
            showError('Sem conexão. Verifique sua internet e tente de novo.');
            return;
        }

        if (resp.ok) {
            // Credenciais aceitas: segue com o botão carregando até a navegação.
            const data = await resp.json().catch(() => ({}));
            window.location.assign(data.redirect || '/');
            return;
        }

        setLoading(false);
        let msg = 'Não foi possível entrar. Tente novamente.';
        if (resp.status === 422) {
            const data = await resp.json().catch(() => ({}));
            msg = data?.errors?.email?.[0] || data?.errors?.password?.[0] || data?.message || msg;
        }
        showError(msg);
    });
}

export function initAuth() {
    const root = document.querySelector('main.auth');
    if (!root) return;

    // Vídeo de fundo: aparece em fade quando o primeiro frame carrega
    const video = document.getElementById('authVideo');
    if (video) {
        const show = () => video.classList.add('ready');
        if (video.readyState >= 2) show();
        video.addEventListener('loadeddata', show);
        if (video.play) video.play().catch(() => { /* autoplay bloqueado: o veil segura o visual */ });
    }

    // Mostrar/ocultar senha
    root.querySelectorAll('.toggle[data-toggle]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const input = document.getElementById(btn.dataset.toggle);
            if (!input) return;
            const visivel = input.type === 'password';
            input.type = visivel ? 'text' : 'password';
            btn.style.color = visivel ? 'var(--brand-600)' : '';
            btn.setAttribute('aria-label', visivel ? 'Ocultar senha' : 'Mostrar senha');
        });
    });

    // Login por AJAX: spinner no botão; sucesso redireciona; erro treme + mostra.
    initAjaxLogin(root);

    // Medidor de força (só existe na tela de cadastro)
    const bar = document.getElementById('strength');
    const txt = document.getElementById('strengthTxt');
    const senha = document.getElementById('password');
    if (bar && txt && senha) {
        senha.addEventListener('input', () => {
            const s = strengthScore(senha.value);
            bar.className = 'strength' + (s ? ' s' + s : '');
            txt.textContent = STRENGTH_LABELS[s] || '';
        });
    }
}
