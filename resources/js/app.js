import './bootstrap';

import { initTheme } from './sm/theme';
import { initShell } from './sm/shell';
import { initDashboard } from './sm/dashboard';
import { initCategories } from './sm/categories';
import { initAuth } from './sm/auth';
import { initSecurity } from './sm/security';
import { initMetas } from './sm/metas';
import { initInvestimentos } from './sm/investimentos';
import { initFaturas } from './sm/faturas';
import { initMoney } from './sm/money';
import { initLaunch } from './sm/launch';
import { initFunding } from './sm/funding';
import { initNav } from './sm/nav';
import { initPwa } from './sm/pwa';
import { initOfflineQueue } from './sm/offline-queue';
import { initDialogos, liberarDialogosOrfaos } from './sm/dialogo';
import { initConfirmar } from './sm/confirmar';

// Utilitário de diálogo (sm/dialogo.js): o teclado dos modais (Esc e Tab preso) e a
// ponte `window.smDialogo` dos scripts inline das views. Liga já na AVALIAÇÃO do
// módulo, e não dentro do init(): os scripts inline que reabrem um modal com erro de
// validação esperam o DOMContentLoaded, e o módulo roda antes dele — assim a ponte
// sempre existe quando eles a procuram.
initDialogos();

// Módulos que agem sobre o CONTEÚDO (#content). Rodam na 1ª carga E de novo a
// cada troca por pjax (navegação sem reload). Cada um checa o próprio root no DOM,
// então re-rodar num conteúdo novo é seguro (não duplica binds nos elementos antigos).
function initContent() {
    // Primeiro de tudo: um modal do #content que estava aberto saiu junto com a tela
    // velha, e a página que ele deixou inerte (topbar, sidebar, barra de baixo) tem de
    // voltar a responder antes de qualquer outra coisa. O Lançar, que vive no shell e
    // sobrevive à troca, continua aberto e continua dono da página.
    liberarDialogosOrfaos();
    initDashboard();
    initCategories();
    initMetas();
    initInvestimentos();
    initFaturas();
    initSecurity();
    initMoney();
}

// Inicialização do shell (sidebar/topbar/modal/PWA) — roda UMA vez; esses
// elementos persistem entre navegações pjax.
function init() {
    initTheme();
    initShell();
    initAuth();
    // "Tem certeza?" dos formulários de excluir (`form[data-confirmar]`): um ouvinte
    // só, no documento, que vale também para o conteúdo trocado pelo pjax. Substitui os
    // `onsubmit="return confirm(...)"`, que a CSP bloqueia.
    initConfirmar();
    // O modal de escolha de fonte vive no shell (serve os 3 formulários), então
    // é ligado uma vez só, antes de quem o consome.
    initFunding();
    initLaunch();
    initPwa();
    initOfflineQueue();

    initContent();          // primeira renderização
    initNav(initContent);   // pjax: reusa initContent após cada troca de #content
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
