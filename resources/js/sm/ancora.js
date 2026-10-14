// Ir até o bloco certo de uma tela (14/10/2026 — pedido do Victor: "clicar na fatura de crédito
// tem que me levar até o cartão; o boleto, até a conta fixa"). Os avisos do sino apontam para
// `/faturas#cartao-3` ou `/faturas#conta-fixa-7-2026-10`; ao chegar, o alvo é ABERTO (o card e,
// se for o caso, o "Ver as outras" em volta dele estão recolhidos), centralizado na tela e
// destacado por um instante.
//
// Os avisos moram no balão do sino, que o pjax atualiza trocando os filhos — por isso o clique é
// delegado no documento (`a[data-ir-para]`), e não ligado em cada link.

import { sincronizarPares } from './details-suave';
import { limitarListasEm } from './lista-rolavel';

const DESTAQUE_MS = 2400;

function semMovimento(janela) {
    return janela.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
}

/**
 * Rola SÓ quem rola de propósito (o `.content` do app, a lista longa com `overflow: auto`) até o
 * alvo. O `scrollIntoView` não serve: com o alvo perto do fim, o `.content` chega ao limite e o
 * navegador passa a rolar a página por baixo dele — o menu lateral subia e sobrava uma faixa vazia.
 * Alvo pequeno fica no meio; alvo alto (o card aberto), com o topo a 16px da borda.
 */
function rolarAte(alvo, janela, behavior) {
    const doc = janela.document;
    let ajuste = 0; // quanto as rolagens de dentro já moveram o alvo
    let rolou = false;
    const destino = (alturaVisivel, topoVisivel) => {
        const r = alvo.getBoundingClientRect();
        const topo = r.top - ajuste;
        return r.height > alturaVisivel * 0.6
            ? topo - topoVisivel - 16
            : topo + r.height / 2 - (topoVisivel + alturaVisivel / 2);
    };
    for (let p = alvo.parentElement; p && p !== doc.body && p !== doc.documentElement; p = p.parentElement) {
        const oy = janela.getComputedStyle(p).overflowY;
        if ((oy !== 'auto' && oy !== 'scroll') || p.scrollHeight <= p.clientHeight) continue;
        const rp = p.getBoundingClientRect();
        const antes = p.scrollTop;
        const novo = Math.max(0, Math.min(p.scrollHeight - p.clientHeight, antes + destino(p.clientHeight, rp.top)));
        p.scrollTo({ top: novo, behavior });
        ajuste += novo - antes;
        rolou = true;
    }
    if (!rolou) {
        // Sem contêiner que role (no celular a página inteira rola): a janela.
        janela.scrollTo?.({ top: janela.scrollY + destino(janela.innerHeight, 0), behavior });
    }
}

/** Abre o alvo, rola até ele e o destaca. Devolve false quando a tela não tem esse alvo. */
export function irParaAncora(hash, doc = document) {
    if (!hash || hash.length < 2) return false;
    let alvo;
    try {
        alvo = doc.getElementById(decodeURIComponent(hash.slice(1)));
    } catch (_) {
        return false;
    }
    if (!alvo) return false;

    // Todo <details> no caminho (o próprio card, o "Ver as outras") fica aberto.
    for (let el = alvo; el; el = el.parentElement) {
        if (el.tagName === 'DETAILS') el.open = true;
    }
    sincronizarPares(doc);   // em duas colunas, o vizinho de linha abre junto
    limitarListasEm(doc);    // listas longas já com a altura de rolagem antes de medir

    const janela = doc.defaultView;
    rolarAte(alvo, janela, semMovimento(janela) ? 'auto' : 'smooth');

    // O foco vai junto (teclado e leitor de tela chegam no mesmo lugar). No card, o resumo.
    const focavel = alvo.matches('details') ? alvo.querySelector(':scope > summary') : alvo;
    if (focavel) {
        if (!focavel.matches('a, button, summary, input, select, textarea, [tabindex]')) focavel.setAttribute('tabindex', '-1');
        focavel.focus?.({ preventScroll: true });
    }

    alvo.classList.remove('alvo-destacado');
    void alvo.offsetWidth; // reinicia a animação num segundo clique no mesmo aviso
    alvo.classList.add('alvo-destacado');
    janela.setTimeout(() => alvo.classList.remove('alvo-destacado'), DESTAQUE_MS);
    return true;
}

export function initAncora(doc = document) {
    if (doc.documentElement.dataset.ancora) return;
    doc.documentElement.dataset.ancora = '1';
    doc.addEventListener('click', (e) => {
        const link = e.target.closest ? e.target.closest('a[data-ir-para]') : null;
        if (!link || e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1) return;
        const janela = doc.defaultView;
        if (typeof janela.smPjaxIr !== 'function') return; // sem pjax: o link segue sozinho
        e.preventDefault();
        janela.smPjaxIr(link.getAttribute('href'));
    });
}
