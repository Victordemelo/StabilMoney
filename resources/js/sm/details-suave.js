// Abrir e fechar SUAVE dos blocos recolhíveis de Contas a pagar (out/2026 — pedido do Victor: "o
// abrir e fechar está muito seco"). O `<details>` nativo abre de uma vez; aqui a altura desliza
// entre o fechado e o aberto (Web Animations, sem mexer no CSS do bloco) e o conteúdo surge junto.
// Sem a API de animação ou com "reduzir movimento", fica o comportamento nativo. Vale para todo
// `details[data-suave]`, inclusive o que chega pelo pjax (o ouvinte é um só, no documento).
//
// Em duas colunas (`.faturas-colunas`, a partir de 1280px) os cards da MESMA LINHA têm a mesma
// altura e abrem e fecham JUNTOS (out/2026 — "tem que ter o card do mesmo tamanho, senão fica
// desalinhado"): um aberto ao lado de um fechado deixava a linha torta.

import { limitarListasEm } from './lista-rolavel';

const DURACAO = 300;
const CURVA = 'cubic-bezier(.22, .8, .24, 1)';
const DUAS_COLUNAS = '(min-width: 1280px)';

function semMovimento(janela) {
    return janela.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
}

/** O card ao lado na mesma linha (só com duas colunas na tela), ou null. */
export function parDe(detalhes) {
    const grade = detalhes.parentElement;
    const janela = detalhes.ownerDocument.defaultView;
    if (!grade?.classList.contains('faturas-colunas') || !janela.matchMedia?.(DUAS_COLUNAS).matches) return null;
    const cards = Array.from(grade.children);
    const par = cards[cards.indexOf(detalhes) ^ 1];
    return par && par.matches('details[data-suave]') ? par : null;
}

/** Abre ou fecha juntos os `details` da lista. Devolve as animações (vazio, sem animar). */
function alternarJuntos(lista) {
    const janela = lista[0].ownerDocument.defaultView;
    if (lista.some((d) => d.dataset.animando)) return [];
    const abrindo = !lista[0].open;
    // Abrindo, as listas longas de dentro ganham a altura de rolagem ANTES da medida — senão a
    // animação terminaria na altura da lista inteira e daria um pulo.
    const abrir = (d) => {
        d.open = abrindo;
        if (abrindo) limitarListasEm(d);
    };
    if (typeof lista[0].animate !== 'function' || semMovimento(janela)) {
        lista.forEach(abrir);
        return [];
    }

    // Mede o outro estado de TODOS antes de animar (no mesmo quadro, sem pintar): com a altura
    // da linha igualada, medir um só daria a altura do vizinho.
    const antes = lista.map((d) => d.offsetHeight);
    lista.forEach(abrir);
    const depois = lista.map((d) => d.offsetHeight);
    if (!abrindo) lista.forEach((d) => { d.open = true; }); // fecha no fim, o conteúdo some deslizando

    return lista.map((detalhes, i) => {
        detalhes.dataset.animando = '1';
        detalhes.style.overflow = 'hidden';
        const animacao = detalhes.animate(
            [{ height: `${antes[i]}px` }, { height: `${depois[i]}px` }],
            { duration: DURACAO, easing: CURVA },
        );
        const corpo = detalhes.querySelector(':scope > :not(summary)');
        corpo?.animate?.(
            abrindo ? [{ opacity: 0, transform: 'translateY(-6px)' }, { opacity: 1, transform: 'none' }]
                : [{ opacity: 1 }, { opacity: 0 }],
            { duration: DURACAO, easing: CURVA },
        );
        const terminar = () => {
            if (!abrindo) detalhes.open = false;
            detalhes.style.overflow = '';
            delete detalhes.dataset.animando;
        };
        animacao.onfinish = terminar;
        animacao.oncancel = terminar;
        return animacao;
    });
}

/** Abre ou fecha `detalhes` (e o par dele na linha). Devolve a animação dele, ou null. */
export function alternar(detalhes) {
    const par = parDe(detalhes);
    if (par && par.open !== detalhes.open) par.open = detalhes.open; // entram no mesmo estado
    return alternarJuntos(par ? [detalhes, par] : [detalhes])[0] ?? null;
}

/** Com duas colunas, a linha começa no mesmo estado: se um dos dois nasce aberto, abrem os dois. */
export function sincronizarPares(doc = document) {
    doc.querySelectorAll('.faturas-colunas > details[data-suave]').forEach((d) => {
        const par = parDe(d);
        if (par && (d.open || par.open)) {
            d.open = true;
            par.open = true;
        }
    });
}

export function initDetailsSuave(doc = document) {
    if (doc.documentElement.dataset.detailsSuave) return;
    doc.documentElement.dataset.detailsSuave = '1';
    doc.addEventListener('click', (e) => {
        const resumo = e.target.closest ? e.target.closest('details[data-suave] > summary') : null;
        if (!resumo) return;
        // Link ou botão DENTRO do resumo segue o caminho dele.
        if (e.target.closest('a, button, input, select, textarea')) return;
        e.preventDefault();
        alternar(resumo.parentElement);
    });
}
