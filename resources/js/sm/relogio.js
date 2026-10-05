/**
 * Relógio da topbar (out/2026): mantém andando a hora que o servidor desenhou, no fuso
 * escolhido em Configurações (`data-fuso`). Vive no shell (o pjax não o troca), então
 * liga UMA vez. Só exibição — nenhuma data de dinheiro sai daqui.
 */
export function formatarHora(data, fuso) {
    try {
        return new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: fuso }).format(data);
    } catch (e) {
        return null; // fuso que o navegador não conhece: fica a hora do servidor
    }
}

export function initRelogio() {
    const relogio = document.querySelector('[data-relogio]');
    const hora = relogio && relogio.querySelector('[data-relogio-hora]');
    if (!hora) return;

    const atualizar = () => {
        const texto = formatarHora(new Date(), relogio.dataset.fuso || 'America/Sao_Paulo');
        if (texto) hora.textContent = texto;
    };
    atualizar();
    setInterval(atualizar, 15000);
}
