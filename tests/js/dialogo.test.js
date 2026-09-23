import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { flush } from './helpers/flush.js';

import {
    abrirDialogo,
    fecharDialogo,
    initDialogos,
    liberarDialogosOrfaos,
} from '../../resources/js/sm/dialogo.js';

/**
 * Utilitário de diálogo (`resources/js/sm/dialogo.js`) — achado A-2 da auditoria de
 * acessibilidade de 07/09/2026: nenhum modal do app era um diálogo. Com o modal aberto, o
 * Tab a partir do último campo escapava para a barra de cookies e a sidebar (atrás do
 * véu, invisíveis), e o Esc jogava o foco no <body>.
 *
 * O DOM imita o shell do `layouts/app.blade.php`: um modal de CONTEÚDO (dentro do
 * #content, como o de categorias) e os dois do SHELL (irmãos do #app, como o Lançar e o
 * de fonte), mais a barra de cookies. É justamente a diferença entre os dois lugares que
 * decide o que fica inerte — e o que o pjax faz com cada um.
 *
 * O jsdom não implementa `inert` (nem bloqueia foco por ele) nem faz a navegação do Tab
 * sozinho: o que se testa é o ATRIBUTO (que o navegador de verdade honra) e a volta do
 * foco nas pontas, que é exatamente o que o utilitário faz à mão.
 */

function modal(id, { corpo = '', rodape = '' } = {}) {
    return `
        <div class="modal-scrim" id="${id}">
            <div class="modal" role="dialog" aria-modal="true" aria-labelledby="${id}-titulo">
                <div class="modal-head">
                    <h3 id="${id}-titulo">${id}</h3>
                    <button type="button" class="modal-x" id="${id}-x">Fechar</button>
                </div>
                <form>
                    ${corpo ? `<div class="modal-body">${corpo}</div>` : ''}
                    <div class="modal-foot">${rodape}</div>
                </form>
            </div>
        </div>`;
}

function montarPagina() {
    document.body.innerHTML = `
        <div class="sm-falling" aria-hidden="true"></div>
        <div class="app" id="app">
            <aside class="sidebar" id="sidebar"><a href="/metas" id="linkSidebar">Metas</a></aside>
            <div class="main">
                <header class="topbar"><button type="button" id="lancar">Lançar</button></header>
                <main class="content" id="content">
                    <button type="button" id="novaCategoria">Nova categoria</button>
                    ${modal('catModal', {
                        corpo: `
                            <input type="radio" name="type" value="income" id="catReceita" checked>
                            <input type="radio" name="type" value="expense" id="catDespesa">
                            <input type="text" id="catNome">
                            <input type="hidden" name="_token" value="x">`,
                        rodape: `
                            <button type="button" id="catCancelar">Cancelar</button>
                            <button type="submit" id="catSalvar">Salvar</button>`,
                    })}
                    ${modal('excluirModal', {
                        rodape: `
                            <button type="button" id="excluirCancelar">Cancelar</button>
                            <button type="submit" id="excluirConfirmar">Excluir meta</button>`,
                    })}
                    ${modal('escolhaModal', {
                        corpo: `
                            <input type="radio" name="periodo" value="mes" id="periodoMes" checked>
                            <input type="radio" name="periodo" value="ano" id="periodoAno">`,
                    })}
                </main>
            </div>
            <nav class="bottom-nav"><a href="/" id="linkBaixo">Início</a></nav>
        </div>
        ${modal('launchModal', {
            corpo: '<input type="text" id="lmValor"><input type="text" id="lmDescricao">',
            rodape: '<button type="submit" id="lmSalvar">Salvar</button>',
        })}
        ${modal('fundingModal', {
            corpo: `
                <input type="radio" name="fonte" value="cheque" id="fonteCheque" checked>
                <input type="radio" name="fonte" value="resgate" id="fonteResgate">`,
            rodape: `
                <button type="button" id="fonteCancelar">Cancelar</button>
                <button type="button" id="fonteConfirmar">Confirmar</button>`,
        })}
        <div class="cookie-bar" id="cookieBar"><button type="button" id="cookieAceitar">Aceitar</button></div>
        <script>/* scripts do shell: nunca precisam de inert */</script>`;
}

const $ = (id) => document.getElementById(id);
const aberto = (id) => $(id).classList.contains('open');
const inertes = () => Array.from(document.querySelectorAll('[inert]')).map((el) => el.id || el.className);

/** Tecla no elemento com foco (ou no <body>), borbulhando até o documento. */
function tecla(key, { shiftKey = false } = {}) {
    const evento = new KeyboardEvent('keydown', { key, shiftKey, bubbles: true, cancelable: true });
    (document.activeElement || document.body).dispatchEvent(evento);
    return evento;
}

beforeEach(() => {
    montarPagina();
});

afterEach(() => {
    // Nada de estado vazando para o próximo teste: fecha o que ficou aberto.
    document.querySelectorAll('.modal-scrim.open').forEach((s) => fecharDialogo(s, { devolverFoco: false }));
    liberarDialogosOrfaos();
    delete window.smDialogo;
});

describe('abrir', () => {
    it('leva o foco para o primeiro controle do CORPO, pulando o X do cabeçalho', () => {
        $('novaCategoria').focus();

        abrirDialogo($('catModal'));

        expect(aberto('catModal')).toBe(true);
        // O primeiro do corpo é o grupo de radios; o Tab para no MARCADO.
        expect(document.activeElement).toBe($('catReceita'));
    });

    it('respeita o foco pedido por quem abriu (elemento ou seletor)', () => {
        abrirDialogo($('catModal'), { foco: '#catNome' });
        expect(document.activeElement).toBe($('catNome'));

        fecharDialogo($('catModal'));
        abrirDialogo($('catModal'), { foco: $('catSalvar') });
        expect(document.activeElement).toBe($('catSalvar'));
    });

    it('num "Excluir?" sem corpo, o foco cai no Cancelar — a opção que não destrói nada', () => {
        abrirDialogo($('excluirModal'));
        expect(document.activeElement).toBe($('excluirCancelar'));
    });

    it('o visibility ainda em transição na abertura NÃO desvia o foco para o botão destrutivo', async () => {
        // Medido no Chromium: o X e o "Cancelar" têm `transition: all`, então o visibility
        // que herdam do véu ANIMA de hidden para visible — no instante da abertura o
        // computado ainda é hidden, e o navegador recusa o foco neles. Só o "Excluir meta"
        // (sem `transition: all`) passava, e o foco caía nele. Aqui esse instante é
        // congelado à mão; o jsdom, sozinho, não recusaria foco nenhum.
        const cancelar = $('excluirCancelar');
        cancelar.style.visibility = 'hidden';
        $('excluirModal-x').style.visibility = 'hidden';
        const focarDeVerdade = HTMLElement.prototype.focus;
        let recusas = 1;
        cancelar.focus = function () {
            if (recusas-- > 0) return; // o navegador recusa enquanto está hidden
            focarDeVerdade.call(this);
        };

        abrirDialogo($('excluirModal'));
        expect(document.activeElement).not.toBe($('excluirConfirmar'));

        // Um quadro depois a transição começou: o foco entra no Cancelar.
        cancelar.style.visibility = '';
        $('excluirModal-x').style.visibility = '';
        await new Promise((resolve) => setTimeout(resolve, 60));

        expect(document.activeElement).toBe(cancelar);
    });

    it('deixa o RESTO da página inerte, e o caminho até o diálogo vivo', () => {
        abrirDialogo($('catModal'));

        // Fora do diálogo, nos três níveis: dentro do #content, no shell e no <body>.
        ['novaCategoria', 'excluirModal', 'sidebar', 'launchModal', 'fundingModal', 'cookieBar'].forEach((id) => {
            expect($(id).hasAttribute('inert'), `${id} deveria estar inerte`).toBe(true);
        });
        expect(document.querySelector('.topbar').hasAttribute('inert')).toBe(true);
        expect(document.querySelector('.bottom-nav').hasAttribute('inert')).toBe(true);

        // O diálogo e os ancestrais dele, não — senão ele próprio ficaria inerte.
        ['catModal', 'content', 'app'].forEach((id) => {
            expect($(id).hasAttribute('inert'), `${id} não pode ficar inerte`).toBe(false);
        });
        expect(document.querySelector('.main').hasAttribute('inert')).toBe(false);
        expect(document.querySelector('script').hasAttribute('inert')).toBe(false);
    });

    it('um modal do SHELL deixa o #app inteiro inerte', () => {
        abrirDialogo($('launchModal'));

        expect($('app').hasAttribute('inert')).toBe(true);
        expect($('cookieBar').hasAttribute('inert')).toBe(true);
        expect($('launchModal').hasAttribute('inert')).toBe(false);
    });

    it('elemento fora do documento (a tela trocou por baixo) não abre nada', () => {
        const solto = document.createElement('div');
        solto.className = 'modal-scrim';

        abrirDialogo(solto);

        expect(solto.classList.contains('open')).toBe(false);
        expect(inertes()).toEqual([]);
    });
});

describe('Tab preso', () => {
    it('Tab no último controle volta ao primeiro', () => {
        abrirDialogo($('catModal'));
        $('catSalvar').focus();

        const evento = tecla('Tab');

        expect(evento.defaultPrevented).toBe(true);
        expect(document.activeElement).toBe($('catModal-x'));
    });

    it('Shift+Tab no primeiro vai para o último', () => {
        abrirDialogo($('catModal'));
        $('catModal-x').focus();

        const evento = tecla('Tab', { shiftKey: true });

        expect(evento.defaultPrevented).toBe(true);
        expect(document.activeElement).toBe($('catSalvar'));
    });

    it('no meio do diálogo, o Tab é do navegador (não intercepta)', () => {
        abrirDialogo($('catModal'));
        $('catNome').focus();

        expect(tecla('Tab').defaultPrevented).toBe(false);
        expect(tecla('Tab', { shiftKey: true }).defaultPrevented).toBe(false);
    });

    it('conta UM radio por grupo: do marcado, quando o grupo fecha o diálogo, volta ao primeiro', () => {
        abrirDialogo($('escolhaModal'));
        expect(document.activeElement).toBe($('periodoMes'));

        const evento = tecla('Tab');

        // O radio NÃO marcado vem depois no DOM, mas o navegador não para nele: do marcado,
        // o Tab dele já sairia do diálogo. Por isso é o marcado que conta como o último.
        expect(evento.defaultPrevented).toBe(true);
        expect(document.activeElement).toBe($('escolhaModal-x'));
    });

    it('com o foco fora do diálogo (clique no véu), o Tab traz de volta pela ponta certa', () => {
        abrirDialogo($('catModal'));
        document.activeElement.blur();

        tecla('Tab');
        expect(document.activeElement).toBe($('catModal-x'));

        document.activeElement.blur();
        tecla('Tab', { shiftKey: true });
        expect(document.activeElement).toBe($('catSalvar'));
    });

    it('o "Salvar" que ficou desabilitado no meio do envio não deixa o Tab escapar', () => {
        abrirDialogo($('catModal'));
        $('catSalvar').focus();
        $('catSalvar').disabled = true; // o spinner do envio

        const evento = tecla('Tab');

        expect(evento.defaultPrevented).toBe(true);
        expect(document.activeElement).toBe($('catModal-x'));
    });
});

describe('Esc e fechar', () => {
    it('Esc fecha e devolve o foco a quem abriu', () => {
        $('novaCategoria').focus();
        abrirDialogo($('catModal'));

        tecla('Escape');

        expect(aberto('catModal')).toBe(false);
        expect(document.activeElement).toBe($('novaCategoria'));
    });

    it('nada fica inerte depois de fechar', () => {
        abrirDialogo($('catModal'));
        expect(inertes().length).toBeGreaterThan(0);

        tecla('Escape');

        expect(inertes()).toEqual([]);
    });

    it('o que já era inerte por outro motivo continua inerte', () => {
        $('cookieBar').setAttribute('inert', '');

        abrirDialogo($('catModal'));
        fecharDialogo($('catModal'));

        expect($('cookieBar').hasAttribute('inert')).toBe(true);
        expect(inertes()).toEqual(['cookieBar']);
    });

    it('o `retorno` explícito vence: o Safari não dá foco ao botão clicado com o mouse', () => {
        // Clique no Safari: o botão não recebe foco, e o ativo continua sendo o <body>.
        abrirDialogo($('catModal'), { retorno: $('novaCategoria') });

        fecharDialogo($('catModal'));

        expect(document.activeElement).toBe($('novaCategoria'));
    });

    it('`aoPedirFechar` decide o que o Esc faz (o modal de fonte responde "cancelou")', () => {
        const aoPedirFechar = vi.fn(() => fecharDialogo($('fundingModal')));
        abrirDialogo($('fundingModal'), { aoPedirFechar });

        tecla('Escape');

        expect(aoPedirFechar).toHaveBeenCalledTimes(1);
        expect(aberto('fundingModal')).toBe(false);
    });

    it('um Esc = uma camada: com diálogo aberto, os outros ouvintes de Esc não disparam', () => {
        const outroOuvinte = vi.fn();
        document.addEventListener('keydown', outroOuvinte);

        abrirDialogo($('catModal'));
        tecla('Escape');
        expect(outroOuvinte).not.toHaveBeenCalled();

        // Sem diálogo aberto, o utilitário não se mete.
        tecla('Escape');
        expect(outroOuvinte).toHaveBeenCalledTimes(1);

        document.removeEventListener('keydown', outroOuvinte);
    });
});

describe('diálogo empilhado (o de fonte por cima do Lançar)', () => {
    it('o Esc fecha só o de cima, e o de baixo volta a ser a página', () => {
        $('lancar').focus();
        abrirDialogo($('launchModal'), { foco: '#lmValor' });

        $('lmSalvar').focus();
        abrirDialogo($('fundingModal'));

        // Com o de fonte por cima, o Lançar também fica inerte.
        expect($('launchModal').hasAttribute('inert')).toBe(true);
        expect(document.activeElement).toBe($('fonteCheque'));

        tecla('Escape');

        expect(aberto('fundingModal')).toBe(false);
        expect(aberto('launchModal')).toBe(true);
        // O foco volta ao botão que disparou o envio, e o Lançar volta a valer.
        expect(document.activeElement).toBe($('lmSalvar'));
        expect($('launchModal').hasAttribute('inert')).toBe(false);
        expect($('app').hasAttribute('inert')).toBe(true);

        tecla('Escape');

        expect(aberto('launchModal')).toBe(false);
        expect(document.activeElement).toBe($('lancar'));
        expect(inertes()).toEqual([]);
    });
});

describe('pjax', () => {
    it('o modal do #content some com a tela: a página volta a responder', () => {
        abrirDialogo($('catModal'));
        expect(document.querySelector('.topbar').hasAttribute('inert')).toBe(true);

        // O que o nav.js faz: troca o #content inteiro (o modal aberto vai junto) e
        // chama o initContent, que libera os órfãos antes de tudo.
        $('content').innerHTML = '<h2>Metas</h2><button type="button" id="novaMeta">Nova meta</button>';
        liberarDialogosOrfaos();

        expect(inertes()).toEqual([]);
    });

    it('mesmo sem o initContent, a troca do #content libera a página sozinha', async () => {
        abrirDialogo($('catModal'));

        $('content').innerHTML = '<h2>Metas</h2>';
        await flush(); // o MutationObserver notifica numa microtask

        expect(inertes()).toEqual([]);
    });

    it('o Lançar, que vive no shell, sobrevive à troca e continua dono da página', () => {
        abrirDialogo($('launchModal'));

        $('content').innerHTML = '<h2>Histórico</h2>';
        liberarDialogosOrfaos();

        expect(aberto('launchModal')).toBe(true);
        expect($('app').hasAttribute('inert')).toBe(true);
        expect($('cookieBar').hasAttribute('inert')).toBe(true);
    });

    it('um `.open` tirado POR FORA do utilitário também libera a página', async () => {
        abrirDialogo($('catModal'));

        $('catModal').classList.remove('open'); // código que não passou por fecharDialogo
        await flush();

        expect(inertes()).toEqual([]);
    });
});

describe('ponte para os scripts inline (window.smDialogo)', () => {
    it('abre e fecha pelo mesmo utilitário', () => {
        initDialogos();
        $('novaCategoria').focus();

        window.smDialogo.abrir($('catModal'), { foco: '#catNome' });
        expect(window.smDialogo.aberto($('catModal'))).toBe(true);
        expect(document.activeElement).toBe($('catNome'));
        expect($('sidebar').hasAttribute('inert')).toBe(true);

        window.smDialogo.fechar($('catModal'));
        expect(aberto('catModal')).toBe(false);
        expect(document.activeElement).toBe($('novaCategoria'));
        expect(inertes()).toEqual([]);
    });
});
