import { test, expect } from '@playwright/test';
import fs from 'node:fs';

/**
 * Smoke visual: registra um usuário, cria dados mínimos e visita TODAS as telas,
 * tirando screenshot e coletando erros (HTTP 5xx + console). Ferramenta de
 * investigação — não faz parte da suíte PHPUnit. Rodar: npx playwright test smoke-screens
 */
const SHOTS = 'test-results/screens';

test('smoke: todas as telas renderizam sem erro', async ({ page }) => {
    test.setTimeout(180_000); // visita ~14 telas com screenshot fullPage
    fs.mkdirSync(SHOTS, { recursive: true });
    const problems = [];
    let consoleErrors = [];
    page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });
    page.on('pageerror', (e) => consoleErrors.push('pageerror: ' + e.message));

    const stamp = Date.now();

    // Cadastro (gera categorias padrão; loga).
    await page.goto('/register');
    await page.fill('#name', 'Smoke Test');
    await page.fill('#email', `smoke+${stamp}@stabilmoney.test`);
    await page.fill('#password', 'SenhaForte#2026');
    await page.evaluate(() => {
        const c = document.querySelector('#terms');
        c.checked = true; c.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await page.click('button[type=submit]');
    await page.waitForURL(/localhost:8001\/$/);

    // Uma conta + uma transação (pra telas com dados renderizarem).
    await page.goto('/accounts/create');
    await page.fill('#name', 'Conta Smoke');
    await page.click('.form-card button[type=submit]');
    await expect(page).toHaveURL(/\/accounts$/);

    await page.goto('/transactions/create');
    await page.fill('#amount', '100,00');
    await page.fill('#description', 'Despesa smoke');
    await page.click('.form-card button[type=submit]');
    await expect(page).toHaveURL(/\/transactions$/);

    const screens = [
        ['dashboard', '/'],
        ['transactions', '/transactions'],
        ['transactions-create', '/transactions/create'],
        ['accounts', '/accounts'],
        ['accounts-create', '/accounts/create'],
        ['categories', '/categories'],
        ['categories-create', '/categories/create'],
        ['metas', '/metas'],
        ['investimentos', '/investimentos'],
        ['faturas', '/faturas'],
        ['dependentes', '/dependentes'],
        ['configuracoes', '/configuracoes'],
        ['meu-perfil', '/meu-perfil'],
        ['offline', '/offline'],
    ];

    for (const [name, path] of screens) {
        consoleErrors = [];
        let status = 0;
        try {
            const resp = await page.goto(path, { waitUntil: 'load', timeout: 20_000 });
            status = resp ? resp.status() : 0;
        } catch (e) {
            problems.push(`${path} → navegação falhou: ${e.message}`);
            continue;
        }
        await page.waitForTimeout(400); // deixa o JS/gráficos assentarem
        await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: true });
        if (status >= 400) problems.push(`${path} → HTTP ${status}`);
        const real = consoleErrors.filter((t) => !/favicon|sw\.js|ServiceWorker|manifest/i.test(t));
        if (real.length) problems.push(`${path} → console: ${real.slice(0, 3).join(' | ')}`);
    }

    console.log('\n===== RESULTADO DO SMOKE =====');
    console.log(problems.length ? problems.join('\n') : 'OK — nenhuma tela com HTTP>=400 ou erro de console.');
    console.log('Screenshots em: ' + SHOTS);

    // Falha só em erro forte (HTTP). Console fica no relatório acima.
    const httpProblems = problems.filter((p) => /HTTP \d|navegação falhou/.test(p));
    expect(httpProblems, httpProblems.join('\n')).toEqual([]);
});

test('smoke: telas públicas de auth renderizam', async ({ page }) => {
    fs.mkdirSync(SHOTS, { recursive: true });
    for (const [name, path] of [
        ['login', '/login'],
        ['register', '/register'],
        ['forgot-password', '/forgot-password'],
    ]) {
        const resp = await page.goto(path, { waitUntil: 'load', timeout: 20_000 });
        await page.waitForTimeout(300);
        await page.screenshot({ path: `${SHOTS}/pub-${name}.png`, fullPage: true });
        expect(resp.status(), path).toBeLessThan(400);
    }
});
