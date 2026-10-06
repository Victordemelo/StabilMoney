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
    // O mesmo teto de 14 dígitos do `money.js` (que pode ainda não ter cortado a tecla).
    const digitos = String(campo?.value ?? '').replace(/\D/g, '').replace(/^0+(?=\d)/, '').slice(0, 14);
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
 * No cartão de crédito o "saldo" é o limite livre — os rótulos acompanham, e o "depois"
 * para em R$ 0,00 (o limite não fica negativo): o excesso vai para o aviso.
 */
export function desenharResumo(el, { saldo, valor, sinal = -1, rotuloValor, cartao = false, obrigacao = false, reservar = false }) {
    if (!el) return;

    if (saldo === null || saldo === undefined) {
        // `reservar`: some mas guarda o espaço (o modal Lançar não muda de altura ao trocar de tipo).
        el.hidden = !reservar;
        el.classList.toggle('reservado', reservar);
        return;
    }
    el.classList.remove('reservado');

    const centavos = (n) => Math.round(n * 100);
    const depoisReal = (centavos(saldo) + sinal * centavos(valor || 0)) / 100;
    // O limite livre do cartão vai até 0, nunca abaixo (decisão do Victor): o que passar
    // dele não vira "limite negativo" — o servidor recusa a compra, e o aviso diz quanto falta.
    const passaDoLimite = cartao && depoisReal < 0 ? -depoisReal : 0;
    const depois = passaDoLimite ? 0 : depoisReal;
    const campo = (nome) => el.querySelector(`[data-rs-${nome}]`);
    // Só reescreve o que mudou: menos trabalho e nada de anúncio repetido por leitor de tela.
    const escrever = (nome, texto) => { const c = campo(nome); if (c && c.textContent !== texto) c.textContent = texto; };

    escrever('rotulo-atual', cartao ? 'Limite livre agora' : 'Saldo atual');
    escrever('atual', formatarBrl(saldo));
    escrever('operador', sinal < 0 ? '−' : '+');
    escrever('rotulo-valor', rotuloValor || (sinal < 0 ? 'Este pagamento' : 'Esta receita'));
    escrever('valor', formatarBrl(valor || 0));
    escrever('rotulo-depois', cartao ? 'Limite livre depois' : 'Saldo depois');
    escrever('depois', formatarBrl(depois));

    campo('atual')?.classList.toggle('neg', saldo < 0);
    campo('depois')?.classList.toggle('neg', depois < 0 || passaDoLimite > 0);

    const aviso = campo('aviso');
    if (aviso) {
        const fica = (depois < 0 || passaDoLimite > 0) && sinal < 0;
        // Obrigação (fatura, conta fixa) passa e a conta fica negativa; gasto NOVO sem fonte que
        // cubra é recusado pelo servidor — o texto não pode prometer que ele passa.
        const texto = !fica ? ''
            : cartao ? `Passa do limite livre em ${formatarBrl(passaDoLimite)}: o cartão não aceita esta compra.`
                : obrigacao ? 'O saldo fica negativo. Se a conta tiver cheque especial ou investimento, você escolhe de onde sai o que faltar.'
                    : 'Falta dinheiro na conta: ao salvar, você escolhe se cobre com o cheque especial ou com um investimento. Sem nenhum dos dois, o lançamento não é aceito.';
        aviso.hidden = !fica;
        if (aviso.textContent !== texto) aviso.textContent = texto;
    }

    el.hidden = false;
}
