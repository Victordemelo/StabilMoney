/**
 * Modal "De onde sai esse dinheiro?" — o consumidor do HTTP 409.
 *
 * Quando uma despesa não cabe no saldo disponível mas existe fonte (cheque
 * especial e/ou resgate de investimento), o servidor responde 409 com as
 * opções em vez de 422. Este módulo mostra a escolha e devolve ao chamador os
 * campos a acrescentar no reenvio (`funding_source`, `funding_investment_id`).
 *
 * Não conhece formulário nenhum: quem chama passa o payload e recebe uma
 * Promise com a escolha (ou null se o usuário cancelar). Isso deixa o mesmo
 * modal servir o modal global "Lançar", o formulário cheio e a tela de faturas.
 */

let scrim = null;
let resolver = null;

function q(sel) {
    return scrim ? scrim.querySelector(sel) : null;
}

/** "1.234,56" com o sinal antes do R$, igual ao Brl::format do servidor. */
function brl(valor) {
    const n = Number(valor) || 0;
    const sinal = n < 0 ? '−' : '';
    return sinal + 'R$ ' + Math.abs(n).toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

function fechar(escolha) {
    if (!scrim) return;
    scrim.classList.remove('open');
    const pendente = resolver;
    resolver = null;
    if (pendente) pendente(escolha);
}

/** Monta uma opção (radio + detalhe + select de investimento quando for o caso). */
function montarOpcao(fonte, faltante) {
    const wrap = document.createElement('label');
    wrap.className = 'fonte-opt' + (fonte.cobre ? '' : ' disabled');

    const radio = document.createElement('input');
    radio.type = 'radio';
    radio.name = 'sm-funding-source';
    radio.value = fonte.id;
    radio.disabled = !fonte.cobre;

    const texto = document.createElement('div');
    texto.className = 'fonte-txt';

    const titulo = document.createElement('strong');
    titulo.textContent = fonte.rotulo;

    const detalhe = document.createElement('span');
    detalhe.textContent = fonte.cobre
        ? fonte.detalhe
        : 'Não cobre: o máximo por aqui é ' + brl(fonte.teto) + '.';

    texto.append(titulo, detalhe);
    wrap.append(radio, texto);

    // Resgate: precisa escolher de qual investimento.
    if (fonte.id === 'resgate_investimento' && Array.isArray(fonte.itens)) {
        const select = document.createElement('select');
        select.className = 'input';
        select.dataset.fundingInvestimento = '1';
        select.disabled = !fonte.cobre;

        fonte.itens.forEach((item) => {
            const opt = document.createElement('option');
            opt.value = item.id;
            opt.textContent = item.nome + ' — ' + brl(item.aplicado) + ' aplicados';
            opt.disabled = !item.cobre;
            select.appendChild(opt);
        });

        const dica = document.createElement('small');
        dica.className = 'field-hint';
        dica.textContent = 'Vamos resgatar ' + brl(faltante) + ' — o resto continua investido.';

        texto.append(select, dica);
    }

    return wrap;
}

/**
 * Abre o modal com as opções do 409 e resolve com
 * `{ funding_source, funding_investment_id }` ou `null` se cancelar.
 */
export function pedirFonte(payload) {
    scrim = document.getElementById('fundingModal');
    if (!scrim || !payload) return Promise.resolve(null);

    const resumo = q('[data-funding-resumo]');
    const lista = q('[data-funding-opcoes]');
    const semSaida = q('[data-funding-sem-saida]');
    const confirmar = q('[data-funding-confirm]');

    const fontes = (payload.fontes || []).filter((f) => f && f.id);
    const alguemCobre = fontes.some((f) => f.cobre);

    if (resumo) {
        resumo.textContent = 'A conta ' + (payload.conta?.nome || '') + ' tem '
            + brl(payload.disponivel) + ' disponíveis e esta despesa é de '
            + brl(payload.valor) + '. Faltam ' + brl(payload.faltante) + '.';
    }

    if (lista) {
        lista.textContent = '';
        fontes.forEach((f) => lista.appendChild(montarOpcao(f, payload.faltante)));
        // Já deixa marcada a primeira opção viável.
        const primeira = lista.querySelector('input[type="radio"]:not(:disabled)');
        if (primeira) primeira.checked = true;
    }

    if (semSaida) {
        semSaida.hidden = alguemCobre;
        const msg = semSaida.querySelector('[data-funding-sem-saida-msg]');
        if (msg && !alguemCobre) {
            msg.textContent = 'Nenhuma fonte cobre esta despesa. Lance um recebimento '
                + 'para completar o valor, ou reduza o gasto.';
        }
    }
    if (confirmar) confirmar.disabled = !alguemCobre;

    scrim.classList.add('open');
    
    return new Promise((resolve) => {
        resolver = resolve;
    });
}

/** Liga os botões do modal (chamado uma vez por página, via initContent). */
export function initFunding() {
    const el = document.getElementById('fundingModal');
    if (!el || el.dataset.fundingBound) return;
    el.dataset.fundingBound = '1';
    scrim = el;

    el.querySelectorAll('[data-funding-close]').forEach((b) => {
        b.addEventListener('click', () => fechar(null));
    });
    // Clique no fundo fecha (mesmo comportamento dos outros modais do app).
    el.addEventListener('click', (e) => {
        if (e.target === el) fechar(null);
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && el.classList.contains('open')) fechar(null);
    });

    const confirmar = el.querySelector('[data-funding-confirm]');
    if (confirmar) {
        confirmar.addEventListener('click', () => {
            const marcado = el.querySelector('input[name="sm-funding-source"]:checked');
            if (!marcado) return;

            const escolha = { funding_source: marcado.value };

            if (marcado.value === 'resgate_investimento') {
                const select = marcado.closest('.fonte-opt')?.querySelector('[data-funding-investimento]');
                if (!select || !select.value) return;
                escolha.funding_investment_id = select.value;
            }

            fechar(escolha);
        });
    }
}
