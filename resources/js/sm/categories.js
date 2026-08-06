// Página de categorias (design v2). Duas frentes:
//
//  1) Arrastar um chip entre as colunas "Despesas" e "Receitas" troca o tipo
//     da categoria. HTML5 drag & drop com atualização otimista: o chip muda de
//     coluna na hora e um PATCH é enviado para categories.update com TODOS os
//     campos exigidos pelo UpdateCategoryRequest (name/type/color/icon). Se a
//     requisição falhar, o chip volta para a posição original (rollback).
//
//  2) Criar/editar em MODAL, na própria tela (`initCategoryModal`), em vez de
//     navegar para as páginas cheias — que continuam existindo e valendo como
//     fallback sem JS.
//
// Só roda na página de categorias (guard pelo #catCols).

const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));

export function initCategories() {
    const cols = document.getElementById('catCols');
    if (!cols) return;

    initCategoryModal();

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    let dragged = null; // chip sendo arrastado no momento

    // Mantém os badges de contagem (.cch-count) em dia com as colunas
    const updateCounts = () => {
        $$('.cat-drop', cols).forEach((drop) => {
            const badge = cols.querySelector(`.cch-count[data-count-for="${drop.dataset.type}"]`);
            if (badge) badge.textContent = $$('.cat-chip', drop).length;
        });
    };

    // Chips sempre antes dos avisos (.cat-drop-empty / .cat-drop-hint),
    // para o CSS esconder o "vazio" assim que a coluna ganha um chip.
    const insertChip = (drop, chip) => {
        drop.insertBefore(chip, drop.querySelector('.cat-drop-empty, .cat-drop-hint'));
    };

    $$('.cat-chip', cols).forEach((chip) => {
        chip.addEventListener('dragstart', (e) => {
            dragged = chip;
            e.dataTransfer.effectAllowed = 'move';
            try { e.dataTransfer.setData('text/plain', chip.dataset.id); } catch { /* IE/edge cases */ }
            // Adia a classe para o "fantasma" do drag não sair já apagado
            setTimeout(() => chip.classList.add('dragging'), 0);
        });
        chip.addEventListener('dragend', () => {
            chip.classList.remove('dragging');
            dragged = null;
            $$('.cat-drop', cols).forEach((d) => d.classList.remove('over'));
        });
    });

    $$('.cat-drop', cols).forEach((drop) => {
        drop.addEventListener('dragover', (e) => {
            if (!dragged) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            drop.classList.add('over');
        });
        drop.addEventListener('dragleave', (e) => {
            if (!drop.contains(e.relatedTarget)) drop.classList.remove('over');
        });
        drop.addEventListener('drop', (e) => {
            e.preventDefault();
            drop.classList.remove('over');
            const chip = dragged;
            // Soltar na própria coluna não muda nada
            if (!chip || chip.closest('.cat-drop') === drop) return;
            moveChip(chip, drop);
        });
    });

    async function moveChip(chip, drop) {
        // Guarda a posição original para o rollback
        const fromDrop = chip.closest('.cat-drop');
        const nextSibling = chip.nextElementSibling;

        const tipoAnterior = chip.dataset.type;

        // Otimista: move o chip já, sem esperar o servidor
        insertChip(drop, chip);
        // O modal de edição lê o tipo daqui — sem isto, editar um chip recém
        // arrastado abriria o modal na coluna errada.
        chip.dataset.type = drop.dataset.type;
        updateCounts();

        try {
            const res = await fetch(chip.dataset.updateUrl, {
                method: 'PATCH',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    name: chip.dataset.name,
                    type: drop.dataset.type,
                    color: chip.dataset.color || null,
                    icon: chip.dataset.icon || null,
                }),
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
        } catch {
            // Rollback: devolve o chip para onde estava
            fromDrop.insertBefore(chip, nextSibling);
            chip.dataset.type = tipoAnterior;
            updateCounts();
            window.alert('Não foi possível mover a categoria. Tente novamente.');
        }
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
        const tipo = marcado ? marcado.value : 'expense';
        form.dataset.type = tipo;
        if (card) card.dataset.type = tipo;
    };
    radiosTipo.forEach((r) => r.addEventListener('change', applyType));

    const marcarTipo = (tipo) => {
        const alvo = form.querySelector(`input[name="type"][value="${tipo === 'income' ? 'income' : 'expense'}"]`);
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
        payload.set('type', form.dataset.type || 'expense');
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
            showError('Sem conexão com o servidor. Tente de novo em instantes.');
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

        if (resp.status === 419) {
            showError('Sua sessão expirou. Atualize a página e tente de novo.');
            return;
        }
        if (resp.status === 403 || resp.status === 404) {
            showError('Esta categoria não está mais disponível.');
            return;
        }

        let msg = 'Não foi possível salvar. Confira os campos e tente de novo.';
        if (resp.status === 422) {
            const data = await resp.json().catch(() => ({}));
            const errs = data?.errors || {};
            msg = Object.values(errs)[0]?.[0] || data?.message || msg;
        }
        showError(msg);
    });
}
