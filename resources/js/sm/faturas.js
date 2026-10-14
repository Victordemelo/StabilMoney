// Página Contas a pagar (design v2): faturas por cartão, parcelas, despesas em conta e
// contas fixas.
//
// O app é server-routed — pagar, estornar e remover são <form> Laravel reais. Este módulo
// cuida só da UI:
//   • abrir/fechar os modais da tela (pagar fatura, pagar conta fixa, nova conta fixa,
//     editar conta fixa, remover despesa) como DIÁLOGOS (sm/dialogo.js: foco dentro, Tab
//     preso, Esc fecha só o do topo, resto da página inerte e o foco de volta a quem abriu),
//     com clique no véu;
//   • o resumo "saldo atual − valor = depois" de cada pagamento.
//
// O "Lançar despesa" desta tela é o modal Lançar do topo, já em Despesa (14/10/2026 — antes
// era um modal próprio, com outro formato). O "Tem certeza?" das exclusões NÃO mora aqui: é o
// `data-confirmar` de cada formulário (sm/confirmar.js), que vale também sem este módulo.
//
// Só roda na tela de Contas a pagar (guard pela marca `data-contas-a-pagar` da view).

import { abrirDialogo, fecharDialogo } from './dialogo';
import { enviarComFonte, respostaOk } from './funding';
import { desenharResumo, lerValorDoCampo, saldoDaOpcao } from './resumo-saldo';
import { atualizarIcone } from './select-icone';

const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));

// ---- Erro dentro de um modal de pagamento (a view não tem caixa de erro) ----
// textContent sempre: nunca interpolar mensagem do servidor em innerHTML.
function erroNoModal(modal, msg) {
    const body = modal.querySelector('.modal-body');
    if (!body) return;
    let box = modal.querySelector('[data-erro-pagamento]');
    if (!box) {
        box = document.createElement('div');
        box.className = 'flash-error';
        box.setAttribute('role', 'alert');
        box.dataset.erroPagamento = '1';
        body.prepend(box);
    }
    box.textContent = msg;
    box.hidden = false;
}

function limparErro(modal) {
    const box = modal.querySelector('[data-erro-pagamento]');
    if (box) box.hidden = true;
}

/**
 * Liga um modal da tela ao utilitário de diálogo: fecha no clique no véu e nos botões
 * de fechar (o X e o "Cancelar"). O Esc é do utilitário, que fecha só o diálogo do
 * TOPO — com o "De onde sai esse dinheiro?" aberto por cima do "Pagar fatura", o
 * primeiro Esc fecha só ele. Os ouvintes de Esc que cada modal prendia no documento
 * somavam um a cada visita pelo pjax e fechavam tudo de uma vez.
 *
 * Devolve `fechar`, para quem precisa fechar por código (o pagamento que deu certo).
 */
function ligarFechamento(modal, seletorDosBotoes) {
    const fechar = () => fecharDialogo(modal);
    modal.addEventListener('click', (e) => { if (e.target === modal) fechar(); });
    $$(seletorDosBotoes, modal).forEach((btn) => btn.addEventListener('click', fechar));
    return fechar;
}

/**
 * Faz um formulário de pagamento (fatura ou conta fixa) enviar por AJAX,
 * passando pelo modal "de onde sai esse dinheiro?" quando o servidor responde
 * 409 — o mesmo caminho do modal global "Lançar".
 *
 * Antes eram POST comum: quem tinha cheque especial clicava em "Confirmar
 * pagamento", o servidor redirecionava com `fonteNecessaria` na sessão e a
 * tela não mostrava NADA (achado A-2 da auditoria de 27/07/2026).
 */
function ligarPagamentoAjax(form, modal, fechar) {
    if (!form || form.dataset.fundingBound) return;
    form.dataset.fundingBound = '1';

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        limparErro(modal);

        const botao = form.querySelector('button[type="submit"]');
        const rotulo = botao ? botao.textContent : '';
        const carregando = (on) => {
            if (!botao) return;
            botao.disabled = on;
            botao.textContent = on ? 'Pagando…' : rotulo;
        };

        carregando(true);

        const dados = new FormData(form);
        let resp;
        try {
            // `retorno`: o modal de fonte abre POR CIMA deste, e ao fechar devolve o
            // foco ao "Confirmar pagamento" (ou, com ele ainda desabilitado, a este
            // diálogo — o utilitário resolve).
            resp = await enviarComFonte(form.action, dados, { retorno: botao });
        } catch (_) {
            carregando(false);
            erroNoModal(modal, 'Sem conexão. Verifique sua internet e tente de novo.');
            return;
        }

        // null = o usuário fechou o modal de escolha; nada foi pago.
        if (resp === null) { carregando(false); return; }

        if (respostaOk(resp)) {
            fechar();
            // Recarrega para refletir saldo/fatura (e mostrar o flash do servidor).
            if (typeof window.smPjaxReload === 'function') window.smPjaxReload();
            else window.location.reload();
            return;
        }

        carregando(false);
        let msg = 'Não foi possível concluir o pagamento. Confira os campos e tente de novo.';
        if (resp.status === 422) {
            const data = await resp.json().catch(() => ({}));
            msg = Object.values(data?.errors || {})[0]?.[0] || data?.message || msg;
        } else if (resp.status === 419) {
            msg = 'Sua sessão expirou. Recarregue a página e tente de novo.';
        }
        erroNoModal(modal, msg);
    });
}

export function initFaturas() {
    // Só a tela de Contas a pagar tem a marca.
    if (!document.querySelector('[data-contas-a-pagar]')) return;

    // ---- Remover despesa: o "x" de cada linha abre um modal que pede a SENHA ----
    // O <form> da linha continua existindo (sem JS ele envia sem senha e o servidor recusa
    // com a mensagem); aqui o envio dele vira a abertura do modal, que manda para a MESMA
    // ação com a senha. A ação vem do atributo do formulário renderizado pelo servidor.
    const remover = document.getElementById('removerDespesaModal');
    if (remover) {
        const rdForm = remover.querySelector('[data-rd-form]');
        const rdPergunta = remover.querySelector('[data-rd-pergunta]');
        const rdAlvo = remover.querySelector('[data-rd-alvo]');
        const rdPerguntaCampo = remover.querySelector('[data-rd-pergunta-campo]');
        const senha = remover.querySelector('#rd-senha');
        const rdTitulo = remover.querySelector('[data-rd-titulo]');
        const rdTipo = remover.querySelector('[data-rd-tipo]');
        const rdBotao = remover.querySelector('[data-rd-botao]');
        ligarFechamento(remover, '[data-remover-close]');

        // O mesmo modal serve à despesa e à conta fixa (out/2026): `tipo` troca o título e
        // o botão, e vai num hidden para o servidor reabrir o modal certo depois de um erro.
        const abrirRemocao = (acao, pergunta, gatilho, { tipo = 'despesa', titulo = '' } = {}) => {
            rdForm.setAttribute('action', acao);
            rdAlvo.value = (acao.match(/\/(\d+)$/) || [])[1] || '';
            rdPergunta.textContent = pergunta || 'Remover esta despesa?';
            rdPerguntaCampo.value = pergunta || '';
            if (rdTipo) rdTipo.value = tipo;
            if (rdTitulo) rdTitulo.textContent = titulo || 'Remover despesa';
            if (rdBotao) rdBotao.textContent = tipo === 'fixa' ? 'Excluir' : 'Remover';
            senha.value = '';
            abrirDialogo(remover, { foco: '#rd-senha', retorno: gatilho });
        };

        $$('form[data-remover-despesa]').forEach((form) => form.addEventListener('submit', (e) => {
            e.preventDefault();
            abrirRemocao(form.getAttribute('action'), form.dataset.pergunta, form.querySelector('button'), {
                tipo: form.dataset.removerTipo || 'despesa',
                titulo: form.dataset.titulo || '',
            });
        }));

        // Senha errada: o servidor volta com o erro e a ação remontada pelo id.
        if (remover.dataset.reabrirAcao) abrirDialogo(remover, { foco: '#rd-senha' });
    }

    // ---- Modal "Pagar fatura" (marcar fatura do cartão como paga) ----
    const payModal = document.getElementById('payInvoiceModal');
    if (payModal) {
        const payForm = payModal.querySelector('[data-pay-form]');
        const payName = payModal.querySelector('[data-pay-name]');
        const payAmount = payModal.querySelector('[data-pay-amount]');
        // Qual fatura: "aberto" (a do mês) ou "fechado" (a que já fechou/venceu).
        const payCiclo = payModal.querySelector('[data-pay-ciclo]');
        const payData = payModal.querySelector('#pay-data');
        const payConta = payModal.querySelector('#pay-account');
        const payResumo = payModal.querySelector('[data-resumo-saldo]');
        let valorDaFatura = 0;
        const syncResumoPay = () => desenharResumo(payResumo, {
            saldo: saldoDaOpcao(payConta)?.saldo ?? null,
            valor: valorDaFatura,
            rotuloValor: 'Esta fatura',
            obrigacao: true, // fatura já é dívida: passa mesmo deixando a conta negativa
        });
        if (payConta) payConta.addEventListener('change', syncResumoPay);
        const fecharPay = ligarFechamento(payModal, '[data-pay-close]');

        ligarPagamentoAjax(payForm, payModal, fecharPay);

        $$('[data-pay-open]').forEach((btn) => btn.addEventListener('click', () => {
            limparErro(payModal);
            if (payForm) payForm.setAttribute('action', btn.dataset.action || '');
            if (payName) payName.textContent = btn.dataset.name || '';
            if (payAmount) payAmount.textContent = btn.dataset.amount || '';
            // O mesmo modal serve às duas faturas do cartão: sem isto, clicar em
            // "Pagar fatura vencida" pagaria a do ciclo aberto.
            if (payCiclo) payCiclo.value = btn.dataset.ciclo || 'aberto';
            if (payData) {
                // Piso da data = compra mais antiga em aberto (o mesmo do
                // PayInvoiceRequest), para o campo não oferecer o que o
                // servidor recusa. E a data volta para hoje a cada abertura.
                if (btn.dataset.min) payData.min = btn.dataset.min;
                else payData.removeAttribute('min');
                payData.value = payData.max || payData.value;
            }
            valorDaFatura = Number(btn.dataset.amountValor) || 0;
            syncResumoPay();
            // O foco entra em "Debitar de", o primeiro campo.
            abrirDialogo(payModal, { retorno: btn });
        }));
    }

    // ---- Modal "Pagar conta fixa" (uma competência de uma conta mensal) ----
    const fixaModal = document.getElementById('fixaPagarModal');
    if (fixaModal) {
        const fixaForm = fixaModal.querySelector('[data-fixa-form]');
        const fixaNome = fixaModal.querySelector('[data-fixa-nome]');
        const fixaValor = fixaModal.querySelector('#fixa-valor');
        const fixaConta = fixaModal.querySelector('#fixa-conta');
        const fixaResumo = fixaModal.querySelector('[data-resumo-saldo]');
        let fixaObrigacao = false;
        const syncResumoFixa = () => {
            atualizarIcone(fixaConta); // a conta padrão da conta fixa é marcada por código
            const opt = fixaConta?.selectedOptions?.[0];
            desenharResumo(fixaResumo, {
                saldo: saldoDaOpcao(fixaConta)?.saldo ?? null,
                valor: lerValorDoCampo(fixaValor),
                rotuloValor: 'Esta conta',
                obrigacao: fixaObrigacao,
                cartao: opt?.dataset.card === '1',
            });
        };
        if (fixaConta) fixaConta.addEventListener('change', syncResumoFixa);
        if (fixaValor) fixaValor.addEventListener('input', syncResumoFixa);
        const fecharFixa = ligarFechamento(fixaModal, '[data-fixa-close]');

        ligarPagamentoAjax(fixaForm, fixaModal, fecharFixa);

        $$('[data-fixa-pagar]').forEach((btn) => btn.addEventListener('click', () => {
            limparErro(fixaModal);
            if (fixaForm) fixaForm.setAttribute('action', btn.dataset.action || '');
            if (fixaNome) fixaNome.textContent = btn.dataset.nome || '';
            // Vem com o valor PREVISTO; o usuário ajusta se a conta veio diferente.
            if (fixaValor) fixaValor.value = btn.dataset.valor || '';
            // E já seleciona o método de pagamento padrão da conta fixa, se houver.
            if (fixaConta && btn.dataset.conta) fixaConta.value = btn.dataset.conta;
            // Piso da data de pagamento: o servidor recusa data anterior ao mês
            // anterior à competência (senão a despesa sumia do fluxo de caixa).
            const fixaData = fixaModal.querySelector('#fixa-data');
            if (fixaData && btn.dataset.min) fixaData.min = btn.dataset.min;
            fixaObrigacao = btn.dataset.obrigacao === '1';
            syncResumoFixa();
            // O foco entra no "Valor pago" — é o que mais muda de um mês para outro.
            abrirDialogo(fixaModal, { foco: fixaValor, retorno: btn });
        }));
    }

    // ---- Modal "Nova conta fixa" ----
    const novaFixa = document.getElementById('fixaNovaModal');
    if (novaFixa) {
        ligarFechamento(novaFixa, '[data-fixanova-close]');
        const btnNova = document.getElementById('novaContaFixaBtn');
        if (btnNova) {
            btnNova.addEventListener('click', () => abrirDialogo(novaFixa, { foco: '#cf-nome', retorno: btnNova }));
        }
    }

    // ---- Modal "Editar conta fixa" ----
    // Um só modal, preenchido pelos data-* do lápis clicado (o mesmo padrão do "Pagar
    // conta fixa"). Morava num script inline da view, com um ouvinte de Esc próprio que
    // somava um a cada visita pelo pjax.
    const editFixa = document.getElementById('fixaEditarModal');
    if (editFixa) {
        const editForm = editFixa.querySelector('[data-fixaedit-form]');
        const campo = (sel) => editFixa.querySelector(sel);
        const preencher = (sel, valor) => {
            const el = campo(sel);
            if (!el) return;
            el.value = valor || '';
            if (el.tagName === 'SELECT') atualizarIcone(el);
        };
        ligarFechamento(editFixa, '[data-fixaedit-close]');

        $$('[data-fixa-editar]').forEach((btn) => btn.addEventListener('click', () => {
            const d = btn.dataset;
            if (editForm) editForm.setAttribute('action', d.action || '');
            preencher('#cfe-nome', d.nome);
            preencher('#cfe-valor', d.valor);
            preencher('#cfe-dia', d.dia);
            preencher('#cfe-conta', d.conta);
            preencher('#cfe-cat', d.categoria);
            preencher('#cfe-inicio', d.inicio);
            preencher('#cfe-fim', d.fim);
            abrirDialogo(editFixa, { foco: '#cfe-nome', retorno: btn });
        }));
    }

}
