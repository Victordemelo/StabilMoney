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
 *
 * É um DIÁLOGO de verdade (sm/dialogo.js), e muitas vezes EMPILHADO: abre por
 * cima do Lançar ou do "Pagar fatura". O Esc fecha só ele — resolvendo com
 * "cancelou" —, e o foco volta ao botão que disparou o envio.
 */

import { abrirDialogo, fecharDialogo } from './dialogo';

let scrim = null;
let resolver = null;
// Faltante que a tela ATUAL mostrou. Vive no escopo do módulo porque o
// handler do "Confirmar" é ligado uma única vez (initFunding), enquanto o valor
// muda a cada abertura. Vira o teto `funding_max_amount` do reenvio.
let faltanteAprovado = 0;

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
    // Devolve a página e o foco ANTES de resolver: quem chamou retoma o envio com o
    // foco já de volta no botão dele.
    fecharDialogo(scrim);
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
    // `motivo` é a explicação PT-BR do servidor (o teto sozinho não conta a
    // história: no resgate ele é o MAIOR investimento, não o total aplicado).
    detalhe.textContent = fonte.cobre
        ? fonte.detalhe
        : (fonte.motivo || 'Não cobre: o máximo por aqui é ' + brl(fonte.teto) + '.');

    texto.append(titulo, detalhe);
    wrap.append(radio, texto);

    // Resgate: precisa escolher de qual investimento. O servidor aceita UM
    // `funding_investment_id`, então cada item traz seu próprio `cobre` — os que
    // não cobrem sozinhos ficam desabilitados, com o porquê no rótulo.
    if (fonte.id === 'resgate_investimento' && Array.isArray(fonte.itens)) {
        const select = document.createElement('select');
        select.className = 'input';
        select.dataset.fundingInvestimento = '1';
        select.disabled = !fonte.cobre;
        // O <label> em volta nomeia o RADIO (o primeiro controle dele), não este
        // select — sem nome próprio o leitor de tela anunciaria só "caixa de seleção".
        select.setAttribute('aria-label', 'Investimento a resgatar');

        fonte.itens.forEach((item) => {
            const opt = document.createElement('option');
            opt.value = item.id;
            opt.textContent = item.nome + ' — ' + brl(item.aplicado) + ' aplicados'
                + (item.cobre ? '' : ' (não cobre sozinho)');
            opt.disabled = !item.cobre;
            select.appendChild(opt);
        });

        // O navegador seleciona a 1ª option mesmo desabilitada; sem isto o
        // usuário confirmaria um investimento que o servidor vai recusar.
        const viavel = fonte.itens.find((item) => item.cobre);
        if (viavel) select.value = String(viavel.id);

        const dica = document.createElement('small');
        dica.className = 'field-hint';
        dica.textContent = fonte.cobre
            ? 'Vamos resgatar ' + brl(faltante) + ' — o resto continua investido.'
            : 'Para juntar mais de um investimento, resgate na tela de Investimentos e lance a despesa depois.';

        texto.append(select, dica);
    }

    return wrap;
}

/**
 * Abre o modal com as opções do 409 e resolve com
 * `{ funding_source, funding_investment_id }` ou `null` se cancelar.
 *
 * `retorno` = onde o foco volta quando o modal fecha — o botão que disparou o envio.
 * Precisa vir de quem chama: o botão fica desabilitado durante o envio, e o Chrome tira o
 * foco de botão desabilitado; na hora de abrir, o foco já está no <body>.
 */
export function pedirFonte(payload, { retorno = null } = {}) {
    scrim = document.getElementById('fundingModal');
    if (!scrim || !payload) return Promise.resolve(null);

    const resumo = q('[data-funding-resumo]');
    const lista = q('[data-funding-opcoes]');
    const semSaida = q('[data-funding-sem-saida]');
    const confirmar = q('[data-funding-confirm]');

    const fontes = (payload.fontes || []).filter((f) => f && f.id);
    const alguemCobre = fontes.some((f) => f.cobre);
    faltanteAprovado = Number(payload.faltante) || 0;

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
            // O motivo do resgate é o que aponta a saída (resgatar mais de um em
            // Investimentos); sem ele o usuário fica só com "não dá".
            const resgate = fontes.find((f) => f.id === 'resgate_investimento');
            msg.textContent = 'Nenhuma fonte cobre esta despesa sozinha. '
                + (resgate?.motivo ? resgate.motivo + ' ' : '')
                + 'Lance um recebimento para completar o valor, ou reduza o gasto.';
        }
    }
    if (confirmar) confirmar.disabled = !alguemCobre;

    const escolha = new Promise((resolve) => {
        resolver = resolve;
    });

    // O foco entra na primeira opção viável (a já marcada); o título e o resumo vêm
    // junto pelo aria-labelledby/aria-describedby do painel. Esc = "cancelou".
    abrirDialogo(scrim, { aoPedirFechar: () => fechar(null), retorno });

    return escolha;
}

/**
 * Envia um formulário por AJAX já tratando o 409 "de onde sai esse dinheiro?".
 *
 * É o mesmo laço do modal global "Lançar", extraído para os pagamentos de
 * fatura e de conta fixa não reimplementarem (achado A-2 da auditoria: eles
 * eram POST comum e o 409 ficava sem consumidor — o clique não fazia nada).
 *
 * `redirect: 'manual'` de propósito: em sucesso o servidor responde 302 e não
 * queremos que o fetch baixe a página inteira — pior, seguir o redirect
 * CONSUMIRIA o flash de sucesso, e a mensagem sumiria do recarregamento.
 *
 * `retorno` é repassado ao `pedirFonte`: o botão de enviar, que recebe o foco de volta
 * quando o modal de fonte fecha.
 *
 * @returns {Promise<Response|null>} a resposta final, ou null se o usuário
 *          cancelou a escolha da fonte.
 */
export async function enviarComFonte(url, formData, { retorno = null } = {}) {
    const enviar = () => fetch(url, {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: formData,
        redirect: 'manual',
    });

    let resp = await enviar();

    // O 409 pode voltar DEPOIS da escolha: o servidor recalcula na hora de gravar e, se o
    // valor aprovado ficou pequeno, devolve as opções recalculadas — então pergunta outra
    // vez (no máximo 3), em vez de devolver um 409 que a tela mostraria como erro genérico.
    for (let perguntas = 0; resp.status === 409 && perguntas < 3; perguntas++) {
        const dados = await resp.json().catch(() => ({}));
        const escolha = await pedirFonte(dados.fonte, { retorno });
        if (!escolha) return null; // cancelou: nada foi pago

        // A escolha anterior não pode sobrar (de resgate para cheque, o id do investimento).
        ['funding_source', 'funding_investment_id', 'funding_max_amount'].forEach((k) => formData.delete(k));
        Object.entries(escolha).forEach(([k, v]) => formData.set(k, v));
        resp = await enviar();
    }

    return resp;
}

/** Sucesso: 2xx OU o 302 que o `redirect: 'manual'` deixa opaco. */
export function respostaOk(resp) {
    return !!resp && (resp.ok || resp.type === 'opaqueredirect');
}

/** Liga os botões do modal (chamado uma vez por página, via initContent). */
export function initFunding() {
    // Fallback sem JS renderizado pelo Blade (session('fonteNecessaria')): com JS
    // presente ele vira diálogo de verdade — nasce aberto pelo servidor, então é
    // ADOTADO aqui (foco dentro, Tab preso, Esc) — e fecha no véu como qualquer outro
    // modal. O "Cancelar" continua sendo um link, que funciona sem JS nenhum.
    const semJs = document.getElementById('fundingModalSemJs');
    if (semJs && !semJs.dataset.fallbackBound) {
        semJs.dataset.fallbackBound = '1';
        semJs.addEventListener('click', (e) => { if (e.target === semJs) fecharDialogo(semJs); });
        if (semJs.classList.contains('open')) abrirDialogo(semJs);
    }

    const el = document.getElementById('fundingModal');
    if (!el || el.dataset.fundingBound) return;
    el.dataset.fundingBound = '1';
    scrim = el;

    el.querySelectorAll('[data-funding-close]').forEach((b) => {
        b.addEventListener('click', () => fechar(null));
    });
    // Clique no fundo fecha (mesmo comportamento dos outros modais do app). O Esc é
    // do utilitário de diálogo, que chama o `aoPedirFechar` dado em `pedirFonte`.
    el.addEventListener('click', (e) => {
        if (e.target === el) fechar(null);
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
                // Cinto e suspensório: um investimento que não cobre sozinho
                // seria recusado pelo servidor, e o modal fecharia à toa.
                if (select.options[select.selectedIndex]?.disabled) return;
                escolha.funding_investment_id = select.value;
            }

            // TETO: o faltante que esta tela mostrou ("Vamos resgatar R$ 100,00", ou
            // "Sua conta fica em R$ X" no cheque especial). O servidor recalcula na hora
            // de gravar — se o disponível tiver caído desde agora (típico de lançamento
            // que dormiu na fila offline), ele devolve 409 e pergunta de novo em vez de
            // resgatar do investimento, ou usar de cheque especial, mais do que o aprovado.
            if (faltanteAprovado > 0) escolha.funding_max_amount = faltanteAprovado.toFixed(2);

            fechar(escolha);
        });
    }
}
