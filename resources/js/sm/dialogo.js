/* ============ StabilMoney — Diálogo modal acessível (os `.modal-scrim`) ============ */
//
// Achado A-2 da auditoria de acessibilidade de 07/09/2026: nenhum modal do app era um
// diálogo. Com o modal aberto, o Tab a partir do último campo escapava para a barra de
// cookies e para a sidebar — que continuavam atrás do véu, invisíveis —; o Esc fechava e
// o foco caía no <body>; e quem usa leitor de tela nem ficava sabendo que um diálogo
// tinha aberto.
//
// Um utilitário só, para todos os modais, faz as quatro coisas do padrão "Dialog (Modal)"
// do WAI-ARIA:
//   • ABRIR: põe `.open`, guarda QUEM ABRIU, deixa o resto da página `inert` e leva o
//     foco para dentro;
//   • PRENDER o Tab: do último controle volta ao primeiro (e o contrário com Shift+Tab);
//   • Esc fecha SÓ o diálogo do topo. O "De onde sai esse dinheiro?" abre por cima do
//     Lançar, e antes o Esc derrubava os dois — com o lançamento digitado junto;
//   • FECHAR: tira `.open`, devolve a página e o foco para quem abriu.
//
// O resto — preencher campos, enviar, a animação do `.modal-scrim` — continua com cada
// tela. A marcação (`role="dialog"`, `aria-modal`, `aria-labelledby`) mora no Blade, ao
// lado do título a que ela aponta; `prepararMarcacao` só completa o que faltar.
//
// ## Por que `inert`, e por que subindo pelos ancestrais
//
// `inert` tira o resto da página do Tab, da árvore de acessibilidade e do clique de uma
// vez só — é o que o `<dialog>` nativo faz no `showModal()`. `aria-hidden` sozinho deixaria
// o Tab passar.
//
// Os modais moram em dois lugares: no shell (`body > #launchModal`) e dentro do conteúdo
// (`#content > #catModal`). Marcar um contêiner fixo (o `#app`) deixaria inerte o próprio
// modal de conteúdo. Por isso a marcação sobe do diálogo até o <body> e deixa inertes os
// IRMÃOS de cada ancestral: o diálogo e o caminho até ele ficam vivos; todo o resto, não.
//
// Só é devolvido o que ESTE módulo marcou: elemento que já era inerte por outro motivo
// continua inerte depois que o diálogo fecha.
//
// ## Pjax
//
// O modal Lançar vive no shell e sobrevive à troca do #content; um modal do conteúdo, não.
// Se uma navegação trocar o #content com ele aberto (o "voltar" do navegador, por exemplo),
// o registro dele ficaria órfão — e a topbar, a sidebar e a barra de baixo, inertes para
// sempre. `liberarDialogosOrfaos()` descarta o diálogo que saiu do documento (ou perdeu o
// `.open` por fora) e recalcula a página: roda a cada troca do pjax (`initContent`, no
// app.js), antes de toda abertura e a cada tecla; um MutationObserver cobre o intervalo.

// Tudo o que o Tab do navegador pode visitar. O filtro fino (desabilitado, escondido,
// tabindex negativo, um radio por grupo) fica em `paradasDoTab`.
const SELETOR_FOCAVEL = [
    'a[href]', 'area[href]', 'button', 'input', 'select', 'textarea', 'iframe', 'summary',
    '[tabindex]', '[contenteditable]:not([contenteditable="false"])',
].join(', ');

// Irmãos que não precisam de `inert`: não aparecem na tela nem recebem foco.
const SEM_INERT = new Set(['SCRIPT', 'STYLE', 'LINK', 'META', 'TEMPLATE', 'NOSCRIPT']);

/** Diálogos abertos, do mais antigo ao do topo: `{ scrim, retorno, aoPedirFechar }`. */
const pilha = [];

/** Elementos que ESTE módulo deixou inertes — os únicos que ele devolve. */
const marcados = new Set();

let ligado = false;
let observador = null;
let idsGerados = 0;

/** O elemento com o papel de diálogo: o painel `.modal`, ou o próprio véu se for ele. */
function painelDe(scrim) {
    const PAPEL = '[role="dialog"], [role="alertdialog"]';
    if (scrim.matches(PAPEL)) return scrim;
    return scrim.querySelector(PAPEL) || scrim.querySelector('.modal') || scrim;
}

/**
 * Completa a marcação de diálogo que faltar. Os modais do app já chegam marcados pelo
 * Blade; isto existe para um modal novo (ou de uma tela ainda não revisada) ganhar o
 * básico só de passar por aqui: o papel, o `aria-modal` e o nome vindo do título.
 */
function prepararMarcacao(scrim) {
    const painel = painelDe(scrim);
    if (!painel.hasAttribute('role')) painel.setAttribute('role', 'dialog');
    if (!painel.hasAttribute('aria-modal')) painel.setAttribute('aria-modal', 'true');

    if (!painel.hasAttribute('aria-labelledby') && !painel.hasAttribute('aria-label')) {
        const titulo = painel.querySelector('h1, h2, h3');
        if (titulo) {
            if (!titulo.id) titulo.id = `sm-dialogo-${++idsGerados}-titulo`;
            painel.setAttribute('aria-labelledby', titulo.id);
        }
    }
}

/**
 * O elemento está na tela? `checkVisibility` responde pelo navegador (display, visibility,
 * `hidden`); sem ele — jsdom, navegador antigo — confere o atributo e o CSS computado.
 *
 * `comVisibilidade: false` ignora a propriedade `visibility` e olha só o que tira o
 * elemento do layout. É o que a ABERTURA precisa: os botões `.btn` e o X do cabeçalho têm
 * `transition: all`, então o `visibility` que eles herdam do véu ANIMA de hidden para
 * visible — e no instante em que o modal abre o valor computado ainda é hidden. Com a
 * checagem completa ali, o X e o "Cancelar" eram descartados e o foco caía no único botão
 * sem `transition: all`: o "Excluir meta", o destrutivo (medido no Chromium).
 */
function visivel(el, { comVisibilidade = true } = {}) {
    if (el.closest('[hidden], [inert]')) return false;

    if (typeof el.checkVisibility === 'function') {
        return el.checkVisibility(comVisibilidade ? { checkVisibilityCSS: true, visibilityProperty: true } : {});
    }

    // `visibility` é herdada, então basta a do próprio elemento; `display: none` não é,
    // e esconde tudo o que estiver dentro — daí subir pelos ancestrais.
    if (comVisibilidade && window.getComputedStyle(el).visibility === 'hidden') return false;
    for (let no = el; no instanceof Element; no = no.parentElement) {
        if (window.getComputedStyle(no).display === 'none') return false;
    }
    return true;
}

/**
 * Os controles que o Tab visita dentro de `raiz`, na ordem do documento. Na abertura,
 * `comVisibilidade: false` (ver `visivel`); no Tab, a checagem completa, como a do
 * navegador.
 */
function paradasDoTab(raiz, { comVisibilidade = true } = {}) {
    const candidatos = Array.from(raiz.querySelectorAll(SELETOR_FOCAVEL)).filter((el) => {
        if (el.disabled || el.tabIndex < 0) return false;
        if (el.tagName === 'INPUT' && el.type === 'hidden') return false;
        return visivel(el, { comVisibilidade });
    });

    // Radio: o Tab para em UM por grupo — o marcado, ou o primeiro se nenhum estiver. Sem
    // isto o "último da lista" podia ser um radio que o navegador nunca visita, e a volta
    // do fim para o começo não aconteceria.
    return candidatos.filter((el) => {
        if (el.type !== 'radio' || !el.name) return true;
        const grupo = candidatos.filter((o) => o.type === 'radio' && o.name === el.name && o.form === el.form);
        return el === (grupo.find((o) => o.checked) || grupo[0]);
    });
}

/** `b` vem depois de `a` na ordem do documento (e não dentro dele)? */
function vemDepois(a, b) {
    const pos = a.compareDocumentPosition(b);
    return Boolean(pos & Node.DOCUMENT_POSITION_FOLLOWING) && !(pos & Node.DOCUMENT_POSITION_CONTAINED_BY);
}

/**
 * Leva o foco para dentro do diálogo: o `foco` pedido por quem abriu; senão o primeiro
 * controle do CORPO — pula o X do cabeçalho, porque começar pelo "fechar" convida a
 * fechar —; senão o do rodapé (num "Excluir?" sem corpo, cai no Cancelar, a opção que não
 * destrói nada); senão o próprio painel.
 */
function focarDentro(scrim, foco = null) {
    const paradas = paradasDoTab(scrim, { comVisibilidade: false });
    const pedido = typeof foco === 'string' ? scrim.querySelector(foco) : foco;
    const primeiroEm = (seletor) => {
        const parte = scrim.querySelector(seletor);
        return parte ? paradas.find((el) => parte.contains(el)) : null;
    };

    let alvo = pedido && scrim.contains(pedido) && !pedido.disabled && visivel(pedido, { comVisibilidade: false })
        ? pedido
        : null;
    alvo = alvo || primeiroEm('.modal-body') || primeiroEm('.modal-foot') || paradas[0] || null;

    if (!alvo) {
        alvo = painelDe(scrim);
        if (!alvo.hasAttribute('tabindex')) alvo.setAttribute('tabindex', '-1');
    }

    focarQuandoPuder(scrim, alvo);
}

/**
 * Dá o foco a `alvo` — e, se o navegador recusar, tenta de novo nos próximos quadros.
 *
 * Recusa acontece bem no instante da abertura: o Chrome não foca elemento cujo
 * `visibility` computado é hidden, e é o que um filho com `transition: all` ainda tem
 * nesse instante (ver `visivel`). Um quadro depois a transição já começou e o valor passa
 * a visible. Desiste se o diálogo fechou ou saiu do topo, ou se o foco já entrou nele por
 * outro caminho (a pessoa foi mais rápida).
 */
function focarQuandoPuder(scrim, alvo, tentativas = 10) {
    alvo.focus();
    if (document.activeElement === alvo || tentativas <= 0) return;

    const proximoQuadro = typeof window.requestAnimationFrame === 'function'
        ? (fn) => window.requestAnimationFrame(fn)
        : (fn) => window.setTimeout(fn, 16);
    proximoQuadro(() => {
        const topo = pilha[pilha.length - 1];
        if (!topo || topo.scrim !== scrim || scrim.contains(document.activeElement)) return;
        focarQuandoPuder(scrim, alvo, tentativas - 1);
    });
}

/**
 * Recalcula a página inerte a partir do diálogo do TOPO: primeiro devolve tudo o que este
 * módulo marcou, depois marca os irmãos de cada ancestral do topo até o <body>. Recalcular
 * do zero, em vez de empilhar e desempilhar marcas, é o que deixa o diálogo empilhado (o
 * de fonte por cima do Lançar) e o órfão do pjax sem caso especial.
 */
function sincronizarInert() {
    marcados.forEach((el) => el.removeAttribute('inert'));
    marcados.clear();

    const topo = pilha[pilha.length - 1];
    if (topo) {
        for (let no = topo.scrim; no.parentElement; no = no.parentElement) {
            for (const irmao of no.parentElement.children) {
                if (irmao === no || SEM_INERT.has(irmao.tagName) || irmao.hasAttribute('inert')) continue;
                irmao.setAttribute('inert', '');
                marcados.add(irmao);
            }
            if (no.parentElement === document.body) break;
        }
    }

    vigiar();
}

/**
 * Com diálogo aberto, observa o documento: um nó removido (troca do #content) ou um
 * `.open` tirado por fora do utilitário libera a página na hora, sem esperar a próxima
 * tecla. Sem diálogo aberto, não observa nada.
 */
function vigiar() {
    if (typeof MutationObserver === 'undefined') return;

    if (pilha.length && !observador) {
        // A guarda do `document` é para o fim da página (ou do ambiente de teste): uma
        // notificação que chegue depois de o documento ir embora não tem o que liberar.
        observador = new MutationObserver(() => {
            if (typeof document !== 'undefined') liberarDialogosOrfaos();
        });
        observador.observe(document.body, {
            childList: true, subtree: true, attributes: true, attributeFilter: ['class'],
        });
    } else if (!pilha.length && observador) {
        observador.disconnect();
        observador = null;
    }
}

/**
 * Devolve o foco a quem abriu. Se ele sumiu (o pjax trocou a tela) ou não aceita foco,
 * o foco vai para o diálogo que ficou por baixo, se houver; sem nenhum, sai do diálogo
 * que está fechando — senão ficaria num campo que em ¼ de segundo vira invisível.
 */
function devolverFocoA(el, scrimQueFechou = null) {
    if (el && el.isConnected && !el.closest('[inert]') && !el.disabled) {
        el.focus();
        if (document.activeElement === el) return;
    }

    const topo = pilha[pilha.length - 1];
    if (topo) {
        focarDentro(topo.scrim);
        return;
    }

    const ativo = document.activeElement;
    if (scrimQueFechou && ativo && scrimQueFechou.contains(ativo)) ativo.blur();
}

/**
 * Descarta da pilha o diálogo que saiu do documento ou perdeu o `.open` sem passar por
 * `fecharDialogo`, e recalcula a página. Barato: olha só os diálogos abertos.
 */
export function liberarDialogosOrfaos() {
    const topoAntes = pilha[pilha.length - 1];
    let mudou = false;

    for (let i = pilha.length - 1; i >= 0; i--) {
        const { scrim } = pilha[i];
        if (!scrim.isConnected || !scrim.classList.contains('open')) {
            pilha.splice(i, 1);
            mudou = true;
        }
    }
    if (!mudou) return;

    sincronizarInert();

    // O do topo foi embora com o foco dentro: devolve a quem abriu, como num fechamento.
    if (topoAntes && !pilha.includes(topoAntes)) {
        const ativo = document.activeElement;
        if (!ativo || ativo === document.body || topoAntes.scrim.contains(ativo)) {
            devolverFocoA(topoAntes.retorno, topoAntes.scrim);
        }
    }
}

/** Tab preso: do último controle volta ao primeiro, e vice-versa com Shift. */
function prenderTab(e, scrim) {
    const paradas = paradasDoTab(scrim);

    if (!paradas.length) {
        e.preventDefault();
        focarDentro(scrim);
        return;
    }

    const primeiro = paradas[0];
    const ultimo = paradas[paradas.length - 1];
    const ativo = document.activeElement;

    // O foco escapou (clique no véu, um controle que sumiu no meio do caminho): o Tab
    // traz de volta pela ponta que combina com a direção.
    if (!ativo || !scrim.contains(ativo)) {
        e.preventDefault();
        (e.shiftKey ? ultimo : primeiro).focus();
        return;
    }

    // Compara pela ORDEM DO DOCUMENTO, e não pela posição na lista: o foco pode estar num
    // controle que saiu dela — o "Salvar" que acabou de ficar desabilitado durante o envio.
    const temParaOndeIr = e.shiftKey
        ? paradas.some((el) => vemDepois(el, ativo))
        : paradas.some((el) => vemDepois(ativo, el));

    if (!temParaOndeIr) {
        e.preventDefault();
        (e.shiftKey ? ultimo : primeiro).focus();
    }
}

function aoTeclar(e) {
    if (!pilha.length) return;
    liberarDialogosOrfaos();

    const topo = pilha[pilha.length - 1];
    if (!topo) return;

    if (e.key === 'Escape' || e.key === 'Esc') {
        // Durante a composição de um acento (IME), o Esc é do teclado, não do diálogo.
        if (e.isComposing) return;
        // Um Esc = uma camada. Sem isto os outros ouvintes de Esc do documento (popovers
        // do shell, modais da tela de faturas) agiriam no MESMO toque, e o diálogo de
        // baixo fecharia junto com o de cima.
        e.stopPropagation();
        if (topo.aoPedirFechar) topo.aoPedirFechar();
        else fecharDialogo(topo.scrim);
        return;
    }

    if (e.key === 'Tab') prenderTab(e, topo.scrim);
}

function ligar() {
    if (ligado) return;
    ligado = true;
    // Captura: roda antes dos outros ouvintes de teclado do documento, para o Esc do
    // diálogo do topo poder parar a propagação (ver `aoTeclar`).
    document.addEventListener('keydown', aoTeclar, true);
}

/**
 * Abre `scrim` (um `.modal-scrim`) como diálogo modal.
 *
 * @param {HTMLElement} scrim
 * @param {object} [opcoes]
 * @param {HTMLElement|string|null} [opcoes.foco] onde o foco começa (elemento ou seletor
 *        dentro do diálogo). Sem ele: o primeiro controle do corpo.
 * @param {HTMLElement|null} [opcoes.retorno] quem recebe o foco de volta ao fechar. Sem
 *        ele: quem estava com o foco na hora de abrir. Passe o botão clicado sempre que
 *        puder — o Safari não dá foco a botão clicado com o mouse.
 * @param {Function|null} [opcoes.aoPedirFechar] o que o Esc faz. Sem ele: `fecharDialogo`.
 *        O modal de fonte usa para resolver a promessa com "cancelou".
 */
export function abrirDialogo(scrim, { foco = null, retorno = null, aoPedirFechar = null } = {}) {
    // Elemento fora do documento (a tela trocou por baixo): não há o que abrir.
    if (!scrim || !scrim.isConnected) return;

    ligar();
    liberarDialogosOrfaos();

    // Quem abriu é lido ANTES de a página ficar inerte: depois disso o navegador já tirou
    // o foco do botão.
    const ativo = document.activeElement;
    const quemAbriu = retorno
        || (ativo && ativo !== document.body && !scrim.contains(ativo) ? ativo : null);

    let entrada = pilha.find((d) => d.scrim === scrim);
    if (entrada) {
        // Reabrir o que já está aberto só o traz para o topo; quem abriu continua o mesmo.
        pilha.splice(pilha.indexOf(entrada), 1);
        if (retorno) entrada.retorno = retorno;
        if (aoPedirFechar) entrada.aoPedirFechar = aoPedirFechar;
    } else {
        entrada = { scrim, retorno: quemAbriu, aoPedirFechar };
    }
    pilha.push(entrada);

    prepararMarcacao(scrim);
    scrim.classList.add('open');
    sincronizarInert();
    focarDentro(scrim, foco);
}

/**
 * Fecha `scrim` e, se ele era o diálogo do topo, devolve o foco a quem o abriu.
 * Fechar o que não foi aberto por aqui só tira o `.open` — nunca quebra.
 */
export function fecharDialogo(scrim, { devolverFoco = true } = {}) {
    if (!scrim) return;
    scrim.classList.remove('open');

    const i = pilha.findIndex((d) => d.scrim === scrim);
    if (i === -1) return;

    const eraOTopo = i === pilha.length - 1;
    const [entrada] = pilha.splice(i, 1);

    // A página volta a ser alcançável ANTES de o foco voltar: foco em elemento inerte não
    // pega.
    sincronizarInert();
    if (devolverFoco && eraOTopo) devolverFocoA(entrada.retorno, scrim);
}

/** `scrim` está aberto como diálogo (registrado aqui)? */
export function dialogoAberto(scrim) {
    return pilha.some((d) => d.scrim === scrim);
}

/**
 * Liga o teclado (Esc e Tab) e publica a ponte `window.smDialogo` para os scripts inline
 * das views (métodos de pagamento, dependentes, excluir conta), que não importam módulos.
 *
 * Chamada na AVALIAÇÃO do app.js, não no DOMContentLoaded: os scripts inline que esperam
 * por ela (reabrir um modal com erro de validação) registram o ouvinte deles durante o
 * parse, antes de qualquer módulo — e o módulo roda antes de o DOMContentLoaded disparar.
 */
export function initDialogos() {
    ligar();
    window.smDialogo = { abrir: abrirDialogo, fechar: fecharDialogo, aberto: dialogoAberto };
}
