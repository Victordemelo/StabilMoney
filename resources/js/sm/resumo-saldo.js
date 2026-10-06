/**
 * Resumo do saldo nas janelas de pagamento (out/2026 — pedido do Victor): antes de confirmar,
 * a pessoa vê o saldo de agora, o que sai (ou entra) e como a conta fica depois.
 *
 *     Saldo atual  R$ 1.000,00   −  Esta fatura  R$ 300,00   =  Saldo depois  R$ 700,00
 *
 * A marcação vem do `partials/resumo-de-saldo` e quem desenha é `desenharResumo` — os modais
 * (Lançar, Lançar despesa, Pagar fatura, Pagar conta fixa) só dizem os números. O saldo de
 * cada conta viaja na `<option>` (`data-saldo-valor`, número cru, e `data-saldo-rotulo`), como
 * o servidor o calcula (`Account::available`; no cartão, o limite livre). É informação, não
 * trava: quem decide se o pagamento passa continua sendo o servidor.
 *
 * Texto sempre por `textContent`.
 */

const BRL = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });

/** "−R$ 1.234,56": o sinal antes do símbolo, com o traço U+2212 (como o `@brl` do servidor). */
export function formatarBrl(valor) {
    const texto = BRL.format(Math.abs(valor)).replace(/ /g, ' ');
    return valor < 0 ? `−${texto}` : texto;
}

/**
 * Valor de um campo de dinheiro já mascarado ("1.234,56") — a máscara do `money.js` deixa
 * sempre duas casas, então os dígitos são centavos. Vazio vale 0.
 */
export function lerValorDoCampo(campo) {
    const digitos = String(campo?.value ?? '').replace(/\D/g, '');
    return digitos === '' ? 0 : Number(digitos) / 100;
}

/** Saldo da opção marcada num select de conta, ou null se ela não traz o número. */
export function saldoDaOpcao(select) {
    const opt = select?.selectedOptions?.[0];
    if (!opt || opt.disabled || opt.dataset.saldoValor === undefined) return null;
    const numero = Number(opt.dataset.saldoValor);
    if (!Number.isFinite(numero)) return null;
    return { saldo: numero, rotulo: opt.dataset.saldoRotulo || 'disponível' };
}

/**
 * Desenha o resumo. `sinal` −1 = sai dinheiro (pagamento, despesa, transferência);
 * +1 = entra (receita). `saldo` null esconde o resumo inteiro (sem conta escolhida).
 *
 * No cartão de crédito o "saldo" é o limite livre — os rótulos acompanham.
 */
export function desenharResumo(el, { saldo, valor, sinal = -1, rotuloValor, cartao = false }) {
    if (!el) return;

    if (saldo === null || saldo === undefined) {
        el.hidden = true;
        return;
    }

    const centavos = (n) => Math.round(n * 100);
    const depois = (centavos(saldo) + sinal * centavos(valor || 0)) / 100;
    const campo = (nome) => el.querySelector(`[data-rs-${nome}]`);
    const escrever = (nome, texto) => { const c = campo(nome); if (c) c.textContent = texto; };

    escrever('rotulo-atual', cartao ? 'Limite livre agora' : 'Saldo atual');
    escrever('atual', formatarBrl(saldo));
    escrever('operador', sinal < 0 ? '−' : '+');
    escrever('rotulo-valor', rotuloValor || (sinal < 0 ? 'Este pagamento' : 'Esta receita'));
    escrever('valor', formatarBrl(valor || 0));
    escrever('rotulo-depois', cartao ? 'Limite livre depois' : 'Saldo depois');
    escrever('depois', formatarBrl(depois));

    campo('atual')?.classList.toggle('neg', saldo < 0);
    campo('depois')?.classList.toggle('neg', depois < 0);

    const aviso = campo('aviso');
    if (aviso) {
        const fica = depois < 0 && sinal < 0;
        aviso.hidden = !fica;
        aviso.textContent = fica
            ? (cartao
                ? 'Passa do limite livre do cartão.'
                : 'O saldo fica negativo. Se a conta tiver cheque especial ou investimento, você escolhe de onde sai o que faltar.')
            : '';
    }

    el.hidden = false;
}
