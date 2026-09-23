// Página de categorias (design v2). Duas frentes:
//
//  1) Drag & drop dos chips, em dois eixos:
//       • DENTRO da coluna: reordena (o usuário decide quem fica no topo) e
//         grava a ordem em PATCH categories.ordenar;
//       • ENTRE as colunas "Receitas" e "Despesas": troca o TIPO da categoria
//         (PATCH categories.update com todos os campos que o
//         UpdateCategoryRequest exige) e, em seguida, grava a posição no
//         destino.
//     Tudo otimista: o chip se move na hora e, se o servidor recusar, a tela
//     volta ao estado anterior (rollback) e o aviso mostra o porquê que o
//     servidor deu — categoria com lançamentos, por exemplo, não muda de tipo.
//
//  2) Criar/editar em MODAL, na própria tela (`initCategoryModal`), em vez de
//     navegar para as páginas cheias — que continuam existindo e valendo como
//     fallback sem JS.
//
//  3) Reordenar SEM arrastar (achado A-4 da auditoria de acessibilidade): os botões
//     "mover para cima/baixo" de cada chip gravam no MESMO PATCH categories.ordenar e
//     anunciam a posição nova para o leitor de tela. Arrastar era o único jeito — quem
//     usa teclado, leitor de tela ou um dedo que não segura o arraste não reordenava.
//
// Só roda na página de categorias (guard pelo #catCols).

import { abrirDialogo, fecharDialogo } from './dialogo';

const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));

/**
 * Tipo com que o modal de criação NASCE — receita, igual ao modal global de
 * "Lançar". Quem clica no "Criar agora" de uma coluna vazia manda o tipo dela
 * (data-cat-type) e esse valor tem precedência: ali o usuário já escolheu.
 */
const TIPO_PADRAO = 'income';

const MSG_SEM_CONEXAO = 'Sem conexão com o servidor. Tente de novo em instantes.';

/**
 * Texto a mostrar quando o CategoryController recusa um envio (o do modal ou o do
 * arraste entre colunas).
 *
 * Nas recusas que importam (422) o servidor explica o PORQUÊ: categoria fixa, categoria
 * com lançamentos que não pode mudar de tipo... Um "não foi possível" genérico no lugar
 * deixava a pessoa repetindo algo que nunca vai passar, sem saber a saída.
 */
async function mensagemDeErro(resp, padrao) {
    if (resp.status === 419) return 'Sua sessão expirou. Atualize a página e tente de novo.';
    if (resp.status === 403 || resp.status === 404) return 'Esta categoria não está mais disponível.';

    if (resp.status === 422) {
        const data = await resp.json().catch(() => ({}));
        const errs = data?.errors || {};
        return Object.values(errs)[0]?.[0] || data?.message || padrao;
    }

    return padrao;
}

export function initCategories() {
    const cols = document.getElementById('catCols');
    if (!cols) return;

    initCategoryModal();

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const ordenarUrl = cols.dataset.ordenarUrl || '';

    const cabecalhosJson = () => ({
        'X-CSRF-TOKEN': csrf,
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json',
        'Content-Type': 'application/json',
    });

    let dragged = null; // chip sendo arrastado no momento
    let origem = null;  // { chip, drop, next, type } — de onde ele saiu (rollback)
    let soltou = false; // houve um `drop` válido? Se não, o dragend desfaz

    // Ids na ordem da tela — o corpo do PATCH categories.ordenar.
    const idsDe = (drop) => $$('.cat-chip', drop)
        .map((c) => Number(c.dataset.id))
        .filter((id) => Number.isFinite(id));

    // A última ordem que o SERVIDOR confirmou, por coluna: é para ela que a tela volta
    // quando uma gravação feita pelos botões falha.
    const ordemSalva = new Map($$('.cat-drop', cols).map((drop) => [drop, idsDe(drop)]));

    // Região `role="status"` da view: o leitor de tela lê o que entra aqui sem tirar o
    // foco de onde está. Texto sempre por textContent — o nome é dado do usuário.
    const anuncio = document.getElementById('catAnuncio');
    const anunciar = (texto) => { if (anuncio) anuncio.textContent = texto; };

    const nomeDaColuna = (drop) =>
        drop.closest('.cat-col')?.querySelector('.cat-col-head h3')?.textContent.trim() || '';

    /** "Mercado: posição 2 de 5 em Despesas." — o que a pessoa ouve depois de mover. */
    const anunciarPosicao = (chip) => {
        const drop = chip.closest('.cat-drop');
        if (!drop) return;
        const chips = $$('.cat-chip', drop);
        anunciar(`${chip.dataset.name || 'Categoria'}: posição ${chips.indexOf(chip) + 1} de ${chips.length} em ${nomeDaColuna(drop)}.`);
    };

    /**
     * O primeiro chip não sobe e o último não desce: `aria-disabled`, e não `disabled`,
     * para o botão continuar FOCÁVEL — quem acabou de levar a categoria ao topo segue com
     * o foco nele, em vez de o foco sumir para o <body>.
     */
    const atualizarBotoesDeMover = () => {
        $$('.cat-drop', cols).forEach((drop) => {
            const chips = $$('.cat-chip', drop);
            chips.forEach((chip, i) => {
                const limites = { '-1': i === 0, 1: i === chips.length - 1 };
                $$('[data-cat-mover]', chip).forEach((botao) => {
                    if (limites[botao.dataset.catMover]) botao.setAttribute('aria-disabled', 'true');
                    else botao.removeAttribute('aria-disabled');
                });
            });
        });
    };

    // Mantém os badges de contagem (.cch-count) em dia com as colunas — e, com eles,
    // quais botões de mover estão nas pontas (toda mudança de coluna passa por aqui).
    const updateCounts = () => {
        $$('.cat-drop', cols).forEach((drop) => {
            const badge = cols.querySelector(`.cch-count[data-count-for="${drop.dataset.type}"]`);
            if (badge) badge.textContent = $$('.cat-chip', drop).length;
        });
        atualizarBotoesDeMover();
    };
    atualizarBotoesDeMover();

    // Fim da lista de chips: os avisos (.cat-drop-empty / .cat-drop-hint) ficam
    // sempre DEPOIS deles, para o CSS esconder o "vazio" assim que a coluna
    // ganha um chip.
    const marcadorFinal = (drop) => drop.querySelector('.cat-drop-empty, .cat-drop-hint');

    /**
     * Move o chip para onde o ponteiro está, AO VIVO (durante o arraste).
     * É esta troca de lugar que serve de feedback visual da reordenação — sem
     * ela seria preciso CSS novo para desenhar a "linha de inserção", e o CSS
     * não é desta rodada.
     */
    const posicionar = (drop, chip, y) => {
        const alvo = $$('.cat-chip', drop).find((outro) => {
            if (outro === chip) return false;
            const r = outro.getBoundingClientRect();
            return y < r.top + r.height / 2; // ponteiro acima da metade: entra antes dele
        }) ?? marcadorFinal(drop);

        if (chip.parentElement !== drop || chip.nextElementSibling !== alvo) {
            drop.insertBefore(chip, alvo);
        }
    };

    /** Devolve o chip exatamente para onde ele estava antes do arraste. */
    const restaurar = (estado) => {
        if (!estado) return;
        estado.drop.insertBefore(estado.chip, estado.next ?? marcadorFinal(estado.drop));
        estado.chip.dataset.type = estado.type;
        updateCounts();
    };

    $$('.cat-chip', cols).forEach((chip) => {
        chip.addEventListener('dragstart', (e) => {
            dragged = chip;
            soltou = false;
            origem = {
                chip,
                drop: chip.closest('.cat-drop'),
                next: chip.nextElementSibling,
                type: chip.dataset.type,
            };
            e.dataTransfer.effectAllowed = 'move';
            try { e.dataTransfer.setData('text/plain', chip.dataset.id); } catch { /* IE/edge cases */ }
            // Adia a classe para o "fantasma" do drag não sair já apagado
            setTimeout(() => chip.classList.add('dragging'), 0);
        });
        chip.addEventListener('dragend', () => {
            chip.classList.remove('dragging');
            $$('.cat-drop', cols).forEach((d) => d.classList.remove('over'));
            // Soltou fora de qualquer coluna: o `posicionar` do dragover já
            // tinha mexido no chip, e o servidor nunca soube de nada — desfaz.
            if (!soltou) restaurar(origem);
            dragged = null;
        });
    });

    $$('.cat-drop', cols).forEach((drop) => {
        drop.addEventListener('dragover', (e) => {
            if (!dragged) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            drop.classList.add('over');
            posicionar(drop, dragged, e.clientY);
        });
        drop.addEventListener('dragleave', (e) => {
            if (!drop.contains(e.relatedTarget)) drop.classList.remove('over');
        });
        drop.addEventListener('drop', (e) => {
            e.preventDefault();
            drop.classList.remove('over');
            if (!dragged) return;
            soltou = true;
            const chip = dragged;
            const estado = origem; // cópia síncrona: o dragend já vai ter limpado
            posicionar(drop, chip, e.clientY); // posição final, exata
            persistir(chip, drop, estado);
        });
    });

    /**
     * PATCH categories.update — é ele que troca o TIPO da categoria.
     *
     * Devolve `null` se o servidor aceitou; senão, o texto a mostrar. A recusa mais comum
     * aqui é de propósito (categoria com lançamentos não muda de tipo), e só o servidor
     * sabe dizer por quê e o que fazer — por isso a mensagem vem dele.
     */
    async function salvarTipo(chip, tipo) {
        let res;
        try {
            res = await fetch(chip.dataset.updateUrl, {
                method: 'PATCH',
                headers: cabecalhosJson(),
                body: JSON.stringify({
                    name: chip.dataset.name,
                    type: tipo,
                    color: chip.dataset.color || null,
                    icon: chip.dataset.icon || null,
                }),
            });
        } catch {
            return MSG_SEM_CONEXAO;
        }

        return res.ok ? null : mensagemDeErro(res, 'Não foi possível mover a categoria. Tente novamente.');
    }

    /**
     * PATCH categories.ordenar — manda os ids da coluna na ordem em que estão AGORA.
     * Aceito, essa passa a ser a ordem confirmada da coluna (`ordemSalva`).
     */
    async function salvarOrdem(drop) {
        if (!ordenarUrl) return false;

        const ids = idsDe(drop);

        try {
            const res = await fetch(ordenarUrl, {
                method: 'PATCH',
                headers: cabecalhosJson(),
                body: JSON.stringify({ ids }),
            });
            if (res.ok) ordemSalva.set(drop, ids);
            return res.ok;
        } catch {
            return false;
        }
    }

    async function persistir(chip, drop, estado) {
        if (!estado) return;

        const trocouDeColuna = drop.dataset.type !== estado.type;

        // Soltou de volta exatamente onde estava: nada a gravar.
        if (!trocouDeColuna && chip.parentElement === estado.drop && chip.nextElementSibling === estado.next) {
            return;
        }

        // O modal de edição lê o tipo daqui — sem isto, editar um chip recém
        // arrastado abriria o modal na coluna errada.
        chip.dataset.type = drop.dataset.type;
        updateCounts();

        if (trocouDeColuna) {
            const erro = await salvarTipo(chip, drop.dataset.type);
            if (erro) {
                restaurar(estado);
                // Dado do usuário (o nome da categoria) pode vir na mensagem: alert()
                // mostra texto puro, nunca interpreta HTML.
                window.alert(erro);
                return;
            }
        }

        if (await salvarOrdem(drop)) {
            // O arraste também conta onde a categoria foi parar — soltar em silêncio
            // deixava quem usa leitor de tela sem saber se deu certo.
            anunciarPosicao(chip);
            return;
        }

        if (trocouDeColuna) {
            // O tipo JÁ mudou no servidor: desfazer o visual seria mentira.
            // Recarrega para a tela mostrar exatamente o que está gravado.
            window.alert('A categoria mudou de coluna, mas a nova ordem não pôde ser salva.');
            if (typeof window.smPjaxReload === 'function') window.smPjaxReload();
            else window.location.reload();
            return;
        }

        restaurar(estado);
        window.alert('Não foi possível salvar a nova ordem. Tente novamente.');
    }

    /* ---- Reordenar pelos botões "mover para cima/baixo" (sem arrastar) ---- */

    /**
     * Troca o chip de lugar com o vizinho de cima (`-1`) ou de baixo (`1`).
     *
     * Quem se move é o VIZINHO, não o chip: tirar e recolocar o elemento que contém o
     * botão focado joga o foco no <body>, e quem usa teclado teria de caçar de novo o
     * botão a cada passo. O resultado na tela é o mesmo.
     */
    const trocarComVizinho = (chip, direcao) => {
        const drop = chip.parentElement;
        const chips = $$('.cat-chip', drop);
        const vizinho = chips[chips.indexOf(chip) + direcao];
        if (!vizinho) return false;

        if (direcao < 0) drop.insertBefore(vizinho, chip.nextElementSibling);
        else drop.insertBefore(vizinho, chip);
        return true;
    };

    /** Devolve a coluna à ordem `ids` (a última confirmada pelo servidor). */
    const restaurarOrdem = (drop, ids) => {
        // Aqui os chips SAEM do lugar (não há vizinho a mover): o foco é guardado e
        // devolvido, senão cairia no <body> junto com o aviso de erro.
        const focado = document.activeElement;
        const marcador = marcadorFinal(drop);
        ids.forEach((id) => {
            const chip = drop.querySelector(`.cat-chip[data-id="${id}"]`);
            if (chip) drop.insertBefore(chip, marcador);
        });
        updateCounts();
        if (focado && drop.contains(focado) && document.activeElement !== focado) focado.focus();
    };

    // Um PATCH por vez, na ordem dos cliques: cada um lê a ordem da tela na hora de
    // sair, então o último a chegar ao servidor é sempre o mais recente — sem a fila,
    // dois cliques rápidos podiam gravar a ordem do primeiro por cima da do segundo.
    let filaDeOrdem = Promise.resolve();

    const gravarOrdemEmFila = (drop) => {
        filaDeOrdem = filaDeOrdem.then(async () => {
            if (await salvarOrdem(drop)) return;
            // Recusou ou sem rede: a tela volta ao que está gravado, e a pessoa fica
            // sabendo — mesma mensagem da reordenação por arraste.
            restaurarOrdem(drop, ordemSalva.get(drop) || []);
            window.alert('Não foi possível salvar a nova ordem. Tente novamente.');
        });
        return filaDeOrdem;
    };

    // Delegado no #catCols: um ouvinte só para todos os chips, e o pjax troca o
    // #catCols inteiro junto com a tela, então não há como empilhar ouvintes.
    cols.addEventListener('click', (e) => {
        const botao = e.target.closest('[data-cat-mover]');
        if (!botao || !cols.contains(botao)) return;
        e.preventDefault();

        const chip = botao.closest('.cat-chip');
        const drop = chip?.closest('.cat-drop');
        if (!chip || !drop) return;

        const direcao = Number(botao.dataset.catMover) < 0 ? -1 : 1;
        const tinhaFoco = document.activeElement === botao;

        // Na ponta: nada a mover, mas quem aperta ouve por quê.
        if (botao.getAttribute('aria-disabled') === 'true' || !trocarComVizinho(chip, direcao)) {
            anunciar(`${chip.dataset.name || 'Categoria'} já está ${direcao < 0 ? 'no topo' : 'no fim'} de ${nomeDaColuna(drop)}.`);
            return;
        }

        updateCounts();
        anunciarPosicao(chip);
        // O foco continua no botão apertado (o chip não saiu do lugar no DOM); isto é
        // só a garantia para o navegador que o tirar mesmo assim.
        if (tinhaFoco && document.activeElement !== botao) botao.focus();
        gravarOrdemEmFila(drop);
    });
}

/* ============ Modal de criar/editar categoria ============ */
// UM modal compartilhado (#catModal) que serve os dois casos: quem clica
// preenche os campos a partir dos data-* do chip. Envia por AJAX com
// Accept: application/json — o CategoryController responde {ok:true} em vez do
// redirect. Sucesso fecha o modal e recarrega a listagem por pjax.
//
// Os gatilhos ([data-cat-open]) mantêm o href para as telas cheias: sem JS este
// arquivo não roda, o modal fica invisível e os links navegam normalmente.
function initCategoryModal() {
    const modal = document.getElementById('catModal');
    if (!modal) return;

    const card = modal.querySelector('.modal');
    const form = modal.querySelector('[data-cat-form]');
    if (!form) return;

    const storeUrl = form.getAttribute('action'); // categories.store (criar)
    const titulo = modal.querySelector('[data-cat-title]');
    const sub = modal.querySelector('[data-cat-sub]');
    const nome = modal.querySelector('#cm-name');
    const iconPicker = modal.querySelector('[data-cat-icons]');
    const colorPicker = modal.querySelector('[data-cat-colors]');
    const lockedHint = modal.querySelector('[data-cat-locked-hint]');
    const errorBox = modal.querySelector('[data-cat-error]');
    const errorMsg = modal.querySelector('[data-cat-error-msg]');
    const saveBtn = modal.querySelector('[data-cat-save]');
    const radiosTipo = $$('input[name="type"]', form);

    let editandoUrl = null; // null = criando; URL = editando (PUT)

    const hideError = () => { if (errorBox) errorBox.hidden = true; };
    const setSaving = (on) => {
        if (!saveBtn) return;
        saveBtn.classList.toggle('is-loading', on);
        saveBtn.disabled = on;
    };
    const shake = () => {
        if (!card) return;
        card.classList.remove('shake');
        void card.offsetWidth; // reflow p/ reiniciar a animação
        card.classList.add('shake');
    };
    const showError = (msg) => {
        // ⚠️ textContent, nunca innerHTML: a mensagem pode ecoar o nome digitado.
        if (errorMsg) errorMsg.textContent = msg;
        if (errorBox) errorBox.hidden = false;
        shake();
    };

    // Espelha o tipo escolhido no form (é o [data-type] que move a pílula do toggle).
    const applyType = () => {
        const marcado = form.querySelector('input[name="type"]:checked');
        const tipo = marcado ? marcado.value : TIPO_PADRAO;
        form.dataset.type = tipo;
        if (card) card.dataset.type = tipo;
    };
    radiosTipo.forEach((r) => r.addEventListener('change', applyType));

    // Só 'expense' tira do padrão: quem chama sem tipo (o botão "Nova
    // categoria" do topo) cai em receita.
    const marcarTipo = (tipo) => {
        const alvo = form.querySelector(`input[name="type"][value="${tipo === 'expense' ? 'expense' : 'income'}"]`);
        if (alvo) alvo.checked = true;
        applyType();
    };

    // Trava o tipo das categorias fixas — a mesma regra que o servidor aplica
    // no update. Como input desabilitado não entra no FormData, o `type` é
    // sempre reposto no envio a partir de form.dataset.type.
    const travarTipo = (travado) => {
        radiosTipo.forEach((r) => { r.disabled = travado; });
        if (lockedHint) lockedHint.hidden = !travado;
    };

    /**
     * Marca a opção `valor` no picker. Se ela não estiver na paleta (categoria
     * criada antes de a lista mudar, ou por outro caminho), a opção é CRIADA na
     * hora — senão nenhum radio ficaria marcado, o campo não iria no envio e o
     * ícone/cor da categoria seria apagado silenciosamente ao salvar.
     */
    const marcarOpcao = (picker, campo, valor) => {
        if (!picker) return;
        const inputs = $$('input', picker);
        const existente = inputs.find((i) => i.value === (valor ?? ''));
        if (existente) { existente.checked = true; return; }

        const id = `cm-${campo}-extra`;
        let input = picker.querySelector(`#${id}`);
        let label = picker.querySelector(`label[for="${id}"]`);
        if (!input) {
            input = document.createElement('input');
            input.type = 'radio';
            input.name = campo;
            input.id = id;
            label = document.createElement('label');
            label.setAttribute('for', id);
            picker.append(input, label);
        }
        input.value = valor;
        if (campo === 'icon') {
            label.textContent = valor; // dado do usuário: textContent, nunca innerHTML
        } else if (/^#[0-9A-Fa-f]{6}$/.test(valor)) {
            label.style.background = valor;
            // A bolinha não tem texto: sem este nome o leitor de tela anunciaria só
            // "botão de opção" (as da paleta levam o nome da cor, vindo do Blade).
            label.title = 'Cor atual da categoria';
            let nomeDaCor = label.querySelector('.sr-only');
            if (!nomeDaCor) {
                nomeDaCor = document.createElement('span');
                nomeDaCor.className = 'sr-only';
                label.append(nomeDaCor);
            }
            nomeDaCor.textContent = 'Cor atual da categoria';
        }
        input.checked = true;
    };

    // Abre como DIÁLOGO (sm/dialogo.js): foco no Nome, resto da página inerte, Tab
    // preso e Esc; ao fechar, o foco volta para quem abriu (o "Nova categoria", o
    // "Criar agora" da coluna vazia ou o lápis do chip).
    const open = (gatilho = null) => {
        hideError();
        setSaving(false);
        abrirDialogo(modal, { foco: nome, retorno: gatilho });
    };
    const close = () => fecharDialogo(modal);

    const abrirCriacao = (tipo, gatilho = null) => {
        editandoUrl = null;
        form.setAttribute('action', storeUrl);
        if (titulo) titulo.textContent = 'Nova categoria';
        if (sub) sub.textContent = 'Para organizar receitas e despesas';
        if (nome) nome.value = '';
        travarTipo(false);
        marcarTipo(tipo);
        marcarOpcao(iconPicker, 'icon', '✨');
        marcarOpcao(colorPicker, 'color', '');
        open(gatilho);
    };

    const abrirEdicao = (chip, gatilho = null) => {
        editandoUrl = chip.dataset.updateUrl;
        form.setAttribute('action', editandoUrl);
        if (titulo) titulo.textContent = 'Editar categoria';
        // Nome da categoria é dado do usuário — textContent.
        if (sub) sub.textContent = `Atualize os dados de "${chip.dataset.name || ''}"`;
        if (nome) nome.value = chip.dataset.name || '';
        travarTipo(chip.dataset.locked === '1');
        marcarTipo(chip.dataset.type);
        marcarOpcao(iconPicker, 'icon', chip.dataset.icon || '✨');
        marcarOpcao(colorPicker, 'color', chip.dataset.color || '');
        open(gatilho);
    };

    // ---- Gatilhos: "Nova categoria", "Criar agora" (coluna vazia) e o lápis de cada chip.
    // Bind direto (não delegado): este init roda de novo a cada troca de #content
    // pelo pjax, então os elementos são sempre os novos.
    $$('[data-cat-open]').forEach((gatilho) => {
        gatilho.addEventListener('click', (e) => {
            // Ctrl/Cmd/clique do meio: deixa o navegador abrir a tela cheia em outra aba.
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1) return;
            e.preventDefault();
            if (gatilho.dataset.catOpen === 'edit') {
                const chip = gatilho.closest('.cat-chip');
                if (chip) abrirEdicao(chip, gatilho);
                return;
            }
            abrirCriacao(gatilho.dataset.catType, gatilho);
        });
    });

    // ---- Fechar: véu, X e "Cancelar". O Esc é do utilitário de diálogo — o ouvinte
    // antigo, preso ao documento, fechava por fora dele e o foco caía no <body>.
    modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
    $$('[data-cat-close]', modal).forEach((b) => b.addEventListener('click', close));
    if (card) {
        card.addEventListener('animationend', (e) => {
            if (e.animationName === 'sm-shake') card.classList.remove('shake');
        });
    }

    // ---- Envio por AJAX.
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        hideError();
        setSaving(true);

        const payload = new FormData(form);
        // O radio de tipo pode estar desabilitado (categoria fixa) e, nesse
        // caso, não entra no FormData — o servidor recusaria por "tipo obrigatório".
        payload.set('type', form.dataset.type || TIPO_PADRAO);
        // Laravel lê o verbo do _method: multipart/form-data com PUT real não é
        // parseado pelo PHP, então o envio é sempre POST.
        if (editandoUrl) payload.set('_method', 'PUT');

        let resp;
        try {
            resp = await fetch(form.getAttribute('action'), {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: payload,
                credentials: 'same-origin',
            });
        } catch (_) {
            setSaving(false);
            showError(MSG_SEM_CONEXAO);
            return;
        }

        if (resp.ok) {
            setSaving(false);
            // FECHA antes de recarregar: o reload é por pjax e troca só o
            // #content. Um modal aberto ficaria por cima do resultado.
            close();
            if (typeof window.smPjaxReload === 'function') window.smPjaxReload();
            else window.location.reload();
            return;
        }

        setSaving(false);
        // Inclui a recusa de trocar o tipo de categoria com lançamentos, que chega como
        // erro do campo `type` (o toggle de tipo do modal cai na mesma trava do arraste).
        showError(await mensagemDeErro(resp, 'Não foi possível salvar. Confira os campos e tente de novo.'));
    });
}
