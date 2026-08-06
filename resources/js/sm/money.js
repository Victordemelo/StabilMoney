/**
 * Máscara monetária BRL ("1.234,56") aplicada EM TEMPO REAL, a cada tecla.
 * Vale para todo input com inputmode="decimal" (valores e taxas).
 *
 * ## Como funciona: os dígitos entram pelos CENTAVOS
 *
 * Só os dígitos contam, e o número é montado da direita para a esquerda:
 *
 *     1        → 0,01
 *     12       → 0,12
 *     123      → 1,23
 *     130000   → 1.300,00
 *
 * É o comportamento dos apps de banco brasileiros, e tem uma vantagem que o
 * formato livre não tinha: **nunca é ambíguo**. Digitando "1300" no modo antigo
 * não dava para saber se a pessoa queria mil e trezentos ou treze reais — o campo
 * só se resolvia no blur, e o valor "pulava" na cara de quem digitou. Aqui o que
 * está na tela é sempre o valor final.
 *
 * Consequência aceita: vírgula e ponto digitados são IGNORADOS, porque a posição
 * decimal é fixa. Quem cola "1.234,56" continua obtendo 1.234,56 (os dígitos são
 * 123456), e quem cola "1234.56" também.
 *
 * ## Regras que não mudaram
 *  - Nunca aceita negativo: o sinal de menos é descartado (o sinal vem do `type`
 *    da transação, nunca do valor).
 *  - Campo vazio continua VAZIO — não vira "0,00", senão todo formulário abriria
 *    com zero preenchido e o `required` deixaria de proteger.
 *  - `data-no-money` é ignorado (ex.: taxa em %, que não é moeda e não leva
 *    separador de milhar nem duas casas forçadas).
 */

/** Teto de dígitos: 999.999.999.999,99 — o mesmo TETO_MONETARIO do servidor. */
const MAX_DIGITOS = 14;

export function initMoney() {
    document.querySelectorAll('input[inputmode="decimal"]:not([data-no-money])').forEach((input) => {
        if (input.dataset.moneyBound) return; // evita duplicar listener
        input.dataset.moneyBound = '1';

        // Valor vindo do servidor (edição) é normalizado uma vez, para a tela abrir
        // no mesmo formato que o usuário vai ver enquanto digita.
        if (input.value.trim() !== '') input.value = formatarDigitos(soDigitos(input.value));

        input.addEventListener('input', () => {
            input.value = formatarDigitos(soDigitos(input.value));
            // O cursor vai para o fim: a máscara reescreve a string inteira a cada
            // tecla, então manter a posição antiga deixaria o cursor no meio de um
            // separador que acabou de se mover.
            const fim = input.value.length;
            input.setSelectionRange?.(fim, fim);
        });

        // O blur continua normalizando: cobre autofill do navegador e valor
        // preenchido por script, que não disparam `input`.
        input.addEventListener('blur', () => {
            input.value = formatarDigitos(soDigitos(input.value));
        });
    });
}

/** Só os dígitos — fora separadores, R$, espaços, zeros à esquerda e o sinal de menos. */
function soDigitos(raw) {
    return String(raw).replace(/\D/g, '').replace(/^0+(?=\d)/, '').slice(0, MAX_DIGITOS);
}

/** Monta "1.234,56" a partir dos dígitos acumulados. Vazio continua vazio. */
function formatarDigitos(digitos) {
    if (digitos === '') return '';

    const centavos = digitos.padStart(3, '0');
    const inteiros = centavos.slice(0, -2);
    const decimais = centavos.slice(-2);

    return `${Number(inteiros).toLocaleString('pt-BR')},${decimais}`;
}
