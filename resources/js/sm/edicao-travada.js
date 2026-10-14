// Editar movimentação com os campos TRAVADOS (out/2026 — pedido do Victor): a tela abre só para
// ler; o botão "Editar movimentação" (ao lado, acima do Excluir) libera os campos e mostra Salvar e
// Cancelar. O Cancelar desfaz o digitado e trava de novo. Sem JS, o formulário vem liberado como
// sempre; com erro de validação (o servidor marca `data-tx-liberado`), já abre liberado.

const CAMPOS = 'input:not([type="hidden"]), select, textarea, button:not([data-tx-ignorar])';

function campos(form) {
    return Array.from(form.querySelectorAll(CAMPOS)).filter((el) => !el.closest('[data-tx-acoes]'));
}

export function travar(form, doc = document) {
    campos(form).forEach((el) => {
        if (el.disabled) return; // o que já vinha desabilitado continua (não é nosso)
        el.disabled = true;
        el.dataset.txTravado = '1';
    });
    form.dataset.txTravada = '1';
    const acoes = form.querySelector('[data-tx-acoes]');
    if (acoes) acoes.hidden = true;
    const cartao = doc.querySelector('[data-tx-editar]');
    if (cartao) cartao.hidden = false;
}

export function liberar(form, doc = document) {
    form.querySelectorAll('[data-tx-travado]').forEach((el) => {
        el.disabled = false;
        delete el.dataset.txTravado;
    });
    delete form.dataset.txTravada;
    const acoes = form.querySelector('[data-tx-acoes]');
    if (acoes) acoes.hidden = false;
    const cartao = doc.querySelector('[data-tx-editar]');
    if (cartao) cartao.hidden = true;
    const primeiro = campos(form).find((el) => !el.readOnly && !el.disabled && el.offsetParent !== null) || campos(form).find((el) => !el.readOnly && !el.disabled);
    primeiro?.focus();
}

export function initEdicaoTravada(doc = document) {
    const form = doc.querySelector('form[data-tx-travavel]');
    if (!form || form.dataset.txLigado) return;
    form.dataset.txLigado = '1';

    if (form.hasAttribute('data-tx-liberado')) {
        liberar(form, doc);
    } else {
        travar(form, doc);
    }

    doc.querySelector('[data-tx-liberar]')?.addEventListener('click', () => liberar(form, doc));
    form.querySelector('[data-tx-cancelar]')?.addEventListener('click', (e) => {
        e.preventDefault();
        form.reset();
        travar(form, doc);
        doc.querySelector('[data-tx-liberar]')?.focus();
    });
}
