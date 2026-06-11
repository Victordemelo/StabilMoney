// Página de categorias (design v2): arrastar um chip entre as colunas
// "Despesas" e "Receitas" troca o tipo da categoria.
//
// HTML5 drag & drop com atualização otimista: o chip muda de coluna na hora
// e um PATCH é enviado para categories.update com TODOS os campos exigidos
// pelo UpdateCategoryRequest (name/type/color/icon). Se a requisição falhar,
// o chip volta para a posição original (rollback).
//
// Só roda na página de categorias (guard pelo #catCols).

const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));

export function initCategories() {
    const cols = document.getElementById('catCols');
    if (!cols) return;

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

        // Otimista: move o chip já, sem esperar o servidor
        insertChip(drop, chip);
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
            updateCounts();
            window.alert('Não foi possível mover a categoria. Tente novamente.');
        }
    }
}
