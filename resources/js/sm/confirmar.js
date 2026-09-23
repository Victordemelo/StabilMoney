/* ============ StabilMoney — Confirmar antes de enviar (`form[data-confirmar]`) ============ */
//
// Desde a CSP com nonce (05/08/2026), TODO manipulador de evento inline (`onsubmit="..."`,
// `onclick="..."`) é bloqueado pelo navegador: atributo de evento não aceita nonce, e o
// `script-src` não tem `'unsafe-inline'`. Os `onsubmit="return confirm(...)"` das telas
// deixaram de rodar — em silêncio, só o console avisava — e "Excluir" passou a apagar
// direto, sem perguntar nada (confirmado num Chromium real).
//
// O substituto é DECLARATIVO: o formulário leva a pergunta num atributo comum,
//
//     <form method="POST" action="..." data-confirmar="Excluir esta categoria? ...">
//
// escapado pelo Blade (`{{ }}`) como qualquer atributo — nunca um pedaço de JavaScript. O
// `confirm('Remover {{ $nome }}? ...')` de antes ainda punha o nome DENTRO de uma string JS
// num atributo: um apóstrofo no nome ("D'Ávila") fechava a string, porque o `&#039;` que o
// `e()` gera volta a ser `'` quando o navegador lê o HTML.
//
// A pergunta é o `confirm()` nativo: modal, acessível por teclado e leitor de tela sem
// nada nosso, e síncrono — o que o `submit` precisa para decidir na hora se segue. Sem JS,
// o formulário envia direto, como antes da CSP.

let ligado = false;

/**
 * A pergunta do formulário. Uma caixa MARCADA dele pode trazer a sua própria
 * (`data-confirmar-marcado`), para o "tem certeza?" dizer o que vai acontecer de fato —
 * ex.: excluir uma cobrança recorrente com ou sem "Encerrar também a recorrência".
 *
 * `form.elements`, e não `querySelector`: vale também para uma caixa ligada ao formulário
 * pelo atributo `form="..."`, fora dele no HTML.
 */
function perguntaDo(form) {
    const marcada = Array.from(form.elements).find(
        (campo) => campo instanceof HTMLInputElement && campo.checked && campo.hasAttribute('data-confirmar-marcado'),
    );

    return marcada ? marcada.getAttribute('data-confirmar-marcado') : form.getAttribute('data-confirmar');
}

/**
 * Liga UM ouvinte de `submit` no documento, em fase de CAPTURA: roda antes dos ouvintes
 * do próprio formulário (o "trava o botão durante o envio" das metas, por exemplo), e,
 * quando a pessoa desiste, para o evento ali — o envio não acontece e o botão não fica
 * travado à toa.
 *
 * Delegado no `document`, e não formulário a formulário, para valer também no conteúdo que
 * chega pelo pjax (o `#content` é trocado inteiro) sem ninguém precisar religar nada.
 */
export function initConfirmar() {
    if (ligado) return;
    ligado = true;

    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-confirmar')) return;

        if (window.confirm(perguntaDo(form))) return;

        e.preventDefault();
        e.stopPropagation();
    }, true);
}
