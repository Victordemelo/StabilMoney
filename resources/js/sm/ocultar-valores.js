// O "olho" que esconde os valores (out/2026 — pedido do Victor): saldo, limite, faturas, tudo
// em R$ do app fica borrado com um toque, para abrir o app na frente dos outros. A escolha fica
// guardada neste aparelho (localStorage `sm-ocultar-valores`) e vale na próxima visita — o
// script do <head> do layout já liga o modo antes da primeira pintura.
//
// Como acha os valores: cada texto com "R$" seguido de número é marcado com `.sm-valor` (no
// elemento mais próximo que contém o valor inteiro — no card do saldo o "R$" e o número são
// spans irmãos, então quem leva a marca é o pai). Um MutationObserver marca o que chega depois
// (troca de tela pelo pjax, modais, o giro do saldo, a contagem dos números). O CSS borra os
// marcados quando `html[data-valores-ocultos]`. Campo de formulário nunca é tocado: o que a
// pessoa está digitando continua visível.

const CHAVE = 'sm-ocultar-valores';
const ATRIBUTO = 'data-valores-ocultos';
const DINHEIRO = /R\$\s*\d/;
const TETO_DE_TEXTO = 80; // acima disso é uma frase, não um valor: não borra o parágrafo inteiro
const PULAR = new Set(['SCRIPT', 'STYLE', 'TEXTAREA', 'INPUT', 'SELECT', 'OPTION', 'NOSCRIPT']);

export function estaOculto(doc = document) {
    return doc.documentElement.hasAttribute(ATRIBUTO);
}

/** Liga/desliga o modo e guarda a escolha. */
export function definirOculto(ligado, doc = document) {
    doc.documentElement.toggleAttribute(ATRIBUTO, ligado);
    try { localStorage.setItem(CHAVE, ligado ? '1' : '0'); } catch (e) { /* storage indisponível */ }
    doc.querySelectorAll('[data-ocultar-valores]').forEach((btn) => {
        btn.setAttribute('aria-pressed', ligado ? 'true' : 'false');
        btn.setAttribute('aria-label', ligado ? 'Mostrar os valores' : 'Esconder os valores');
        btn.title = ligado ? 'Mostrar os valores' : 'Esconder os valores';
    });
}

/** O elemento que leva a marca: sobe do texto até conter o valor inteiro ("R$" + número). */
function alvoDoTexto(no) {
    let el = no.parentElement;
    for (let i = 0; el && i < 3; i++) {
        if (PULAR.has(el.tagName) || el.closest('[data-sem-ocultar]')) return null;
        const texto = el.textContent;
        if (texto.length > TETO_DE_TEXTO) return null;
        if (DINHEIRO.test(texto)) return el;
        el = el.parentElement;
    }
    return null;
}

/** Marca com `.sm-valor` todo valor em R$ dentro de `raiz`. */
export function marcarValores(raiz = document.body) {
    if (!raiz) return;
    const doc = raiz.ownerDocument || raiz;
    const andador = doc.createTreeWalker(raiz, NodeFilter.SHOW_TEXT, {
        acceptNode: (no) => (no.nodeValue.includes('R$') ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_SKIP),
    });
    for (let no = andador.nextNode(); no; no = andador.nextNode()) {
        const alvo = alvoDoTexto(no);
        if (alvo) alvo.classList.add('sm-valor');
    }
}

export function initOcultarValores(doc = document) {
    const botoes = doc.querySelectorAll('[data-ocultar-valores]');
    if (!botoes.length || doc.documentElement.dataset.ocultarLigado) return;
    doc.documentElement.dataset.ocultarLigado = '1';

    definirOculto(estaOculto(doc), doc); // sincroniza os botões com o que o <head> decidiu
    marcarValores(doc.body);
    doc.documentElement.setAttribute('data-valores-prontos', '');

    doc.addEventListener('click', (e) => {
        const btn = e.target.closest ? e.target.closest('[data-ocultar-valores]') : null;
        if (!btn) return;
        definirOculto(!estaOculto(doc), doc);
    });

    // O que chega depois: o elemento novo (ou o que teve o texto trocado) é remarcado. Sobe
    // um nível no texto trocado para alcançar o pai do "R$" (ex.: a contagem do saldo).
    const pendentes = new Set();
    let agendado = false;
    const observador = new MutationObserver((mudancas) => {
        mudancas.forEach((m) => {
            const alvo = m.type === 'characterData' ? m.target.parentElement : m.target;
            if (alvo) pendentes.add(alvo.parentElement || alvo);
        });
        if (agendado) return;
        agendado = true;
        queueMicrotask(() => {
            agendado = false;
            pendentes.forEach((el) => { if (el.isConnected) marcarValores(el); });
            pendentes.clear();
        });
    });
    observador.observe(doc.body, { childList: true, subtree: true, characterData: true });
}
