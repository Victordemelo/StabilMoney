/**
 * Mostrar/ocultar senha — o "olho" ao lado do campo (out/2026: estendido do card de senha das
 * Configurações para os modais de Família, a pedido do Victor).
 *
 * Marcação (o CSS é o `.input-pw`/`.pw-toggle` do design-system):
 *
 *     <div class="input-pw">
 *         <input type="password" id="x" ...>
 *         <button type="button" class="pw-toggle" data-toggle="x" aria-label="Mostrar senha">…</button>
 *     </div>
 *
 * Um ouvinte só, DELEGADO no document: vale para o conteúdo trocado pelo pjax e para os N
 * modais de editar dependente sem ligar botão por botão. Ligar duas vezes faria cada clique
 * alternar duas vezes (e o campo não mudar) — por isso a trava `ligado`.
 *
 * Ao enviar o formulário, o campo volta a ser de senha: o gerenciador de senhas do navegador
 * só oferece salvar campo `type="password"`, e a tela seguinte (erro de validação, modal
 * reaberto) não deve nascer com a senha à vista.
 */

let ligado = false;

/** Alterna o campo entre oculto e visível e atualiza o botão. */
export function alternarSenha(botao, campo, mostrar = campo.type === 'password') {
    campo.type = mostrar ? 'text' : 'password';
    botao.classList.toggle('on', mostrar);
    botao.setAttribute('aria-label', mostrar ? 'Ocultar senha' : 'Mostrar senha');
    botao.setAttribute('aria-pressed', mostrar ? 'true' : 'false');
}

export function initMostrarSenha() {
    if (ligado) return;
    ligado = true;

    document.addEventListener('click', (e) => {
        const botao = e.target.closest?.('.pw-toggle[data-toggle]');
        if (!botao) return;
        const campo = document.getElementById(botao.dataset.toggle);
        if (!campo) return;
        alternarSenha(botao, campo);
    });

    document.addEventListener('submit', (e) => {
        e.target.querySelectorAll?.('.pw-toggle[data-toggle].on').forEach((botao) => {
            const campo = document.getElementById(botao.dataset.toggle);
            if (campo) alternarSenha(botao, campo, false);
        });
    });
}
