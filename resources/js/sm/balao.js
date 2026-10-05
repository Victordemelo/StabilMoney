// ============================================================
// Balões de aviso (out/2026 — pedido do Victor): toda confirmação de "salvo/alterado"
// aparece num balão flutuante no canto SUPERIOR DIREITO, por cima da tela, e some
// sozinho. Antes era uma faixa verde no topo do conteúdo, que empurrava a página.
//
// - `mostrarBalao(msg, { tipo })` cria um balão (tipo `ok` | `info` | `erro`);
// - `promoverFlashes(raiz)` leva os `[data-flash]` que o servidor renderizou (partials/flash)
//   para a pilha de balões. Sem JS, o próprio `.flash` já é fixo no canto (CSS).
//
// Texto sempre por `textContent` — nunca `innerHTML` (regra do projeto: a mensagem pode
// citar nome de conta, categoria ou meta).
// ============================================================

const DURACAO = { ok: 4500, info: 4500, erro: 8000 };

function pilha() {
    let p = document.getElementById('sm-baloes');
    if (!p) {
        p = document.createElement('div');
        p.id = 'sm-baloes';
        p.className = 'sm-baloes';
        // `status` (polite): o leitor de tela anuncia sem interromper.
        p.setAttribute('role', 'status');
        p.setAttribute('aria-live', 'polite');
        document.body.appendChild(p);
    }
    return p;
}

function fechar(balao) {
    if (!balao.isConnected || balao.classList.contains('saindo')) return;
    balao.classList.add('saindo');
    setTimeout(() => balao.remove(), 300);
}

function armar(balao, tipo) {
    const botao = document.createElement('button');
    botao.type = 'button';
    botao.className = 'sm-balao-x';
    botao.setAttribute('aria-label', 'Fechar aviso');
    botao.textContent = '×';
    botao.addEventListener('click', () => fechar(balao));
    balao.appendChild(botao);

    let timer = setTimeout(() => fechar(balao), DURACAO[tipo] ?? DURACAO.ok);
    // Passou o mouse (ou o foco) por cima: espera a pessoa terminar de ler.
    const pausar = () => clearTimeout(timer);
    const retomar = () => { timer = setTimeout(() => fechar(balao), 2500); };
    balao.addEventListener('mouseenter', pausar);
    balao.addEventListener('mouseleave', retomar);
    balao.addEventListener('focusin', pausar);
    balao.addEventListener('focusout', retomar);
}

export function mostrarBalao(mensagem, { tipo = 'ok' } = {}) {
    if (!mensagem) return null;
    const balao = document.createElement('div');
    balao.className = `sm-balao ${tipo}`;
    const texto = document.createElement('span');
    texto.className = 'sm-balao-txt';
    texto.textContent = mensagem;
    balao.appendChild(texto);
    pilha().appendChild(balao);
    armar(balao, tipo);
    return balao;
}

/** Leva os avisos de sucesso do servidor (`[data-flash]`) para a pilha de balões. */
export function promoverFlashes(raiz = document) {
    raiz.querySelectorAll('[data-flash]').forEach((flash) => {
        flash.removeAttribute('data-flash');
        flash.classList.add('sm-balao', 'ok');
        flash.removeAttribute('role'); // a pilha já é a região `status`
        pilha().appendChild(flash);
        armar(flash, 'ok');
    });
}

export function initBaloes() {
    promoverFlashes();
    // Ponte para os scripts inline das views (que não importam módulos).
    window.smBalao = mostrarBalao;
}
