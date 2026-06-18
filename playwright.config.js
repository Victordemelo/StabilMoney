import { defineConfig, devices } from '@playwright/test';

/**
 * Testes de navegador (e2e) — separados da suíte PHPUnit.
 * Pré-requisito: app de dev no ar (docker compose up) com assets buildados
 * (npm run build) ou `npm run dev` rodando. Rodar com: `npm run e2e`.
 */
export default defineConfig({
    testDir: './tests/e2e',
    timeout: 60_000,
    fullyParallel: false,
    retries: 0,
    reporter: 'list',
    use: {
        baseURL: process.env.E2E_BASE_URL || 'http://localhost:8001',
        headless: true,
        trace: 'on-first-retry',
    },
    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    ],
});
