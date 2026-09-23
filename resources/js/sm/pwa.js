// PWA: registra o service worker (/sw.js) para o app ser instalável e
// funcionar offline. Tudo defensivo — feature-detection + try/catch — para
// que uma falha de registro NUNCA quebre a página.
//
// E percebe VERSÃO NOVA do app (P-6 da auditoria de PWA de 06/09/2026). O SW agora
// carrega a versão do build no próprio código, então um deploy muda os bytes do /sw.js
// e o navegador instala o SW novo, que assume as abas abertas (`controllerchange`).
// Faltavam duas pontas, e as duas moram aqui:
//  - PERGUNTAR ao navegador se há versão nova. Ele só confere o /sw.js sozinho numa
//    navegação completa — e com o pjax quase não há navegação completa: um app
//    instalado ficava dias aberto com o CSS/JS velhos. `vigiarAtualizacoes`;
//  - AVISAR a página quando a versão dela ficou para trás. `ouvirTrocaDeVersao`.

/**
 * Evento (no `window`) de "esta página ficou para trás: há build novo no ar". O
 * `sm/nav.js` o escuta e passa a navegar com carregamento completo — é a próxima
 * navegação, não um reload no meio do que a pessoa está fazendo, que traz o CSS/JS novos.
 */
export const EVENTO_VERSAO_NOVA = 'sm:versao-nova';

/**
 * De quanto em quanto tempo, no máximo, conferir se há SW novo com a página na frente.
 * Cada conferência é um GET do /sw.js — sem sessão e sem cookie desde 22/09/2026.
 */
export const INTERVALO_DE_CHECAGEM = 30 * 60 * 1000;

export function initPwa() {
    if (!('serviceWorker' in navigator)) return;
    const sw = navigator.serviceWorker;

    ouvirTrocaDeVersao(sw);

    const registrar = () => {
        sw.register('/sw.js')
            .then((reg) => vigiarAtualizacoes(reg))
            .catch((err) => {
                console.warn('PWA: service worker não registrado —', err);
            });
    };

    // Registra após o load para não competir com o carregamento da página.
    if (document.readyState === 'complete') registrar();
    else window.addEventListener('load', registrar);
}

/**
 * Pede ao navegador que confira o /sw.js quando o app volta para a frente e, com ele
 * aberto, a cada `INTERVALO_DE_CHECAGEM` — nunca mais que isso. Achando bytes novos, o
 * navegador instala e ativa o SW novo sozinho (`skipWaiting` + `clients.claim`), e o
 * `controllerchange` chega a esta página.
 */
function vigiarAtualizacoes(reg) {
    if (!reg || typeof reg.update !== 'function') return;

    // O `register` acabou de conferir: a primeira checagem nossa só depois do intervalo.
    let ultima = Date.now();

    const conferir = () => {
        if (document.visibilityState !== 'visible') return;
        if (Date.now() - ultima < INTERVALO_DE_CHECAGEM) return;
        ultima = Date.now();
        // Sem rede a conferência falha — tenta de novo na próxima vez, em silêncio.
        Promise.resolve().then(() => reg.update()).catch(() => {});
    };

    document.addEventListener('visibilitychange', conferir);
    setInterval(conferir, INTERVALO_DE_CHECAGEM);
}

/**
 * Quando um SW NOVO assume esta página, pergunta a versão dele e compara com a da
 * própria página (meta `sm-versao`, posta pelo layouts/app).
 *
 * A pergunta existe para não haver alarme falso: `controllerchange` também acontece
 * logo depois de um carregamento completo que JÁ trouxe o CSS/JS novos (a página nasceu
 * controlada pelo SW velho, e o novo assumiu segundos depois) e quando o que mudou foi só
 * o código do SW. Nos dois casos as versões batem e nada acontece.
 */
function ouvirTrocaDeVersao(sw) {
    let controlador = sw.controller;

    sw.addEventListener('controllerchange', () => {
        const anterior = controlador;
        controlador = sw.controller;
        // Sem controlador ANTES = primeira instalação: o SW acabou de assumir uma página que
        // veio inteira da rede, com os assets certos. Nada a perguntar.
        if (!anterior || !controlador) return;
        try {
            controlador.postMessage({ tipo: 'sm-versao?' });
        } catch (_) { /* SW substituído no meio do caminho: a próxima troca pergunta de novo */ }
    });

    sw.addEventListener('message', (e) => {
        const dados = e.data || {};
        if (dados.tipo !== 'sm-versao' || typeof dados.versao !== 'string') return;

        const daPagina = document.querySelector('meta[name="sm-versao"]')?.getAttribute('content');
        // Página sem a meta (telas de auth, legais): não tem pjax nem nada a atualizar.
        if (!daPagina || dados.versao === daPagina) return;

        versaoNova();
    });

    // A fila de mensagens do SW para a página só anda depois que ela é ligada — por
    // `onmessage` ou por isto. Ligar já, e não esperar o fim do carregamento.
    if (typeof sw.startMessages === 'function') sw.startMessages();
}

let avisado = false;

function versaoNova() {
    if (avisado) return;
    avisado = true;
    window.dispatchEvent(new CustomEvent(EVENTO_VERSAO_NOVA));
    mostrarAviso();
}

/**
 * O aviso "saiu uma versão nova", com a escolha na mão da pessoa: atualizar agora ou
 * seguir — e aí a próxima navegação já vem completa (ver `EVENTO_VERSAO_NOVA`). Nunca
 * recarrega sozinho: um modal aberto perderia o que ela estava digitando.
 *
 * Reusa o visual da barra de avisos (`.cookie-bar`, com `.cookie-txt` e os botões do
 * design system) em vez de um CSS próprio. Fica no TOPO, e não embaixo como a barra de
 * cookies: embaixo já moram ela, o selo e o toast da fila offline. Camada 85, a da
 * barra — acima dos popovers, abaixo dos modais (tests/js/camadas.test.js).
 */
function mostrarAviso() {
    if (document.getElementById('sm-versao-nova')) return;

    const aviso = document.createElement('div');
    aviso.id = 'sm-versao-nova';
    aviso.className = 'cookie-bar';
    aviso.style.top = 'calc(16px + env(safe-area-inset-top, 0px))';
    aviso.style.bottom = 'auto';

    // Região viva para o leitor de tela. Nasce VAZIA e recebe o texto no quadro seguinte:
    // região que já entra no documento com o texto costuma não ser anunciada.
    const texto = document.createElement('div');
    texto.className = 'cookie-txt';
    texto.setAttribute('role', 'status');

    const agoraNao = document.createElement('button');
    agoraNao.type = 'button';
    agoraNao.className = 'btn-ghost';
    agoraNao.textContent = 'Agora não';
    agoraNao.addEventListener('click', () => aviso.remove());

    const atualizar = document.createElement('button');
    atualizar.type = 'button';
    atualizar.className = 'btn-primary cookie-accept';
    atualizar.textContent = 'Atualizar';
    atualizar.addEventListener('click', () => window.location.reload());

    aviso.append(texto, agoraNao, atualizar);
    document.body.appendChild(aviso);

    requestAnimationFrame(() => {
        const titulo = document.createElement('strong');
        titulo.textContent = 'Saiu uma versão nova do Stabil Money';
        const detalhe = document.createElement('p');
        detalhe.textContent = 'Atualize para usar a mais recente. O que você já salvou continua aí.';
        texto.append(titulo, detalhe);
        aviso.classList.add('show');
    });
}
