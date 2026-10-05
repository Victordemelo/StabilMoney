// ============================================================
// Tutorial guiado (out/2026 — pedido do Victor): passa tela por tela, botão por botão,
// destacando cada coisa e explicando para que serve.
//
// - Os passos (`PASSOS`) dizem a TELA (caminho), os SELETORES do que destacar (o primeiro
//   que existir vence) e o texto. Elemento que não existe na tela (ex.: o menu lateral no
//   celular, a Família para quem é dependente) vira um passo CENTRALIZADO, sem destaque —
//   a explicação nunca some.
// - O tour atravessa as telas com navegação normal: o passo atual fica no sessionStorage, e
//   a tela seguinte retoma de onde parou (`retomarTutorial`).
// - Esc ou "Sair" encerram. O balão é um diálogo (role="dialog"), recebe o foco, e o texto
//   entra sempre por `textContent`.
// ============================================================

const CHAVE = 'sm-tutorial-passo';

export const PASSOS = [
    { tela: '/', titulo: 'Bem-vindo ao Stabil Money', texto: 'Vamos passar pelas telas principais e mostrar para que serve cada botão. Use "Próximo" para avançar ou Esc para sair a qualquer momento.' },
    { tela: '/', alvo: ['.dashboard-balance-hero'], titulo: 'Seu saldo', texto: 'Quanto você tem disponível para gastar agora: o saldo das contas, já sem o que está guardado em metas e investimentos.' },
    { tela: '/', alvo: ['.section-head .seg', '.seg'], titulo: 'Período', texto: 'Troque entre semana, mês e ano para ver receitas, despesas e economia de cada período.' },
    { tela: '/', alvo: ['.topbar [data-launch-open]', '#mLancar', '.fab'], titulo: 'Lançar', texto: 'O botão "+" registra uma receita, uma despesa ou uma transferência — em qualquer tela do app.' },
    { tela: '/', alvo: ['#notifBtn', '#mNotif'], titulo: 'Avisos de vencimento', texto: 'O sino lista o que vence nos próximos dias e o que já venceu: faturas de cartão e contas fixas.' },
    { tela: '/', alvo: ['#themeBtn', '#mTheme'], titulo: 'Tema claro ou escuro', texto: 'Alterna entre o tema claro e o escuro. A escolha fica salva neste aparelho.' },
    { tela: '/', alvo: ['.tb-relogio'], titulo: 'Relógio', texto: 'A hora no fuso que você escolher em Configurações › Conta.' },
    { tela: '/', alvo: ['#sidebar .nav'], titulo: 'Menu', texto: 'Cada item diz em uma linha para que serve. O número vermelho em "Contas a pagar" conta o que venceu ou vence hoje.' },
    { tela: '/', alvo: ['#sidePatrimonio'], titulo: 'Patrimônio total', texto: 'Tudo o que é seu somado: o disponível, o guardado em metas, o investido e o cheque especial livre.' },
    { tela: '/', alvo: ['#profileBtn'], titulo: 'Seu perfil', texto: 'Aqui ficam Meu perfil, Configurações, este Tutorial, as Informações do sistema e o botão de sair.' },

    { tela: '/transactions', alvo: ['.filter-bar'], titulo: 'Movimentações: filtros', texto: 'Filtre por tipo, conta, categoria e período. Os filtros ficam no endereço da página, então dá para voltar a eles depois.' },
    { tela: '/transactions', alvo: ['.tx-list', '.empty-state'], titulo: 'Lista de movimentações', texto: 'Tudo o que entrou e saiu, do mais novo ao mais antigo. Clique numa linha para ver os detalhes. O que já foi pago ou recebido não muda de valor: para corrigir, exclua e lance de novo.' },

    { tela: '/faturas', alvo: ['.fatura-card'], titulo: 'Contas a pagar', texto: 'No topo, as contas fixas do mês (aluguel, condomínio…) com o botão Pagar. O lápis ajusta o valor previsto; o "x" exclui, pedindo a sua senha.' },
    { tela: '/faturas', alvo: ['#lancarBtn'], titulo: 'Lançar despesa', texto: 'Registra uma compra no cartão — à vista, parcelada ou recorrente — ou uma despesa por débito, Pix ou TED.' },

    { tela: '/metas', alvo: ['#metaNovaBtn'], titulo: 'Metas', texto: 'Crie um objetivo (viagem, reserva de emergência) e guarde dinheiro nele com "Aportar". O valor guardado sai do disponível, para não ser gasto sem querer.' },
    { tela: '/investimentos', alvo: ['#invNovoBtn'], titulo: 'Investimentos', texto: 'Registre aplicações (CDI, Selic, IPCA+, prefixado) e acompanhe a projeção do rendimento, já com a estimativa de IR e IOF.' },
    { tela: '/accounts', alvo: ['.acct-grupo', '.section-head .btn-primary'], titulo: 'Contas e cartões', texto: 'Suas contas de banco, cartões de crédito e débito, Pix e TED. Receita entra em conta; despesa sai por um cartão, Pix ou TED.' },
    { tela: '/categories', alvo: ['.cat-cols'], titulo: 'Categorias', texto: 'Receitas de um lado, despesas do outro. Arraste para reordenar ou para trocar o tipo; clique para editar.' },
    { tela: '/dependentes', alvo: ['.dep-grid'], titulo: 'Família', texto: 'Quem usa a conta com você: cada pessoa tem login próprio e vê o dinheiro da família. Aqui você também vê quanto cada um gastou no mês.' },
    { tela: '/configuracoes', alvo: ['.settings-tabs'], titulo: 'Configurações', texto: 'Senha, verificação em duas etapas, conta, histórico de atividade e a instalação no celular.' },
    { tela: '/configuracoes', titulo: 'Pronto!', texto: 'Esse foi o tour. Você pode refazê-lo quando quiser, no menu do seu perfil › Tutorial.' },
];

const guardar = (passo) => { try { sessionStorage.setItem(CHAVE, String(passo)); } catch (e) { /* sem storage: o tour só não atravessa telas */ } };
const ler = () => { try { const v = sessionStorage.getItem(CHAVE); return v === null ? null : Number(v); } catch (e) { return null; } };
const limpar = () => { try { sessionStorage.removeItem(CHAVE); } catch (e) { /* nada */ } };

const caminhoAtual = () => window.location.pathname.replace(/\/+$/, '') || '/';

function visivel(el) {
    const r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0 && getComputedStyle(el).visibility !== 'hidden';
}

function acharAlvo(passo) {
    for (const seletor of passo.alvo || []) {
        const el = document.querySelector(seletor);
        if (el && visivel(el)) return el;
    }
    return null;
}

let camada = null;

function encerrar() {
    limpar();
    if (camada) {
        camada.remove();
        camada = null;
    }
    document.removeEventListener('keydown', aoTeclar, true);
    window.removeEventListener('resize', reposicionar);
}

function aoTeclar(e) {
    if (e.key === 'Escape') {
        e.preventDefault();
        e.stopPropagation();
        encerrar();
    }
}

let atual = null;

function reposicionar() {
    if (!camada || atual === null) return;
    const foco = camada.querySelector('.tour-foco');
    const balao = camada.querySelector('.tour-balao');
    const alvo = acharAlvo(PASSOS[atual]);

    if (!alvo) {
        foco.hidden = true;
        camada.classList.add('sem-alvo');
        balao.style.top = '';
        balao.style.left = '';
        return;
    }

    camada.classList.remove('sem-alvo');
    foco.hidden = false;
    const r = alvo.getBoundingClientRect();
    const folga = 6;
    Object.assign(foco.style, {
        top: `${r.top - folga}px`, left: `${r.left - folga}px`,
        width: `${r.width + folga * 2}px`, height: `${r.height + folga * 2}px`,
    });

    // O balão vai embaixo do alvo; sem espaço, em cima; e nunca sai da tela.
    const bw = balao.offsetWidth;
    const bh = balao.offsetHeight;
    const vw = window.innerWidth;
    const vh = window.innerHeight;
    let top = r.bottom + 14;
    if (top + bh > vh - 12) top = r.top - bh - 14;
    if (top < 12) top = Math.max(12, Math.min(vh - bh - 12, r.top + 12));
    const left = Math.max(12, Math.min(vw - bw - 12, r.left + r.width / 2 - bw / 2));
    balao.style.top = `${top}px`;
    balao.style.left = `${left}px`;
}

function irPara(indice) {
    if (indice < 0) return;
    if (indice >= PASSOS.length) {
        encerrar();
        return;
    }
    const passo = PASSOS[indice];
    guardar(indice);
    if (passo.tela !== caminhoAtual()) {
        window.location.href = passo.tela;
        return;
    }
    mostrar(indice);
}

function montarCamada() {
    const c = document.createElement('div');
    c.className = 'tour';
    // Molde FIXO, sem dado nenhum do usuário — título e texto entram depois por textContent.
    c.innerHTML = `
        <div class="tour-foco" aria-hidden="true"></div>
        <div class="tour-balao" role="dialog" aria-modal="false" aria-labelledby="tour-titulo" aria-describedby="tour-texto" tabindex="-1">
            <span class="tour-passo"></span>
            <h3 id="tour-titulo"></h3>
            <p id="tour-texto"></p>
            <div class="tour-acoes">
                <button type="button" class="btn ghost tour-sair">Sair</button>
                <span class="tour-espaco"></span>
                <button type="button" class="btn ghost tour-voltar">Anterior</button>
                <button type="button" class="btn primary tour-proximo">Próximo</button>
            </div>
        </div>`;
    c.querySelector('.tour-sair').addEventListener('click', encerrar);
    c.querySelector('.tour-voltar').addEventListener('click', () => irPara(atual - 1));
    c.querySelector('.tour-proximo').addEventListener('click', () => irPara(atual + 1));
    document.body.appendChild(c);
    document.addEventListener('keydown', aoTeclar, true);
    window.addEventListener('resize', reposicionar);
    return c;
}

function mostrar(indice) {
    atual = indice;
    const passo = PASSOS[indice];
    if (!camada) camada = montarCamada();

    camada.querySelector('.tour-passo').textContent = `Passo ${indice + 1} de ${PASSOS.length}`;
    camada.querySelector('#tour-titulo').textContent = passo.titulo;
    camada.querySelector('#tour-texto').textContent = passo.texto;
    camada.querySelector('.tour-voltar').hidden = indice === 0;
    camada.querySelector('.tour-proximo').textContent = indice === PASSOS.length - 1 ? 'Concluir' : 'Próximo';

    const alvo = acharAlvo(passo);
    if (alvo) alvo.scrollIntoView({ block: 'center', behavior: 'auto' });
    reposicionar();
    camada.querySelector('.tour-proximo').focus();
}

/** Começa do primeiro passo (botão "Começar o tour" da página Tutorial). */
export function iniciarTutorial() {
    irPara(0);
}

/** Na carga de cada tela: se um tour está em andamento e o passo é desta tela, continua. */
export function retomarTutorial() {
    const passo = ler();
    if (passo === null || Number.isNaN(passo) || !PASSOS[passo]) return;
    if (PASSOS[passo].tela !== caminhoAtual()) {
        // Saiu do tour por outro caminho (clicou no menu, por exemplo): encerra em silêncio.
        limpar();
        return;
    }
    mostrar(passo);
}

/**
 * Troca de tela por pjax (o menu, no meio do tour): se o passo atual não é desta tela, o tour
 * acaba — senão o balão ficaria apontando para uma tela que já saiu.
 */
export function tutorialAposTrocarDeTela() {
    if (!camada || atual === null) return;
    if (PASSOS[atual].tela !== caminhoAtual()) encerrar();
    else reposicionar();
}

export function initTutorial() {
    document.addEventListener('click', (e) => {
        const gatilho = e.target.closest('[data-tutorial-iniciar]');
        if (!gatilho) return;
        e.preventDefault();
        iniciarTutorial();
    });
    retomarTutorial();
}
