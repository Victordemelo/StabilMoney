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
import { initNav } from './sm/nav';
import { initPwa } from './sm/pwa';
import { initOfflineQueue } from './sm/offline-queue';

// Módulos que agem sobre o CONTEÚDO (#content). Rodam na 1ª carga E de novo a
// cada troca por pjax (navegação sem reload). Cada um checa o próprio root no DOM,
// então re-rodar num conteúdo novo é seguro (não duplica binds nos elementos antigos).
function initContent() {
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
