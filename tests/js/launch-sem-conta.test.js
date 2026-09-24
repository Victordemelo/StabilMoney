import { afterEach, beforeEach, describe, expect, it } from 'vitest';

/**
 * Modal global "Lançar" (`resources/js/sm/launch.js`) para quem ainda NÃO tem conta.
 *
 * Sem conta cadastrada, o `partials/launch-modal.blade.php` não renderiza o formulário:
 * o modal traz só o aviso "Crie uma conta primeiro" e o botão "Criar conta". É o estado
 * de TODA pessoa recém-cadastrada — o cadastro semeia as categorias, não as contas.
 *
 * O `initLaunch` sabe disso (`if (!form) return;`), mas o ouvinte que ABRE o modal é
 * ligado antes dessa guarda, e o `open()` zera o formulário (`form.reset()`) antes de
 * abrir. Com `form === null`, o clique no "Lançar" da topbar, no botão central da
 * barra de baixo ou no "Nova transação" do Histórico cancelava a navegação para
 * `/transactions/create` (o `preventDefault` vem antes) e morria num TypeError: nem o
 * modal abria, nem a página cheia. O botão mais visível do app ficava mudo, sem nenhuma
 * mensagem, justamente para quem acabou de chegar.
 *
 * Arquivo próprio (e não um `describe` no `launch.test.js`): aquele monta SEMPRE o modal
 * com formulário no `beforeEach`, e o ouvinte delegado de cada montagem continua ligado
 * no `document` — misturar os dois estados embaralharia qual ouvinte respondeu.
 */

import { initLaunch } from '../../resources/js/sm/launch.js';

/** O modal como o Blade o entrega quando `$lmAccounts` está vazio. */
function montarModalSemConta() {
    document.body.innerHTML = `
        <a href="/transactions/create" class="launch-btn" data-launch-open>Lançar</a>
        <div class="modal-scrim" id="launchModal" data-close>
            <div class="modal modal-wide" role="dialog" aria-modal="true" aria-labelledby="lm-titulo">
                <div class="modal-head">
                    <h3 id="lm-titulo">Nova transação</h3>
                    <button class="modal-x" type="button" data-close-btn aria-label="Fechar">×</button>
                </div>
                <div class="modal-body">
                    <div class="empty-state">
                        <h3>Crie uma conta primeiro</h3>
                        <p>Você precisa de pelo menos uma conta para registrar transações.</p>
                        <a class="btn-primary" href="/accounts/create">Criar conta</a>
                    </div>
                </div>
                <div class="modal-foot">
                    <button class="btn ghost" type="button" data-close-btn>Fechar</button>
                </div>
            </div>
        </div>`;

    initLaunch();
}

let erros;
const registrarErro = (e) => { erros.push(e.error || e.message); e.preventDefault(); };

beforeEach(() => {
    erros = [];
    window.addEventListener('error', registrarErro);
    montarModalSemConta();
});

afterEach(() => {
    window.removeEventListener('error', registrarErro);
});

describe('"Lançar" sem conta cadastrada', () => {
    it('o clique abre o modal com o aviso "Crie uma conta primeiro", sem erro', () => {
        const gatilho = document.querySelector('[data-launch-open]');

        const clique = new MouseEvent('click', { bubbles: true, cancelable: true });
        gatilho.dispatchEvent(clique);

        expect(erros).toEqual([]);
        // O clique foi interceptado (o modal é o destino) — então o modal TEM de abrir.
        expect(clique.defaultPrevented).toBe(true);
        expect(document.getElementById('launchModal').classList.contains('open')).toBe(true);
    });

    it('fecha pelo botão "Fechar" como qualquer outro modal', () => {
        document.querySelector('[data-launch-open]').click();
        document.querySelector('.modal-foot [data-close-btn]').click();

        expect(erros).toEqual([]);
        expect(document.getElementById('launchModal').classList.contains('open')).toBe(false);
    });
});
