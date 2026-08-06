import { test, expect } from '@playwright/test';
import fs from 'node:fs';

/**
 * Auditoria de responsividade dos MODAIS no celular.
 *
 * Os modais ficam de fora de qualquer varredura que só visita URLs — eles não têm
 * endereço próprio. E são justamente onde o app **recebe dados**: lançar despesa, aportar
 * numa meta, pagar fatura, escolher de onde sai o dinheiro. Um modal que não cabe é pior
 * que uma tela que não cabe, porque o usuário fica preso: não vê o botão de salvar e nem
 * sempre acha o de fechar.
 *
 * Três perguntas, medidas em vez de opinadas:
 *
 *  1. **Cabe na largura?** Modal mais largo que a tela corta os campos.
 *  2. **O botão de ação é alcançável?** Se o modal é mais alto que a tela, ele precisa
 *     rolar POR DENTRO (`overflow-y:auto`). Se não rolar, o "Salvar" fica inacessível —
 *     e o usuário não tem como concluir o que abriu o modal para fazer.
 *  3. **E com o TECLADO aberto?** É a pergunta que separa modal que funciona no celular
 *     de modal que só funciona no emulador. O teclado come ~55% da altura; a auditoria
 *     repete tudo em 360×340 para simular isso.
 *
 * Abre cada modal aplicando a classe `.open` (é assim que o app os mostra) em vez de
 * caçar o botão que o dispara: pega TODOS, inclusive os que dependem de dados que a conta
 * de teste não tem.
 */

const SHOTS = 'test-results/modais';

const PAGINAS = [
    ['dashboard', '/'],
    ['metodos-pagamento', '/accounts'],
    ['categorias', '/categories'],
    ['pagar-despesas', '/faturas'],
    ['metas', '/metas'],
    ['investimentos', '/investimentos'],
    ['dependentes', '/dependentes'],
    ['meu-perfil', '/meu-perfil'],
];

const CENARIOS = [
    { nome: 'celular', width: 360, height: 740 },
    // Altura com o teclado do Android aberto numa tela de 740px.
    { nome: 'com-teclado', width: 360, height: 340 },
];

async function entrar(page) {
    const email = process.env.SEED_EMAIL;
    const senha = process.env.SEED_PASSWORD;
    expect(email && senha, 'SEED_EMAIL/SEED_PASSWORD não vieram do ambiente').toBeTruthy();

    await page.goto('/login');
    await page.fill('#email', email);
    await page.fill('#password', senha);
    await page.click('button[type=submit]');
    await page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 15_000 });
}

/** Abre UM modal e devolve as medidas que importam. */
async function medirModal(page, id) {
    return page.evaluate((idAlvo) => {
        document.querySelectorAll('.modal-scrim.open').forEach((m) => m.classList.remove('open'));

        const scrim = document.getElementById(idAlvo);
        if (!scrim) return { erro: 'não achou' };
        scrim.classList.add('open');

        const modal = scrim.querySelector('.modal');
        if (!modal) return { erro: 'sem .modal dentro' };

        const vw = window.innerWidth;
        const vh = window.innerHeight;
        const r = modal.getBoundingClientRect();

        const estilo = getComputedStyle(modal);
        const rolaPorDentro = ['auto', 'scroll'].includes(estilo.overflowY);
        const precisaRolar = modal.scrollHeight > modal.clientHeight + 1;

        // O botão de ação principal: o último submit/primary do rodapé.
        const acao = modal.querySelector('.modal-foot button[type=submit], .modal-foot .btn.primary, .modal-foot .btn-primary')
            || [...modal.querySelectorAll('button[type=submit], .btn.primary, .btn-primary')].pop();

        let acaoInfo = null;
        if (acao) {
            const ra = acao.getBoundingClientRect();
            acaoInfo = {
                dentroDaTela: ra.bottom <= vh + 1 && ra.top >= -1,
                // Se o modal rola por dentro, o botão é alcançável mesmo fora da vista inicial.
                alcancavel: (ra.bottom <= vh + 1 && ra.top >= -1) || rolaPorDentro,
                altura: Math.round(ra.height),
            };
        }

        // Estouro horizontal DENTRO do modal (o app recorta, então medir o container).
        const estouroInterno = [];
        for (const el of modal.querySelectorAll('*')) {
            // Botão de ícone pequeno que "estoura" é o pseudo-elemento invisível que
            // amplia a área de toque para 44×44 sem engordar o desenho (ver `.modal-x`).
            // Não há conteúdo perdido: o excedente É a folga de toque pretendida.
            if (el.matches('button, a') && el.clientWidth < 60
                && el.querySelectorAll(':scope > *:not(svg)').length === 0) continue;

            if (el.scrollWidth > el.clientWidth + 2 && el.clientWidth > 0) {
                estouroInterno.push({
                    seletor: el.tagName.toLowerCase() + (el.className && typeof el.className === 'string'
                        ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : ''),
                    sobra: el.scrollWidth - el.clientWidth,
                });
            }
        }

        // Manchas claras (tema escuro).
        const claro = (cor) => {
            const m = cor.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?\)/);
            if (!m) return false;
            const a = m[4] === undefined ? 1 : +m[4];
            if (a < 0.5) return false;
            return (0.299 * +m[1] + 0.587 * +m[2] + 0.114 * +m[3]) > 200;
        };
        const manchas = [...modal.querySelectorAll('*')]
            .filter((el) => {
                const rr = el.getBoundingClientRect();
                return rr.width * rr.height > 4000 && claro(getComputedStyle(el).backgroundColor);
            })
            .map((el) => el.tagName.toLowerCase() + '.' + String(el.className).split(' ')[0])
            .slice(0, 3);

        // Alvos de toque pequenos.
        const toquePequeno = [...modal.querySelectorAll('button, a, input[type=checkbox], input[type=radio]')]
            .filter((el) => {
                const rr = el.getBoundingClientRect();
                return rr.width > 0 && (rr.width < 44 || rr.height < 44);
            }).length;

        return {
            larguraModal: Math.round(r.width),
            alturaModal: Math.round(r.height),
            viewport: { vw, vh },
            estouraLargura: r.width > vw + 1,
            maisAltoQueATela: r.height > vh + 1,
            rolaPorDentro,
            precisaRolar,
            acao: acaoInfo,
            estouroInterno: estouroInterno.slice(0, 4),
            manchas,
            toquePequeno,
        };
    }, id);
}

test('auditoria de modais no celular', async ({ page }) => {
    test.setTimeout(600_000);
    fs.mkdirSync(SHOTS, { recursive: true });

    const problemas = [];

    for (const tema of ['light', 'dark']) {
        await page.addInitScript((t) => {
            try {
                localStorage.setItem('sm-theme', t);
                localStorage.setItem('sm-cookie-consent', '1');
            } catch (_) { /* modo privado */ }
        }, tema);

        await page.setViewportSize({ width: 360, height: 740 });
        await page.context().clearCookies();
        await entrar(page);

        for (const [nomePagina, url] of PAGINAS) {
            await page.goto(url, { waitUntil: 'networkidle' });

            const ids = await page.evaluate(() =>
                [...document.querySelectorAll('.modal-scrim[id]')].map((m) => m.id));

            for (const id of ids) {
                for (const cen of CENARIOS) {
                    await page.setViewportSize({ width: cen.width, height: cen.height });
                    const m = await medirModal(page, id);
                    if (m.erro) continue;

                    const falhas = [];
                    if (m.estouraLargura) falhas.push(`largura ${m.larguraModal}px > tela ${m.viewport.vw}px`);
                    if (m.maisAltoQueATela && !m.rolaPorDentro) falhas.push(`altura ${m.alturaModal}px > tela ${m.viewport.vh}px e NÃO rola por dentro`);
                    if (m.acao && !m.acao.alcancavel) falhas.push('botão de ação inalcançável');
                    if (m.estouroInterno.length) falhas.push('estouro interno: ' + JSON.stringify(m.estouroInterno));
                    if (tema === 'dark' && m.manchas.length) falhas.push('mancha clara: ' + m.manchas.join(', '));

                    if (falhas.length) {
                        problemas.push({ pagina: nomePagina, modal: id, tema, cenario: cen.nome, falhas, medidas: m });
                    }

                    if (cen.nome === 'celular' && tema === 'dark') {
                        await page.screenshot({ path: `${SHOTS}/${id}-dark.png` });
                    }
                }
            }
        }
    }

    fs.writeFileSync(`${SHOTS}/relatorio.json`, JSON.stringify(problemas, null, 2));

    console.log('\n============= AUDITORIA DE MODAIS =============');
    if (!problemas.length) console.log('Nenhum problema de layout nos modais.');
    for (const p of problemas) {
        console.log(`\n[${p.modal}] ${p.pagina} · ${p.tema} · ${p.cenario}`);
        p.falhas.forEach((f) => console.log('   - ' + f));
    }
    console.log('\nScreenshots: ' + SHOTS);
    console.log('===============================================\n');
});
