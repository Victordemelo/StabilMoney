// O ícone ao lado de um select de conta (14/10/2026 — pedido do Victor: "botar o ícone do lado
// do Pagar com; a mesma coisa na receita e na transferência"). O select fica dentro de um
// `.sel-ico`; cada opção traz `data-icone` (a URL) e `data-icone-forma` ("cartao" desenha o
// cartãozinho, "quadrado" o ícone do banco, do Pix ou do TED). Opção sem ícone (conta sem banco,
// "Todas as contas"), ou desabilitada, deixa o select sem desenho.
//
// A troca pela pessoa chega pelo `change` (um ouvinte só, no documento: vale para o que o pjax
// traz). Quem troca a escolha por código (o Lançar ao mudar o tipo, o editar conta fixa) chama
// `atualizarIcone` depois — mudar `.value` ou `.selected` não dispara evento.

/** Desenha o ícone da opção escolhida. */
export function atualizarIcone(select) {
    const caixa = select?.closest?.('.sel-ico');
    if (!caixa) return;
    let img = caixa.querySelector(':scope > .sel-ico-img');
    if (!img) {
        img = caixa.ownerDocument.createElement('img');
        img.className = 'sel-ico-img';
        img.alt = '';
        img.setAttribute('aria-hidden', 'true');
        caixa.prepend(img);
    }
    // `options[selectedIndex]`, não `selectedOptions[0]`: depois de um `form.reset()` o jsdom
    // devolve a opção antiga em `selectedOptions` (o índice é o que acompanha o valor).
    const opcao = select.selectedIndex >= 0 ? select.options[select.selectedIndex] : null;
    const url = opcao && !opcao.disabled ? (opcao.dataset.icone || '') : '';
    if (url) {
        if (img.getAttribute('src') !== url) img.setAttribute('src', url);
        img.hidden = false;
        caixa.dataset.forma = opcao.dataset.iconeForma === 'cartao' ? 'cartao' : 'quadrado';
        caixa.classList.add('com-icone');
    } else {
        img.hidden = true;
        img.removeAttribute('src');
        caixa.classList.remove('com-icone');
    }
}

/** Todos os selects com ícone dentro de `raiz`. */
export function atualizarIcones(raiz = document) {
    raiz.querySelectorAll('.sel-ico > select').forEach(atualizarIcone);
}

export function initSelectIcone(doc = document) {
    atualizarIcones(doc);
    if (doc.documentElement.dataset.selectIcone) return;
    doc.documentElement.dataset.selectIcone = '1';
    doc.addEventListener('change', (e) => {
        if (e.target.matches?.('.sel-ico > select')) atualizarIcone(e.target);
    });
    // `form.reset()` volta a escolha ao padrão sem `change`: redesenha depois do reset.
    doc.addEventListener('reset', (e) => {
        const form = e.target; // guardado já: depois do despacho o evento não garante o alvo
        doc.defaultView.setTimeout(() => atualizarIcones(form), 0);
    }, true);
}
