/**
 * Formatação de campos monetários no padrão BRL ("1.234,56") ao SAIR do campo
 * (blur). Aplica-se a todo input com inputmode="decimal" (valores e taxas).
 *
 * Regras:
 *  - Nunca aceita negativo: o sinal de menos é descartado (o sistema não
 *    trabalha com valores negativos; o sinal vem do tipo, não do valor).
 *  - Campo vazio continua vazio (não vira "0,00").
 *  - Entende tanto "1.234,56" quanto "1234.56" / "1234" digitados pelo usuário.
 *  - Campos marcados com `data-no-money` são ignorados (ex.: taxa em %, que não
 *    é moeda e não deve ganhar separador de milhar nem forçar 2 casas).
 */
export function initMoney() {
    document.querySelectorAll('input[inputmode="decimal"]:not([data-no-money])').forEach((input) => {
        if (input.dataset.moneyBound) return; // evita duplicar listener
        input.dataset.moneyBound = '1';
        input.addEventListener('blur', () => {
            input.value = formatBRL(input.value);
        });
    });
}

/** Converte qualquer entrada para "1.234,56" (sem sinal). String vazia se inválido/vazio. */
function formatBRL(raw) {
    let s = String(raw).trim();
    if (s === '') return '';

    // Mantém só dígitos, ponto e vírgula (remove R$, %, espaços, sinal de menos…).
    s = s.replace(/[^\d.,]/g, '');
    if (s === '') return '';

    let normalized;
    if (s.includes(',')) {
        // vírgula = decimal; pontos = milhar
        normalized = s.replace(/\./g, '').replace(',', '.');
    } else if (/^\d{1,3}(\.\d{3})+$/.test(s)) {
        // só milhares: "1.234" -> "1234"
        normalized = s.replace(/\./g, '');
    } else {
        // ponto como decimal ("12.5") ou inteiro simples
        normalized = s;
    }

    const n = Math.abs(parseFloat(normalized));
    if (!isFinite(n)) return '';

    return n.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
