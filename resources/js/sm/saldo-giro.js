// O saldo que GIRA de banco em banco no painel (out/2026 — pedido do Victor).
//
// Com mais de uma conta de banco, o card de destaque mostra o total e, a cada 4 segundos,
// passa para o saldo de uma conta (Banco Santander, depois Banco Itaú…), com o selo do banco
// trocando junto, e volta ao total. O servidor desenha tudo (o total visível, as contas
// escondidas); aqui só se troca qual slide está `.ativo`. Para com a aba escondida e com o
// mouse ou o foco no card; tocar no selo passa para o próximo. Com "reduzir movimento" não
// gira sozinho — só no toque. Sai sozinho quando o pjax troca a tela.

const INTERVALO = 4000;

export function initSaldoGiro(raiz = document, opcoes = {}) {
    const ligados = [];
    raiz.querySelectorAll('[data-saldo-giro]').forEach((giro) => {
        if (giro.dataset.ligado) return;
        giro.dataset.ligado = '1';
        const g = ligar(giro, opcoes);
        if (g) ligados.push(g);
    });
    return ligados;
}

function ligar(giro, opcoes) {
    const card = giro.closest('.dashboard-balance-hero') || giro.parentElement;
    const selos = card.querySelector('[data-giro-selos]');
    const total = giro.querySelectorAll('[data-giro-item]').length;
    if (total < 2) return null;

    const agendar = opcoes.agendar || ((fn, ms) => setTimeout(fn, ms));
    const cancelar = opcoes.cancelar || ((id) => clearTimeout(id));
    const reduzir = opcoes.reduzir ?? window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
    let atual = 0;
    let timer = null;
    let pausado = false;

    const mostrar = (i) => {
        atual = ((i % total) + total) % total;
        card.querySelectorAll('[data-giro-item]').forEach((el) => {
            const ativo = Number(el.dataset.giroItem) === atual;
            el.classList.toggle('ativo', ativo);
            if (ativo) el.removeAttribute('aria-hidden');
            else el.setAttribute('aria-hidden', 'true');
        });
    };

    const agendarProximo = () => {
        if (timer !== null) cancelar(timer);
        timer = null;
        if (reduzir) return;
        timer = agendar(() => {
            timer = null;
            if (!giro.isConnected) return; // a tela saiu (pjax): para de vez
            if (!pausado && !document.hidden) mostrar(atual + 1);
            agendarProximo();
        }, INTERVALO);
    };

    const proximo = () => { mostrar(atual + 1); agendarProximo(); };
    selos?.addEventListener('click', proximo);
    selos?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); proximo(); }
    });
    // Só MOUSE pausa: no celular o toque dispara "entrar" e nunca "sair", e o giro parava de vez.
    card.addEventListener('pointerenter', (e) => { if (e.pointerType === 'mouse') pausado = true; });
    card.addEventListener('pointerleave', (e) => { if (e.pointerType === 'mouse') pausado = false; });
    card.addEventListener('focusin', () => { pausado = true; });
    card.addEventListener('focusout', () => { pausado = false; });

    mostrar(0);
    agendarProximo();
    return { mostrar, proximo };
}
