// Página de Investimentos (design v2): carteira com donut de alocação,
// lista de ativos e projeção de rentabilidade.
//
// O app é server-routed — os formulários (criar/editar/excluir/aportar/resgatar)
// são <form> Laravel reais. Este módulo cuida só da camada de UI:
//   • abrir/fechar os modais (.modal-scrim → classe .open), com Esc e clique no véu;
//   • no "Aportar"/"Resgatar" (modais COMPARTILHADOS), setar o `action` do form e
//     preencher nome/posição a partir dos data-* do botão clicado;
//   • a PREVIEW de rentabilidade do "Novo investimento": recalcula bruto/líquido
//     e a projeção em 12 meses ao mudar classe/indexador/taxa/valor (porte do
//     investModal do design v2 → finance.js, com o IR pela tabela regressiva);
//   • reabrir o modal certo quando a validação do servidor volta com erro.
//
// Só roda na tela de investimentos (guard pelo modal de criação da view).

const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));

// Converte "1.234,56" / "R$ 110" / "110%" em número (espelha parseMoney do protótipo).
function parseMoney(str) {
    if (str == null) return 0;
    const limpo = String(str).replace(/[^0-9,.-]/g, '').replace(/\.(?=\d{3}(\D|$))/g, '').replace(',', '.');
    const n = parseFloat(limpo);
    return Number.isFinite(n) ? n : 0;
}

// Formata número em R$ pt-BR com centavos (espelha BRL do protótipo).
function brl(v) {
    return (Number(v) || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// Uuid novo para o `client_uuid` do formulário (idempotência no servidor): um
// duplo clique ou o reenvio de um POST que já chegou não grava de novo.
function novoUuid() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
    // Fallback (contexto sem `randomUUID`, ex.: http em rede local): v4 pela API antiga.
    const b = new Uint8Array(16);
    window.crypto.getRandomValues(b);
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    const h = Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');
    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

// Troca o uuid do form a cada ABERTURA do modal: cada abertura é uma intenção
// nova; dentro da mesma abertura, reenviar é duplicata.
function renovarUuid(form) {
    const campo = form ? form.querySelector('[data-client-uuid]') : null;
    if (campo) campo.value = novoUuid();
}

// Desabilita o botão de enviar enquanto o POST está em voo: é a primeira
// barreira contra o duplo clique (o uuid é a segunda, no servidor). Volta a
// habilitar se a página for restaurada do bfcache (botão "voltar").
function travarAoEnviar(form) {
    if (!form || form.dataset.travaEnvio) return;
    form.dataset.travaEnvio = '1';
    form.addEventListener('submit', () => {
        $$('button[type="submit"]', form).forEach((btn) => {
            btn.disabled = true;
            btn.setAttribute('aria-busy', 'true');
        });
    });
    window.addEventListener('pageshow', () => {
        $$('button[type="submit"]', form).forEach((btn) => {
            btn.disabled = false;
            btn.removeAttribute('aria-busy');
        });
    });
}

// Rentabilidade bruta projetada por indexador (espelha grossRate/IDX_BASE do finance.js).
// idxBase = { CDI, Selic, 'IPCA+', Prefixado } vem do data-idx-base do modal.
function grossRate(indexador, taxa, idxBase) {
    if (indexador === 'CDI' || indexador === 'Selic') return (idxBase[indexador] || 0) * (taxa / 100);
    if (indexador === 'IPCA+') return (idxBase['IPCA+'] || 0) + taxa;
    if (indexador === 'Prefixado') return taxa;
    // Não indexado (RV/cripto/fundos): usa a própria taxa informada como rentab. observada.
    // (O accessor Investment::grossRate no PHP faz o mesmo — os dois têm de bater.)
    return taxa;
}

// Prazo padrão da simulação (o seletor da tela troca este valor).
const PRAZO_PROJECAO_DIAS = 365;

// IOF e IR vêm do PHP (App\Support\TributosRendaFixa) pelo data-tributos do modal.
// Fonte ÚNICA de propósito: esta tela já teve card e prévia discordando por terem
// cada um a sua regra. O fallback abaixo só existe para o caso de o atributo faltar.
const TRIBUTOS_FALLBACK = {
    iofPorDia: {},
    iofDiasIsencao: 30,
    irFaixas: [
        { ate: 180, aliquota: 22.5 },
        { ate: 360, aliquota: 20 },
        { ate: 720, aliquota: 17.5 },
        { ate: null, aliquota: 15 },
    ],
    classesGanhoDeCapital: ['renda_variavel', 'cripto'],
};

const ganhoDeCapital = (t, classe) => (t.classesGanhoDeCapital || []).includes(classe);

// % do RENDIMENTO retido como IOF. Só existe nos 29 primeiros dias — no 30º zera.
// Ações e cripto são ganho de capital: não têm IOF de renda fixa.
function iofAliquota(t, classe, dias) {
    if (ganhoDeCapital(t, classe)) return 0;
    return Number((t.iofPorDia || {})[dias] || 0);
}

// % do RENDIMENTO retido como IR (tabela regressiva, Lei 11.033/2004).
function irAliquota(t, classe, dias) {
    if (ganhoDeCapital(t, classe)) return 15;
    const faixa = (t.irFaixas || []).find((f) => f.ate === null || dias <= f.ate);
    return faixa ? Number(faixa.aliquota) : 15;
}

// Decompõe o rendimento em IOF, IR e líquido.
// ORDEM: o IOF sai primeiro e o IR incide sobre o que SOBROU — aplicar os dois sobre o
// rendimento cheio cobraria imposto a mais. Espelha TributosRendaFixa::decompor().
function decomporRendimento(t, rendimento, classe, dias) {
    const aliqIof = iofAliquota(t, classe, dias);
    const aliqIr = irAliquota(t, classe, dias);

    if (!(rendimento > 0)) {
        return { iof: 0, ir: 0, aliqIof, aliqIr, liquido: rendimento };
    }

    const iof = rendimento * aliqIof / 100;
    const ir = (rendimento - iof) * aliqIr / 100;

    return { iof, ir, aliqIof, aliqIr, liquido: rendimento - iof - ir };
}

// Número em pt-BR com 1..2 casas, sem zeros à toa ("17,5" e não "17,50").
function pct(v) {
    return (Number(v) || 0).toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 2 });
}

export function initInvestimentos() {
    // A view de investimentos tem sempre o modal de criação; sem ele, não é esta tela.
    const createModal = document.getElementById('invCreateModal');
    if (!createModal) return;

    const todosModais = $$('.modal-scrim[data-inv-modal]');

    const abrir = (modal) => {
        if (!modal) return;
        modal.classList.add('open');
        // Foca o primeiro campo editável (ignora os pickers de radio).
        const campo = modal.querySelector('input[type="text"], input[type="date"], select');
        if (campo) setTimeout(() => campo.focus(), 120);
    };

    const fecharTodos = () => todosModais.forEach((m) => m.classList.remove('open'));

    // Fechar: clique no véu, no X ou nos botões "Cancelar" ([data-inv-close]).
    todosModais.forEach((modal) => {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) modal.classList.remove('open');
        });
        $$('[data-inv-close]', modal).forEach((btn) =>
            btn.addEventListener('click', () => modal.classList.remove('open'))
        );
    });

    // Esc fecha qualquer modal aberto.
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') fecharTodos();
    });

    // ---- Abrir "Novo investimento" (botão do topo, estado vazio e "Novo aporte" inline) ----
    // O "Novo investimento" grava o aporte inicial: uuid novo a cada abertura.
    ['invNovoBtn', 'invNovoBtnVazio', 'invAddInline'].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('click', () => {
            renovarUuid(createModal.querySelector('form'));
            abrir(createModal);
        });
    });

    // ---- Editar / Excluir (modais por ativo, abertos pelo id no data-id) ----
    $$('[data-inv-edit]').forEach((btn) => {
        btn.addEventListener('click', () => abrir(document.getElementById(`invEditModal-${btn.dataset.id}`)));
    });
    $$('[data-inv-del]').forEach((btn) => {
        btn.addEventListener('click', () => abrir(document.getElementById(`invDeleteModal-${btn.dataset.id}`)));
    });

    // ---- Aportar (modal compartilhado) ----
    const aporteModal = document.getElementById('invAporteModal');
    if (aporteModal) {
        const form = aporteModal.querySelector('[data-aporte-form]');
        const nomeEl = aporteModal.querySelector('[data-aporte-name]');
        const aplicEl = aporteModal.querySelector('[data-aporte-aplicado]');
        const actionField = aporteModal.querySelector('[data-action-field]');
        // base da URL: /investimentos/__ID__/aportes — trocamos o __ID__ pelo id clicado.
        $$('[data-inv-aporte]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const url = aporteModal.dataset.actionBase.replace('__ID__', btn.dataset.id);
                if (form) form.action = url;
                if (actionField) actionField.value = url; // p/ reabrir após erro
                if (nomeEl) nomeEl.textContent = btn.dataset.name || '';
                if (aplicEl) aplicEl.textContent = btn.dataset.aplicado || '—';
                const amount = aporteModal.querySelector('[name="amount"]');
                if (amount) amount.value = '';
                renovarUuid(form);
                abrir(aporteModal);
            });
        });
    }

    // ---- Resgatar (modal compartilhado) ----
    const resgateModal = document.getElementById('invResgateModal');
    if (resgateModal) {
        const form = resgateModal.querySelector('[data-resgate-form]');
        const nomeEl = resgateModal.querySelector('[data-resgate-name]');
        const aplicEl = resgateModal.querySelector('[data-resgate-aplicado]');
        const actionField = resgateModal.querySelector('[data-action-field]');
        $$('[data-inv-resgatar]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const url = resgateModal.dataset.actionBase.replace('__ID__', btn.dataset.id);
                if (form) form.action = url;
                if (actionField) actionField.value = url;
                if (nomeEl) nomeEl.textContent = btn.dataset.name || '';
                if (aplicEl) aplicEl.textContent = btn.dataset.aplicado || '—';
                const amount = resgateModal.querySelector('[name="amount"]');
                if (amount) amount.value = '';
                renovarUuid(form);
                abrir(resgateModal);
            });
        });
    }

    // ---- Preview de rentabilidade do "Novo investimento" ----
    // ESTIMATIVA: recalcula bruto/IR/líquido e a projeção em 12 meses ao mudar
    // classe/indexador/taxa/valor. Porte do upd() do investModal (finance.js),
    // com o IR pela tabela regressiva (17,5% em 12 meses) no lugar dos 15% fixos.
    {
        let idxBase = {};
        try { idxBase = JSON.parse(createModal.dataset.idxBase || '{}'); } catch (_) { idxBase = {}; }

        // Tabelas de IOF/IR vindas do PHP — fonte única (ver TributosRendaFixa).
        let tributos = TRIBUTOS_FALLBACK;
        try {
            const bruto = JSON.parse(createModal.dataset.tributos || 'null');
            if (bruto && bruto.irFaixas) tributos = bruto;
        } catch (_) { tributos = TRIBUTOS_FALLBACK; }

        const prazoSel = createModal.querySelector('[data-inv-prazo]');
        const classeSel = createModal.querySelector('[data-inv-classe]');
        const idxSel = createModal.querySelector('[data-inv-idx]');
        const taxaInput = createModal.querySelector('[data-inv-taxa]');
        const taxaLabel = createModal.querySelector('[data-inv-taxa-label]');
        const valorInput = createModal.querySelector('[data-inv-valor]');
        const preview = createModal.querySelector('[data-inv-preview]');

        const TAXA_LBLS = {
            CDI: '% do CDI',
            Selic: '% da Selic',
            'IPCA+': 'IPCA + (% a.a.)',
            Prefixado: 'Taxa fixa (% a.a.)',
            '': 'Rentab. (% a.a.)',
        };

        // Premissas visíveis: as bases são constantes do app, sem data de referência.
        const premissas = () => {
            const partes = Object.entries(idxBase)
                .filter(([nome, base]) => nome !== 'Prefixado' && Number(base) > 0)
                .map(([nome, base]) => `${nome} ${pct(base)}%`);

            return 'Estimativa, não é promessa de retorno. Premissas: '
                + (partes.length ? partes.join(' · ') + ' a.a. (constantes do app, sem data de referência); ' : '')
                + 'IR e IOF pelas tabelas regressivas sobre o rendimento (o IOF só incide '
                + 'nos 29 primeiros dias). Não considera come-cotas, taxas de custódia/administração '
                + 'nem variação de mercado.';
        };

        const atualizar = () => {
            if (!preview) return;
            const classe = classeSel ? classeSel.value : '';
            const indexador = idxSel ? idxSel.value : '';
            const taxa = parseMoney(taxaInput ? taxaInput.value : '');
            const valor = parseMoney(valorInput ? valorInput.value : '');

            if (taxaLabel) {
                const sufixo = (TAXA_LBLS[indexador] || '% a.a.');
                taxaLabel.innerHTML = `Taxa <span class="hint">(${sufixo})</span>`;
            }

            const dias = Number(prazoSel ? prazoSel.value : PRAZO_PROJECAO_DIAS) || PRAZO_PROJECAO_DIAS;
            const bruto = grossRate(indexador, taxa, idxBase);

            // Rendimento do PERÍODO simulado (a taxa é anual, então proporcional aos dias).
            const rendimentoPct = bruto * (dias / 365);
            const rendimento = valor * rendimentoPct / 100;
            const t = decomporRendimento(tributos, rendimento, classe, dias);

            const rotuloPrazo = dias < 30
                ? `${dias} ${dias === 1 ? 'dia' : 'dias'}`
                : (dias % 365 === 0 ? `${dias / 365} ${dias === 365 ? 'ano' : 'anos'}` : `${Math.round(dias / 30)} meses`);

            if (valor > 0) {
                // A linha do IOF só aparece quando ele existe — mostrar "IOF 0%" em 12
                // meses seria ruído. Quando aparece, vem com o aviso do porquê.
                const linhaIof = t.aliqIof > 0
                    ? `<div class="ivp-row"><span>IOF (${pct(t.aliqIof)}% do rendimento)</span><b class="neg">− R$ ${brl(t.iof)}</b></div>`
                    : '';

                preview.innerHTML =
                    `<div class="ivp-row"><span>Rentabilidade bruta estimada</span><b>${pct(bruto)}% a.a.</b></div>` +
                    `<div class="ivp-row"><span>Rendimento em ${rotuloPrazo}</span><b>R$ ${brl(rendimento)}</b></div>` +
                    linhaIof +
                    `<div class="ivp-row"><span>IR (${pct(t.aliqIr)}% do rendimento)</span><b class="neg">− R$ ${brl(t.ir)}</b></div>` +
                    `<div class="ivp-row"><span>Rendimento líquido</span><b class="pos">R$ ${brl(t.liquido)}</b></div>` +
                    `<div class="ivp-row"><span>Valor final em ${rotuloPrazo}</span><b>R$ ${brl(valor + t.liquido)}</b></div>` +
                    (t.aliqIof > 0
                        ? `<div class="ivp-hint">Resgatar antes de 30 dias tem IOF: ele começa em 96% do rendimento no 1º dia e cai até zerar no 30º.</div>`
                        : '') +
                    `<div class="ivp-hint">${premissas()}</div>`;
            } else {
                preview.innerHTML = `<div class="ivp-hint">Preencha o valor para ver a projeção estimada de rendimento.</div>`;
            }
        };

        if (prazoSel) prazoSel.addEventListener('change', atualizar);
        if (classeSel) classeSel.addEventListener('change', atualizar);
        if (idxSel) idxSel.addEventListener('change', atualizar);
        if (taxaInput) taxaInput.addEventListener('input', atualizar);
        if (valorInput) valorInput.addEventListener('input', atualizar);
        atualizar();
    }

    // ---- Um envio por vez em todo formulário de modal (criar/aportar/resgatar/editar) ----
    todosModais.forEach((modal) => $$('form', modal).forEach(travarAoEnviar));

    // ---- Reabrir o modal correto após erro de validação do servidor ----
    // Editar/criar usam data-reopen="1" diretamente. Para aportar/resgatar,
    // o backend não sabe o ativo — então reabrimos o modal compartilhado com
    // o action que veio do hidden _action (data-reopen-action), se houver.
    todosModais.forEach((modal) => {
        if (modal.dataset.reopen !== '1') return;
        const acao = modal.dataset.reopenAction; // URL exata vinda do hidden _action
        if (acao) {
            const form = modal.querySelector('[data-aporte-form], [data-resgate-form]');
            if (form) {
                form.action = acao;
                const actionField = form.querySelector('[data-action-field]');
                if (actionField) actionField.value = acao;
            }
        }
        abrir(modal);
    });
}
