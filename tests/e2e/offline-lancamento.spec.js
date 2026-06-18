import { test, expect } from '@playwright/test';

/**
 * Fluxo ponta a ponta da fila de lançamentos offline (Fase 2 do PWA).
 *
 * Registra um usuário novo, cria uma conta, fica OFFLINE, lança, volta ONLINE
 * e confere que o lançamento sincronizou EXATAMENTE UMA VEZ (dedupe por
 * client_uuid). Cobre o que o PHPUnit não alcança: IndexedDB + evento online.
 *
 * Pré-requisito: app de dev no ar (http://localhost:8001) com assets buildados.
 * Cria dados reais no banco de dev (usuário com e-mail único por execução).
 */
test('lançamento offline entra na fila e sincroniza sem duplicar', async ({ page, context }) => {
    const stamp = Date.now();
    const email = `e2e+${stamp}@stabilmoney.test`;
    const descricao = `E2E offline ${stamp}`;

    // 1. Cadastro (cria titular + categorias padrão; loga automaticamente).
    await page.goto('/register');
    await page.fill('#name', 'Teste E2E');
    await page.fill('#email', email);
    await page.fill('#password', 'SenhaForte#2026');
    // Checkbox de termos é estilizado (input escondido fora da viewport): marca via JS.
    await page.evaluate(() => {
        const cb = document.querySelector('#terms');
        cb.checked = true;
        cb.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await page.click('button[type=submit]');
    await page.waitForURL(/localhost:8001\/$/);

    // 2. Cria uma conta (o lançamento precisa de pelo menos uma).
    await page.goto('/accounts/create');
    await page.fill('#name', 'Carteira E2E');
    await page.click('.form-card button[type=submit]'); // escopa ao form (a sidebar tem o botão Sair)
    await expect(page).toHaveURL(/\/accounts$/);

    // 3. Abre o formulário de novo lançamento (online; o SW cacheia o form).
    await page.goto('/transactions/create');
    await expect(page.locator('form[data-offline-queue]')).toBeVisible();

    // 4. Fica OFFLINE e lança.
    await context.setOffline(true);
    await page.fill('#amount', '42,50');
    await page.fill('#description', descricao);
    await page.click('.form-card button[type=submit]');

    // 5. Espera-se: ficou na fila — o selo de pendente aparece.
    await expect(page.locator('#sm-offline-badge')).toBeVisible({ timeout: 5_000 });

    // E o IndexedDB tem exatamente 1 item pendente.
    const pendentes = await page.evaluate(() => new Promise((resolve) => {
        const req = indexedDB.open('sm-offline', 1);
        req.onsuccess = () => {
            const tx = req.result.transaction('lancamentos', 'readonly');
            tx.objectStore('lancamentos').getAll().onsuccess = (e) => resolve(e.target.result.length);
        };
        req.onerror = () => resolve(-1);
    }));
    expect(pendentes).toBe(1);

    // 6. Volta ONLINE → sincroniza sozinho (o selo some).
    await context.setOffline(false);
    await expect(page.locator('#sm-offline-badge')).toBeHidden({ timeout: 15_000 });

    // 7. O lançamento aparece na lista EXATAMENTE UMA VEZ (dedupe).
    await page.goto('/transactions');
    await expect(page.getByText(descricao)).toHaveCount(1);
});
