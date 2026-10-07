/**
 * "Recuperar senha", depois do pedido (out/2026): o "Reenviar" fica desligado e conta os
 * segundos até poder pedir de novo; ao zerar, liga e mostra o "Usar outro e-mail".
 *
 * Só conforto: quem decide é o servidor (`PasswordResetLinkController`, por sessão e por rede).
 * O número inicial vem dele (`data-espera`, em segundos), e a contagem usa o relógio — uma aba
 * em segundo plano, que atrasa os timers, não atrasa o fim da espera.
 */
export function initRecuperarSenha(raiz = document) {
    const form = raiz.querySelector('form[data-reenviar-link]');
    if (!form) return null;

    const botao = form.querySelector('[data-reenviar-botao]');
    const rotulo = form.querySelector('[data-reenviar-rotulo]');
    const anuncio = form.querySelector('[data-reenviar-anuncio]');
    const outro = raiz.querySelector('[data-outro-email]');
    const segundos = Math.max(0, parseInt(form.dataset.espera, 10) || 0);
    const fim = Date.now() + segundos * 1000;

    const liberar = () => {
        botao.disabled = false;
        rotulo.textContent = 'Reenviar o link';
        if (outro) outro.hidden = false;
        // Um anúncio só, no fim — a contagem segundo a segundo seria ruído no leitor de tela.
        if (anuncio && segundos > 0) anuncio.textContent = 'Você já pode reenviar o link.';
    };

    if (segundos === 0) {
        liberar();
        return null;
    }

    botao.disabled = true;
    const passo = () => {
        const falta = Math.ceil((fim - Date.now()) / 1000);
        if (falta <= 0) {
            clearInterval(relogio);
            liberar();
            return;
        }
        const texto = `Reenviar em ${falta} s`;
        if (rotulo.textContent !== texto) rotulo.textContent = texto;
    };
    const relogio = setInterval(passo, 250);
    passo();

    return relogio;
}
