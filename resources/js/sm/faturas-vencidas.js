// "Já paguei essa fatura fora do app" (out/2026 — pedido do Victor): a caixa aparece quando uma
// DESPESA no CARTÃO tem data numa fatura que já venceu — a opção do cartão traz, em
// `data-corte-vencidas`, o fechamento da fatura vencida mais recente. Fora disso a caixa fica
// escondida e DESABILITADA (não vai no envio). Serve o modal Lançar, a página cheia de nova
// transação e o "Lançar despesa" de Contas a pagar: cada um tem um bloco `[data-vencidas-pagas]`
// que diz, por id, qual é o select do método e qual é o campo de data.

/** O tipo do lançamento no formulário (Contas a pagar só lança despesa). */
function tipoDo(form) {
    const marcado = form.querySelector('input[name="type"]:checked') || form.querySelector('input[type="hidden"][name="type"]');
    return marcado ? marcado.value : 'expense';
}

/** Mostra ou esconde a caixa de um bloco conforme o método, a data e o tipo escolhidos. */
export function atualizarBloco(bloco) {
    const doc = bloco.ownerDocument;
    const form = bloco.closest('form');
    const conta = doc.getElementById(bloco.dataset.conta);
    const data = doc.getElementById(bloco.dataset.data);
    const modo = bloco.dataset.modo ? doc.getElementById(bloco.dataset.modo) : null;
    const caixa = bloco.querySelector('input[type="checkbox"]');
    if (!form || !conta || !data || !caixa) return;

    const opt = conta.selectedOptions[0];
    const corte = opt && !opt.disabled ? opt.dataset.corteVencidas || '' : '';
    const iso = data.value || '';
    const mostrar = tipoDo(form) === 'expense'
        && (!modo || modo.value !== 'recorrente')
        && corte !== '' && /^\d{4}-\d{2}-\d{2}$/.test(iso) && iso <= corte;

    // Reaparecendo, volta marcada: o padrão é "já paguei" (a pessoa desmarca se não pagou).
    if (mostrar && bloco.hidden) caixa.checked = true;
    bloco.hidden = !mostrar;
    caixa.disabled = !mostrar;
}

export function atualizarFaturasVencidas(raiz = document) {
    raiz.querySelectorAll('[data-vencidas-pagas]').forEach(atualizarBloco);
}

/** Um ouvinte só, no documento: vale para o modal do shell e para o que chega pelo pjax. */
export function initFaturasVencidas(doc = document) {
    if (doc.documentElement.dataset.vencidasLigado) return;
    doc.documentElement.dataset.vencidasLigado = '1';
    const aoMudar = (e) => {
        const form = e.target && e.target.closest ? e.target.closest('form') : null;
        if (form) atualizarFaturasVencidas(form);
    };
    doc.addEventListener('input', aoMudar);
    doc.addEventListener('change', aoMudar);
    // Os botões de modo de Contas a pagar (À vista/Parcelado/Recorrente) trocam um hidden por código.
    doc.addEventListener('click', (e) => {
        if (e.target && e.target.closest && e.target.closest('form [type="button"]')) aoMudar(e);
    });
    // `form.reset()` (o modal Lançar zera a cada abertura) não dispara input/change.
    doc.addEventListener('reset', (e) => setTimeout(() => aoMudar(e)), true);
    atualizarFaturasVencidas(doc);
}
