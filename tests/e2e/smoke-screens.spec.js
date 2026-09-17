import { test, expect } from '@playwright/test';
import fs from 'node:fs';

/**
 * Smoke visual: registra um usuário, cria dados mínimos e visita TODAS as telas,
 * tirando screenshot e coletando erros (HTTP 5xx + console). Ferramenta de
 * investigação — não faz parte da suíte PHPUnit. Rodar: npx playwright test smoke-screens
 */
const SHOTS = 'test-results/screens';

const MAILPIT = process.env.E2E_MAILPIT_URL || 'http://localhost:8026';

/**
 * Cadastra um usuário e devolve a página JÁ DENTRO do app.
 *
 * Por que isto não é só "preencher e esperar `/`": o app inteiro roda sob o middleware
 * `verified`. Quando o SMTP entrega, o usuário novo nasce POR CONFIRMAR e é desviado para
 * `/verify-email` — e é assim que TEM de ser. Desligar a verificação para o e2e ficar
 * verde seria trocar a prova pelo verde: o buraco que o middleware fecha (cadastrar-se
 * com o e-mail de outra pessoa) deixaria de ser exercitado justamente na única suíte que
 * roda o app de verdade.
 *
 * Então o helper aceita os DOIS desfechos legítimos do cadastro e leva os dois até dentro
 * do app pelo caminho honesto:
 *
 *  - caiu em `/`          → o app não exigiu confirmação. É o que acontece quando o mailer
 *                           não entrega, ou quando o SMTP recusa o destinatário: aí o
 *                           usuário nasce verificado (ver RegisteredUserController), senão
 *                           ficaria trancado sem link que o destrave.
 *  - caiu em `/verify-email` → o link SAIU. O helper vai buscá-lo na caixa de e-mail de dev
 *                           (Mailpit, http://localhost:8026) e clica — percorrendo o fluxo
 *                           de verificação real, ponta a ponta.
 */
async function cadastrarEEntrar(page, { nome, email, senha = 'SenhaForte#2026' }) {
    await page.goto('/register');
    await page.fill('#name', nome);
    await page.fill('#email', email);
    await page.fill('#password', senha);
    // Checkbox de termos é estilizado (input escondido fora da viewport): marca via JS.
    await page.evaluate(() => {
        const cb = document.querySelector('#terms');
        cb.checked = true;
        cb.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await page.click('button[type=submit]');

    await page.waitForURL((url) => ['/', '/verify-email'].includes(url.pathname));

    if (new URL(page.url()).pathname === '/verify-email') {
        await page.goto(await linkDeVerificacao(email));
    }

    expect(new URL(page.url()).pathname, 'o cadastro tinha de terminar dentro do app').toBe('/');
}

/** Pega na caixa do Mailpit o link de confirmação enviado para `email`. */
async function linkDeVerificacao(email) {
    const busca = await fetch(
        `${MAILPIT}/api/v1/search?query=${encodeURIComponent(`to:${email}`)}&limit=1`
    ).catch(() => null);

    const id = busca && busca.ok ? (await busca.json()).messages?.[0]?.ID : null;

    if (!id) {
        throw new Error(
            `O app exigiu confirmação de e-mail, mas não há mensagem para ${email} em ${MAILPIT}.\n` +
            'Pré-requisito do e2e: o mailer do ambiente precisa ser o Mailpit do docker compose ' +
            '(MAIL_HOST=mailpit, MAIL_PORT=1025, MAIL_SCHEME=smtp) — ou um que não entregue.'
        );
    }

    const mensagem = await (await fetch(`${MAILPIT}/api/v1/message/${id}`)).json();
    const link = (mensagem.Text || '').match(/https?:\/\/[^\s"<>]+\/verify-email\/[^\s"<>]+/);

    if (!link) throw new Error('O e-mail de verificação chegou sem link clicável.');

    return link[0];
}

test('smoke: todas as telas renderizam sem erro', async ({ page }) => {
    test.setTimeout(180_000); // visita ~14 telas com screenshot fullPage
    fs.mkdirSync(SHOTS, { recursive: true });
    const problems = [];
    let consoleErrors = [];
    page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });
    page.on('pageerror', (e) => consoleErrors.push('pageerror: ' + e.message));

    const stamp = Date.now();

    // Cadastro (gera categorias padrão; loga).
    await cadastrarEEntrar(page, { nome: 'Smoke Test', email: `smoke+${stamp}@stabilmoney.test` });

    // Uma conta + uma transação (pra telas com dados renderizarem).
    // `#name-novo`: os `id` do formulário de conta levam sufixo, porque a lista repete o
    // mesmo formulário uma vez por conta (um modal cada).
    await page.goto('/accounts/create');
    await page.fill('#name-novo', 'Conta Smoke');
    // Saldo inicial: sem ele a despesa abaixo estoura o disponível e o servidor devolve 409
    // ("de onde sai esse dinheiro?") em vez de gravar — a tela ficaria no formulário.
    await page.fill('#initial_balance-novo', '1000,00');
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
        ['termos', '/termos'],
        ['privacidade', '/privacidade'],
    ]) {
        const resp = await page.goto(path, { waitUntil: 'load', timeout: 20_000 });
        await page.waitForTimeout(300);
        await page.screenshot({ path: `${SHOTS}/pub-${name}.png`, fullPage: true });
        expect(resp.status(), path).toBeLessThan(400);
    }
});
