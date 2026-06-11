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
