import { test, expect } from '@playwright/test';

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
    await cadastrarEEntrar(page, { nome: 'Teste E2E', email });

    // 2. Cria uma conta (o lançamento precisa de pelo menos uma).
    // Os `id` do formulário levam sufixo (`-novo` na página cheia, `-c<id>` nos modais da
    // lista): a lista repete este mesmo formulário uma vez por conta, e id repetido faria
    // o `<label for>` focar o campo do vizinho.
    await page.goto('/accounts/create');
    await page.fill('#name-novo', 'Carteira E2E');
    // Sem banco pré-selecionado desde 23/09/2026: o select é obrigatório, e sem esta
    // escolha o navegador segura o envio (`required`) antes de chegar ao servidor.
    await page.selectOption('#bank-novo', 'nubank');
    // O saldo inicial não é enfeite: a conta nasceria com R$ 0,00 e QUALQUER despesa cairia
    // no 409 "de onde sai esse dinheiro?" — e desde 06/08/2026 a fila offline NÃO escolhe a
    // fonte sozinha, ela retém o item e pede a decisão do usuário. Sem saldo, o que este
    // spec mediria seria o fluxo de escolha de fonte, não a sincronização da fila.
    // "1000,00" dá R$ 1.000,00 com ou sem o money.js no ar (ele lê só os dígitos).
    await page.fill('#initial_balance-novo', '1000,00');
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
