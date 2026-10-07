// Rolagem suave da página inicial (out/2026 — pedido do Victor: "dinâmica e fluida, não seca").
//
// Os links do menu ("Recursos", "Como funciona"…) e qualquer outro `a[href^="#"]` da página
// deslizam até a seção numa curva que acelera e desacelera, com a duração proporcional à
// distância (uma seção vizinha é rápida, o fim da página leva um pouco mais). O topo fixo é
// descontado pelo `scroll-margin-top` da própria seção. A rolagem para assim que a pessoa
// mexe a roda, toca na tela ou aperta uma tecla — nunca briga com ela. Com "reduzir
// movimento" o salto é direto. Ao chegar, o endereço ganha o `#seção` (sem pular de novo) e
// o foco vai para a seção, para quem navega por teclado ou leitor de tela continuar dali.

const DURACAO_MIN = 450;
const DURACAO_MAX = 1100;

/** Curva "easeInOutCubic": começa devagar, acelera no meio e pousa suave. */
export function curva(t) {
    return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
}

/** Quanto tempo leva para percorrer `distancia` pixels. */
export function duracaoPara(distancia) {
    return Math.round(Math.min(DURACAO_MAX, Math.max(DURACAO_MIN, 380 + Math.abs(distancia) * 0.32)));
}

/** Quem rola a página: na página inicial é o `body` (o design system trava o `html`). */
function rolador(doc) {
    const body = doc.body;
    const estilo = doc.defaultView.getComputedStyle(body);
    if (/(auto|scroll)/.test(estilo.overflowY) && body.scrollHeight > body.clientHeight) return body;
    return doc.scrollingElement || doc.documentElement;
}

/**
 * Liga a rolagem suave na página inicial. `opcoes` existe para os testes (relógio e quadro).
 * Devolve `{ irPara(alvo), desligar() }`, ou null fora da página inicial.
 */
export function initRolagemSuave(doc = document, opcoes = {}) {
    if (!doc.body || !doc.body.classList.contains('inicio-body') || doc.body.dataset.rolagemSuave) return null;
    doc.body.dataset.rolagemSuave = '1';

    const janela = doc.defaultView;
    const agora = opcoes.agora || (() => janela.performance.now());
    const quadro = opcoes.quadro || ((fn) => janela.requestAnimationFrame(fn));
    const reduzir = opcoes.reduzir ?? janela.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
    let animacao = 0;
    const ouvintes = new AbortController();
    const { signal } = ouvintes;

    const parar = () => { animacao += 1; };
    ['wheel', 'touchstart', 'keydown', 'mousedown'].forEach((ev) => {
        janela.addEventListener(ev, parar, { passive: true, signal });
    });

    const chegar = (alvo) => {
        if (janela.history?.replaceState) janela.history.replaceState(null, '', `#${alvo.id}`);
        if (!alvo.hasAttribute('tabindex')) alvo.setAttribute('tabindex', '-1');
        alvo.focus({ preventScroll: true });
    };

    const irPara = (alvo) => {
        const el = rolador(doc);
        const margem = parseFloat(janela.getComputedStyle(alvo).scrollMarginTop) || 0;
        const topoDoRolador = el === doc.body || el === doc.documentElement ? 0 : el.getBoundingClientRect().top;
        const inicio = el.scrollTop;
        const maximo = el.scrollHeight - el.clientHeight;
        const destino = Math.max(0, Math.min(maximo, inicio + alvo.getBoundingClientRect().top - topoDoRolador - margem));
        const distancia = destino - inicio;

        const escrever = (y) => {
            // `scroll-behavior: smooth` do CSS faria cada passo virar uma animação própria.
            el.style.scrollBehavior = 'auto';
            el.scrollTop = y;
        };

        if (reduzir || Math.abs(distancia) < 2) {
            escrever(destino);
            el.style.scrollBehavior = '';
            chegar(alvo);
            return;
        }

        const minha = ++animacao;
        const t0 = agora();
        const duracao = duracaoPara(distancia);
        const passo = () => {
            if (minha !== animacao) { el.style.scrollBehavior = ''; return; }
            const t = Math.min(1, (agora() - t0) / duracao);
            escrever(inicio + distancia * curva(t));
            if (t < 1) { quadro(passo); return; }
            el.style.scrollBehavior = '';
            chegar(alvo);
        };
        quadro(passo);
    };

    doc.addEventListener('click', (e) => {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        const link = e.target.closest ? e.target.closest('a[href^="#"]') : null;
        if (!link) return;
        const id = decodeURIComponent(link.getAttribute('href').slice(1));
        const alvo = id ? doc.getElementById(id) : null;
        if (!alvo) return;
        e.preventDefault();
        irPara(alvo);
    }, { signal });

    const desligar = () => {
        parar();
        ouvintes.abort();
        delete doc.body.dataset.rolagemSuave;
    };

    return { irPara, desligar };
}
