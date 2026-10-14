// Abrir e fechar SUAVE dos blocos recolhíveis de Contas a pagar (out/2026 — pedido do Victor: "o
// abrir e fechar está muito seco"). O `<details>` nativo abre de uma vez; aqui a altura desliza
// entre o fechado e o aberto (Web Animations, sem mexer no CSS do bloco) e o conteúdo surge junto.
// Sem a API de animação ou com "reduzir movimento", fica o comportamento nativo. Vale para todo
// `details[data-suave]`, inclusive o que chega pelo pjax (o ouvinte é um só, no documento).

const DURACAO = 300;
const CURVA = 'cubic-bezier(.22, .8, .24, 1)';

function semMovimento(janela) {
    return janela.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
}

/** Abre ou fecha `detalhes` com a altura deslizando. Devolve a animação (ou null, sem animar). */
export function alternar(detalhes) {
    const janela = detalhes.ownerDocument.defaultView;
    if (detalhes.dataset.animando) return null;
    if (typeof detalhes.animate !== 'function' || semMovimento(janela)) {
        detalhes.open = !detalhes.open;
        return null;
    }

    const abrindo = !detalhes.open;
    const antes = detalhes.offsetHeight;
    // Mede o outro estado sem pintar: as trocas abaixo acontecem no mesmo quadro.
    detalhes.open = !detalhes.open;
    const depois = detalhes.offsetHeight;
    if (!abrindo) detalhes.open = true; // fecha só no fim, para o conteúdo sumir deslizando

    detalhes.dataset.animando = '1';
    detalhes.style.overflow = 'hidden';
    const animacao = detalhes.animate(
        [{ height: `${antes}px` }, { height: `${depois}px` }],
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
