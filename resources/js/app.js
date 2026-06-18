import './bootstrap';

import { initTheme } from './sm/theme';
import { initShell } from './sm/shell';
import { initDashboard } from './sm/dashboard';
import { initCategories } from './sm/categories';
import { initAuth } from './sm/auth';
import { initSecurity } from './sm/security';
import { initPwa } from './sm/pwa';

// Inicialização única do shell + módulos por página (cada módulo decide se
// a página atual lhe diz respeito olhando para o DOM).
function init() {
    initTheme();
    initShell();
    initDashboard();
    initCategories();
    initAuth();
    initSecurity();
    initPwa();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
