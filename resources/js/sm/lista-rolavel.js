// Listas longas de Contas a pagar rolam POR DENTRO (14/10/2026 — pedido do Victor: "no máximo
// até 8, depois ativa rolagem"): `data-rolagem="8"` mostra até 8 itens; do 9º em diante a lista
// fica da altura dos 8 primeiros e rola. A altura é medida (cada item tem a altura que o texto
// pede), não estimada. Lista dentro de um `<details>` fechado não tem altura: é medida quando
// abre — o `details-suave.js` chama `limitarListasEm` antes de medir a animação, para ela já
// terminar na altura certa. Sem JS, a lista aparece inteira.

/** Limita uma lista ao número de itens do `data-rolagem`. Devolve true se ela passou a rolar. */
export function limitarLista(lista) {
    const maximo = Number(lista.dataset.rolagem) || 0;
    const itens = Array.from(lista.children);
    lista.style.maxHeight = '';
    lista.classList.remove('rolando');
    if (!maximo || itens.length <= maximo) return false;

    const topo = lista.getBoundingClientRect().top;
    const fim = itens[maximo - 1].getBoundingClientRect().bottom;
    if (fim - topo <= 0) return false; // escondida (details fechado): mede quando abrir
    lista.style.maxHeight = `${Math.ceil(fim - topo)}px`;
    lista.classList.add('rolando');
    return true;
}

/** Limita todas as listas dentro de `raiz` (o documento, ou o `<details>` que acabou de abrir). */
export function limitarListasEm(raiz) {
    raiz.querySelectorAll('[data-rolagem]').forEach(limitarLista);
}

export function initListasRolaveis(doc = document) {
    limitarListasEm(doc);
    if (doc.documentElement.dataset.listasRolaveis) return;
    doc.documentElement.dataset.listasRolaveis = '1';
    // `toggle` não sobe pela árvore: o ouvinte é de captura. Cobre o `<details>` aberto sem o
    // `details-suave` (sem animação, ou pelo teclado em navegador que não gera clique).
    doc.addEventListener('toggle', (e) => {
        if (e.target.open) limitarListasEm(e.target);
    }, true);
    let espera = null;
    doc.defaultView?.addEventListener('resize', () => {
        clearTimeout(espera);
        espera = setTimeout(() => limitarListasEm(doc), 150);
    });
}
