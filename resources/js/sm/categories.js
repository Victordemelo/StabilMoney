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
// Só roda na página de categorias (guard pelo #catCols).

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

    // Mantém os badges de contagem (.cch-count) em dia com as colunas
    const updateCounts = () => {
        $$('.cat-drop', cols).forEach((drop) => {
            const badge = cols.querySelector(`.cch-count[data-count-for="${drop.dataset.type}"]`);
            if (badge) badge.textContent = $$('.cat-chip', drop).length;
        });
    };

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

    /** PATCH categories.ordenar — manda os ids da coluna na ordem final. */
    async function salvarOrdem(drop) {
        if (!ordenarUrl) return false;

        const ids = $$('.cat-chip', drop)
            .map((c) => Number(c.dataset.id))
            .filter((id) => Number.isFinite(id));

        try {
            const res = await fetch(ordenarUrl, {
                method: 'PATCH',
                headers: cabecalhosJson(),
                body: JSON.stringify({ ids }),
            });
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

        if (await salvarOrdem(drop)) return;

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
            label.title = valor;
        }
        input.checked = true;
    };

    const open = () => {
        hideError();
        setSaving(false);
        modal.classList.add('open');
        if (nome) setTimeout(() => nome.focus(), 80);
    };
    const close = () => modal.classList.remove('open');

    const abrirCriacao = (tipo) => {
        editandoUrl = null;
        form.setAttribute('action', storeUrl);
        if (titulo) titulo.textContent = 'Nova categoria';
        if (sub) sub.textContent = 'Para organizar receitas e despesas';
        if (nome) nome.value = '';
        travarTipo(false);
        marcarTipo(tipo);
        marcarOpcao(iconPicker, 'icon', '✨');
        marcarOpcao(colorPicker, 'color', '');
        open();
    };

    const abrirEdicao = (chip) => {
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
        open();
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
                if (chip) abrirEdicao(chip);
                return;
            }
            abrirCriacao(gatilho.dataset.catType);
        });
    });

    // ---- Fechar: véu, X e "Cancelar".
    modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
    $$('[data-cat-close]', modal).forEach((b) => b.addEventListener('click', close));
    if (card) {
        card.addEventListener('animationend', (e) => {
            if (e.animationName === 'sm-shake') card.classList.remove('shake');
        });
    }
    // Esc: o listener é do documento, que SOBREVIVE ao pjax — a flag evita
    // empilhar um handler novo a cada re-init do conteúdo.
    if (!document.__smCatEscBound) {
        document.__smCatEscBound = true;
        document.addEventListener('keydown', (e) => {
            if (e.key !== 'Escape') return;
            const aberto = document.getElementById('catModal');
            if (aberto) aberto.classList.remove('open');
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
