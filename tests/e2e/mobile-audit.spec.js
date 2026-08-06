import { test, expect } from '@playwright/test';
import fs from 'node:fs';

/**
 * Auditoria de responsividade MOBILE — o app é usado principalmente no celular.
 *
 * Por que automatizado e não "olhar no navegador": os dois defeitos que mais estragam um
 * app mobile são MEDÍVEIS, e olhar não os pega de forma confiável.
 *
 *   1. **Rolagem horizontal.** Um único elemento 20px mais largo que a tela faz a PÁGINA
 *      INTEIRA deslizar de lado. O usuário sente como "quebrado" sem saber dizer o que é,
 *      e no toque isso atrapalha todo gesto de rolar. `scrollWidth > clientWidth` responde
 *      com um número, e dá para apontar QUAL elemento está estourando.
 *   2. **Mancha branca no tema escuro.** Um `background:#fff` cravado (em vez do token)
 *      passa despercebido no claro e vira um retângulo aceso no escuro. Aqui isso é
 *      procurado varrendo os elementos grandes e comparando a cor com o tema ativo.
 *
 * As duas coisas rodam nos dois temas, em 360px (Android pequeno) e 390px (iPhone).
 * Screenshots ficam em test-results/mobile/ para inspeção do que é subjetivo.
 *
 * Pré-requisitos: `docker compose up -d`, `npm run build`, e as credenciais do usuário
 * de dev em SEED_EMAIL / SEED_PASSWORD (o runner lê do .env; nunca ficam no código).
 *
 * Rodar: npx playwright test mobile-audit
 */

const SHOTS = 'test-results/mobile';

const TELAS_AUTENTICADAS = [
    ['dashboard', '/'],
    ['historico', '/transactions'],
    ['lancar', '/transactions/create'],
    ['metodos-pagamento', '/accounts'],
    ['metodo-novo', '/accounts/create'],
    ['categorias', '/categories'],
    ['categoria-nova', '/categories/create'],
    ['pagar-despesas', '/faturas'],
    ['metas', '/metas'],
    ['investimentos', '/investimentos'],
    ['dependentes', '/dependentes'],
    ['config-seguranca', '/configuracoes'],
    ['config-2fa', '/configuracoes/2fa'],
    ['config-conta', '/configuracoes/conta'],
    ['meu-perfil', '/meu-perfil'],
];

const TELAS_PUBLICAS = [
    ['login', '/login'],
    ['cadastro', '/register'],
    ['esqueci-senha', '/forgot-password'],
    ['termos', '/termos'],
    ['privacidade', '/privacidade'],
    ['offline', '/offline'],
];

const VIEWPORTS = [
    { nome: '360', width: 360, height: 740 },
    { nome: '390', width: 390, height: 844 },
];

/**
 * Procura conteúdo que NÃO CABE — medindo recorte, não rolagem.
 *
 * ⚠️ A versão anterior desta função media `scrollWidth` do documento, que é o teste
 * clássico de "a página rola de lado". **Ela dava tudo-certo num app que estava
 * cortando conteúdo.** Motivo: `.content` tem `overflow-x: hidden` e `.card` tem
 * `overflow: hidden`, então nada nunca rola — o que passa da borda é simplesmente
 * recortado, em silêncio. Um botão de excluir que some é indistinguível de um botão
 * que não existe.
 *
 * O teste certo num app que recorta é comparar, **em cada container**, o quanto o
 * conteúdo ocupa (`scrollWidth`) com o quanto cabe (`clientWidth`). Diferença > 1px
 * é conteúdo que o usuário nunca vai ver.
 *
 * Também reporta o `documentElement`, que é o caso em que a página inteira rola de
 * lado — ainda vale medir, só não é mais o único sinal.
 */
async function medirEstouro(page) {
    return page.evaluate(() => {
        const doc = document.documentElement;
        const larguraDaTela = doc.clientWidth;

        const achados = [];

        // 1) A página inteira rola de lado?
        const rolagemDaPagina = doc.scrollWidth - larguraDaTela;

        /**
         * Recortes que são o comportamento CORRETO — ignorar, senão a auditoria vira
         * ruído e ninguém lê a lista no dia em que ela achar algo de verdade.
         *
         *  - `.sr-only`  : mede 1px POR DEFINIÇÃO (texto só para leitor de tela).
         *  - `.auth-visual` : painel decorativo com vídeo em `object-fit: cover`;
         *                     cortar as laterais é o efeito pretendido.
         *  - `.auth-panel`  : é a coluna clara das telas de auth, que são sempre claras.
         */
        const ehEsperado = (el) =>
            el.closest('.sr-only, .auth-visual') !== null
            || el.classList.contains('sr-only')
            || el.classList.contains('auth-visual')
            || el.classList.contains('auth-panel');

        // 2) Conteúdo recortado dentro de qualquer container.
        for (const el of document.querySelectorAll('body *')) {
            const sobra = el.scrollWidth - el.clientWidth;
            // 2px é arredondamento de borda arredondada, não conteúdo perdido.
            if (sobra <= 2 || el.clientWidth === 0) continue;
            if (ehEsperado(el)) continue;

            const estilo = getComputedStyle(el);

            // Texto de uma linha com reticências é truncamento DESENHADO, não defeito:
            // o usuário vê o "…" e sabe que há mais. Sem reticências, o corte é mudo.
            if (estilo.textOverflow === 'ellipsis' && estilo.whiteSpace === 'nowrap') continue;
            // Container que ROLA de propósito (abas, tabela com scroll) não é defeito:
            // ali o usuário alcança o conteúdo deslizando.
            if (['auto', 'scroll'].includes(estilo.overflowX)) continue;

            const r = el.getBoundingClientRect();
            if (r.width === 0 || r.height === 0) continue;

            achados.push({
                seletor: el.tagName.toLowerCase()
                    + (el.id ? '#' + el.id : '')
                    + (typeof el.className === 'string' && el.className
                        ? '.' + el.className.trim().split(/\s+/).slice(0, 3).join('.')
                        : ''),
                cabe: el.clientWidth,
                precisa: el.scrollWidth,
                cortado: sobra,
                recortadoPor: estilo.overflowX, // 'hidden' = some sem aviso
            });
        }

        // Os maiores cortes primeiro — é onde mais coisa desaparece.
        achados.sort((a, b) => b.cortado - a.cortado);

        return { larguraDaTela, rolagemDaPagina, recortes: achados.slice(0, 8) };
    });
}

/**
 * Procura "manchas": áreas grandes com fundo claro enquanto o tema é escuro.
 * É o defeito que o usuário descreve como "parte branca" — quase sempre um `#fff`
 * cravado no lugar do token de superfície.
 */
async function procurarManchasClaras(page) {
    return page.evaluate(() => {
        const claro = (cor) => {
            const m = cor.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?\)/);
            if (!m) return false;
            const [r, g, b, a] = [+m[1], +m[2], +m[3], m[4] === undefined ? 1 : +m[4]];
            if (a < 0.5) return false;
            return (0.299 * r + 0.587 * g + 0.114 * b) > 200; // claro de verdade
        };

        const areaJanela = window.innerWidth * window.innerHeight;
        const achados = [];

        for (const el of document.querySelectorAll('body *')) {
            const r = el.getBoundingClientRect();
            const area = r.width * r.height;
            if (area < areaJanela * 0.06) continue; // ignora coisa pequena

            // As telas de auth são claras nos DOIS temas, por decisão de design
            // (tokens fixos no escopo `.auth`). Apontá-las aqui é falso positivo.
            if (el.closest('.auth')) continue;

            const cor = getComputedStyle(el).backgroundColor;
            if (!claro(cor)) continue;

            achados.push({
                seletor: el.tagName.toLowerCase()
                    + (el.id ? '#' + el.id : '')
                    + (typeof el.className === 'string' && el.className
                        ? '.' + el.className.trim().split(/\s+/).slice(0, 3).join('.')
                        : ''),
                cor,
                proporcaoDaTela: +(area / areaJanela).toFixed(2),
            });
        }

        return achados.slice(0, 6);
    });
}

async function entrar(page) {
    const email = process.env.SEED_EMAIL;
    const senha = process.env.SEED_PASSWORD;
    expect(email && senha, 'SEED_EMAIL/SEED_PASSWORD não vieram do ambiente').toBeTruthy();

    await page.goto('/login');
    await page.fill('#email', email);
    await page.fill('#password', senha);
    await page.click('button[type=submit]');
    await page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 15_000 });

    expect(
        new URL(page.url()).pathname,
        'O usuário de dev caiu no desafio de 2FA — desative-o nele para rodar a auditoria'
    ).not.toContain('verificacao-em-duas-etapas');
}

test('auditoria mobile: nenhuma tela rola de lado nem acende no escuro', async ({ page }) => {
    test.setTimeout(600_000);
    fs.mkdirSync(SHOTS, { recursive: true });

    const problemas = [];
    const erros = [];
    page.on('console', (m) => { if (m.type() === 'error') erros.push(m.text()); });
    page.on('pageerror', (e) => erros.push('pageerror: ' + e.message));

    for (const tema of ['light', 'dark']) {
        await page.addInitScript((t) => {
            try {
                localStorage.setItem('sm-theme', t);
                // Consentimento de cookies pré-aceito: a barra é `position: fixed` e, em
                // 360px, COBRE o botão "Entrar" do login (medido: barra 493→656, botão
                // 585→636). Sem isto a auditoria não passa da porta de entrada — o que,
                // por si só, é o achado mais grave desta varredura.
                localStorage.setItem('sm-cookie-consent', '1');
            } catch (_) { /* modo privado */ }
        }, tema);

        for (const vp of VIEWPORTS) {
            await page.setViewportSize({ width: vp.width, height: vp.height });

            // As públicas rodam deslogado; depois entra e faz as demais.
            await page.context().clearCookies();

            for (const [nome, url] of TELAS_PUBLICAS) {
                await page.goto(url, { waitUntil: 'networkidle' });
                await verificar(page, { nome, url, tema, vp }, problemas, SHOTS);
            }

            await entrar(page);

            for (const [nome, url] of TELAS_AUTENTICADAS) {
                await page.goto(url, { waitUntil: 'networkidle' });
                await verificar(page, { nome, url, tema, vp }, problemas, SHOTS);
            }
        }
    }

    const relatorio = { problemas, errosDeConsole: [...new Set(erros)] };
    fs.writeFileSync(`${SHOTS}/relatorio.json`, JSON.stringify(relatorio, null, 2));

    console.log('\n================ AUDITORIA MOBILE ================');
    if (!problemas.length) {
        console.log('Nenhum estouro horizontal nem mancha clara no escuro.');
    }
    for (const p of problemas) {
        console.log(`\n[${p.tipo}] ${p.tela} · ${p.tema} · ${p.viewport}px`);
        console.log('   ' + JSON.stringify(p.detalhe));
    }
    console.log('\nScreenshots: ' + SHOTS);
    console.log('==================================================\n');
});

async function verificar(page, ctx, problemas, dir) {
    const { nome, tema, vp } = ctx;
    const etiqueta = `${nome}-${tema}-${vp.nome}`;

    const medida = await medirEstouro(page);

    if (medida.rolagemDaPagina > 0) {
        problemas.push({
            tipo: 'PÁGINA ROLA DE LADO',
            tela: nome, tema, viewport: vp.nome,
            detalhe: { sobra: medida.rolagemDaPagina + 'px' },
        });
    }

    if (medida.recortes.length) {
        problemas.push({
            tipo: 'CONTEÚDO RECORTADO',
            tela: nome, tema, viewport: vp.nome,
            detalhe: medida.recortes,
        });
    }

    if (tema === 'dark') {
        const manchas = await procurarManchasClaras(page);
        if (manchas.length) {
            problemas.push({
                tipo: 'MANCHA CLARA NO TEMA ESCURO',
                tela: nome, tema, viewport: vp.nome,
                detalhe: manchas,
            });
        }
    }

    await page.screenshot({ path: `${dir}/${etiqueta}.png`, fullPage: true });
}
