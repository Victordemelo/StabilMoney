#!/usr/bin/env node
/**
 * Varredura de RESPONSIVIDADE — de 1920×1080 até 200px, claro e escuro, e o PWA instalado.
 *
 * Não é um teste do Playwright Test (o nome não termina em .spec.js de propósito): é uma
 * ferramenta de regressão que abre cada tela e cada modal em cada largura e mede, por
 * script, o que quebra. Saída: um resumo no terminal e um `achados.json` na pasta de saída.
 * Sai com código 1 quando há achado.
 *
 * O que ela procura, em cada combinação tela × largura × tema:
 *   - rolagem-horizontal  documentElement.scrollWidth > clientWidth
 *   - fora-da-tela        elemento visível com parte além das bordas da viewport
 *   - sai-do-container    texto/controle que passa da caixa visível (fundo/borda) que o contém
 *   - texto-sobreposto    dois textos visíveis que se cruzam (Range.getClientRects() por nó de
 *                         texto; a pilha de pintura é conferida com elementsFromPoint, então o
 *                         que está escondido sob uma camada fixa — topo, barra inferior,
 *                         modal — não conta)
 *   - texto-coberto       texto coberto por um elemento opaco que NÃO é camada fixa
 *   - texto-cortado       texto recortado por overflow hidden/clip sem reticências proposital
 *   - alvo-pequeno        controle com menos de 32px (só abaixo de 768px de largura)
 *   - modal-*             modal que passa da tela, sem rolagem interna, cabeçalho/rodapé fora
 *   - sob-barra           conteúdo do fim da página que nunca sai de baixo da barra inferior
 *   - pwa-area-segura     (com --pwa) texto/controle de camada fixa dentro da área do
 *                         notch/barra de gestos (env(safe-area-inset-*) simulado pelo CDP)
 *
 * USO (contra uma prévia descartável — NUNCA contra o app de dev com dados reais):
 *
 *   node tests/e2e/responsividade.mjs --base=http://127.0.0.1:8192 --cookie=/caminho/cookie.json
 *
 * O cookie é o de "lembrar de mim" gerado pelo próprio framework ({"name":..,"value":..}),
 * como faz a prévia em .git/stabil-patches/previa/montar.sh — a varredura nunca digita senha.
 * A família de demonstração (DadosDeDemonstracaoSeeder) é o que dá conteúdo às telas.
 *
 * Opções:
 *   --saida=DIR          pasta do achados.json e das fotos (padrão: ./responsividade-saida)
 *   --larguras=390,200   só estas larguras (padrão: todas da lista LARGURAS)
 *   --telas=painel,login só estas telas/estados (ids da lista TELAS; prefixo com * vale)
 *   --temas=claro        claro, escuro ou os dois (padrão)
 *   --pwa                roda TAMBÉM os perfis de PWA instalado (iPhone com notch, retrato e
 *                        paisagem, display-mode standalone)
 *   --so-pwa             só os perfis de PWA
 *   --fotos              salva um JPEG de cada combinação com achado, com os achados marcados
 *   --paralelo=6         páginas simultâneas
 */
import { chromium } from 'playwright-core';
import fs from 'node:fs';
import path from 'node:path';

const args = Object.fromEntries(process.argv.slice(2).map((a) => {
    const [k, ...v] = a.replace(/^--/, '').split('=');
    return [k, v.length ? v.join('=') : true];
}));
if (!args.base || !args.cookie) {
    console.error('uso: node tests/e2e/responsividade.mjs --base=http://127.0.0.1:8192 --cookie=cookie.json [--pwa] [--fotos]');
    process.exit(2);
}
const BASE = String(args.base).replace(/\/$/, '');
const COOKIE = JSON.parse(fs.readFileSync(args.cookie, 'utf8'));
const SAIDA = path.resolve(args.saida || 'responsividade-saida');
const PARALELO = Number(args.paralelo || 6);
fs.mkdirSync(SAIDA, { recursive: true });

// Largura × altura. Sem altura no pedido (920/921/620/560) usa 900.
export const LARGURAS = [
    [1920, 1080], [1440, 900], [1366, 768], [1280, 800], [1024, 768], [921, 900], [920, 900],
    [768, 1024], [620, 900], [560, 900], [414, 896], [390, 844], [375, 667], [360, 740],
    [320, 640], [280, 653], [200, 600],
];

// PWA instalado. iOS com `apple-mobile-web-app-status-bar-style: default` põe o conteúdo
// ABAIXO da barra de status (inset de cima 0 e 47px a menos de altura); a barra de gestos
// fica POR CIMA do conteúdo (34px). Em paisagem o notch vira inset lateral.
const PERFIS_PWA = [
    { id: 'pwa-iphone', w: 390, h: 797, insets: { top: 0, bottom: 34, left: 0, right: 0 } },
    { id: 'pwa-iphone-se', w: 375, h: 647, insets: { top: 0, bottom: 0, left: 0, right: 0 } },
    { id: 'pwa-paisagem', w: 844, h: 390, insets: { top: 0, bottom: 21, left: 47, right: 47 } },
    { id: 'pwa-android', w: 360, h: 716, insets: { top: 0, bottom: 24, left: 0, right: 0 } },
];

const abrirDialogo = (id) => async (page) => {
    await page.evaluate((id) => {
        const el = document.getElementById(id);
        if (!el) throw new Error('modal não encontrado: ' + id);
        window.smDialogo.abrir(el);
    }, id);
    await page.waitForTimeout(150);
    return '#' + id + ' .modal';
};
const lancar = (tipo) => async (page) => {
    await page.evaluate(() => document.querySelector('[data-launch-open]').click());
    await page.waitForTimeout(150);
    if (tipo) await page.evaluate((t) => document.getElementById(t).click(), tipo);
    await page.waitForTimeout(100);
    return '#launchModal .modal';
};

// id, caminho, logado?, preparar(page) → seletor do escopo (modal/drawer) ou nada.
export const TELAS = [
    { id: 'inicio', url: '/', guest: true }, // a página inicial pública (out/2026)
    { id: 'login', url: '/login', guest: true },
    { id: 'cadastro', url: '/register', guest: true },
    { id: 'esqueci-a-senha', url: '/forgot-password', guest: true },
    { id: 'termos', url: '/termos', guest: true },
    { id: 'privacidade', url: '/privacidade', guest: true },
    { id: 'erro-404', url: '/pagina-que-nao-existe', guest: true, status: 404 },
    { id: 'painel', url: '/' },
    { id: 'movimentacoes', url: '/transactions' },
    { id: 'movimentacoes-filtros', url: '/transactions?type=expense&account=4&de=2026-01-01&ate=2026-12-31' },
    { id: 'editar-parcela', url: async (page) => {
        await page.goto(BASE + '/transactions?account=4', { waitUntil: 'domcontentloaded' });
        const caminho = await page.evaluate(() => {
            const a = [...document.querySelectorAll('a[href*="/edit"]')].find((x) => /Notebook/.test(x.textContent));
            return a ? new URL(a.href).pathname : null;
        });
        return caminho || '/transactions/113/edit';
    } },
    { id: 'contas-a-pagar', url: '/faturas' },
    { id: 'contas-a-pagar-cartao-aberto', url: '/faturas', preparar: async (page) => {
        await page.evaluate(() => document.querySelectorAll('details.fatura-recolhe').forEach((d) => { d.open = true; }));
        await page.waitForTimeout(100);
    } },
    { id: 'contas-e-cartoes', url: '/accounts' },
    { id: 'metas', url: '/metas' },
    { id: 'investimentos', url: '/investimentos' },
    { id: 'categorias', url: '/categories' },
    { id: 'familia', url: '/dependentes' },
    { id: 'meu-perfil', url: '/meu-perfil' },
    { id: 'config-seguranca', url: '/configuracoes/seguranca' },
    { id: 'config-2fa', url: '/configuracoes/2fa' },
    { id: 'config-conta', url: '/configuracoes/conta' },
    { id: 'config-atividade', url: '/configuracoes/atividade' },
    { id: 'modal-lancar-receita', url: '/', preparar: lancar(null) },
    { id: 'modal-lancar-despesa', url: '/', preparar: lancar('lm-tt-expense') },
    { id: 'modal-lancar-transferencia', url: '/', preparar: lancar('lm-tt-transfer') },
    { id: 'modal-lancar-despesa-faturas', url: '/faturas', preparar: abrirDialogo('lancarModal') },
    { id: 'modal-remover-despesa', url: '/faturas', preparar: async (page) => {
        await page.evaluate(() => {
            document.querySelectorAll('details.fatura-recolhe').forEach((d) => { d.open = true; });
            document.querySelector('[data-remover-despesa]').click();
        });
        await page.waitForTimeout(150);
        return '#removerDespesaModal .modal';
    } },
    { id: 'modal-excluir-conta', url: '/configuracoes/conta', preparar: abrirDialogo('confirm-user-deletion') },
    { id: 'modal-nova-conta', url: '/accounts', preparar: abrirDialogo('acctModal-novo') },
    { id: 'modal-nova-meta', url: '/metas', preparar: abrirDialogo('metaCreateModal') },
    { id: 'drawer', url: '/', maxLargura: 920, preparar: async (page) => {
        await page.click('#mMenu');
        await page.waitForTimeout(350);
        return '#sidebar';
    } },
    { id: 'popover-perfil', url: '/', preparar: async (page) => {
        const vw = await page.evaluate(() => document.documentElement.clientWidth);
        if (vw <= 920) { await page.click('#mMenu'); await page.waitForTimeout(350); }
        await page.evaluate(() => document.getElementById('profileBtn').click());
        await page.waitForTimeout(250);
        return '#profilePop';
    } },
];

/* ======================================================================================
 * Análise dentro da página. Uma função só, serializada pelo page.evaluate.
 * ====================================================================================== */
function analisar(opts) {
    const { escopoSel, celular, insets } = opts;
    const de = document.documentElement;
    const vw = de.clientWidth;
    const vh = window.innerHeight;
    const escopo = escopoSel ? document.querySelector(escopoSel) : null;
    const achados = [];
    const css = new Map();
    const cs = (el) => { let c = css.get(el); if (!c) { c = getComputedStyle(el); css.set(el, c); } return c; };
    const INF = { l: -1e9, t: -1e9, r: 1e9, b: 1e9 };
    const r4 = (r) => ({ l: Math.round(r.l), t: Math.round(r.t), r: Math.round(r.r), b: Math.round(r.b) });
    const deRect = (r) => ({ l: r.left, t: r.top, r: r.right, b: r.bottom });
    const inter = (a, b) => ({ l: Math.max(a.l, b.l), t: Math.max(a.t, b.t), r: Math.min(a.r, b.r), b: Math.min(a.b, b.b) });
    const w = (r) => Math.max(0, r.r - r.l);
    const h = (r) => Math.max(0, r.b - r.t);
    const dentro = (el) => !escopo || escopo.contains(el);
    // Retângulo em coordenadas do DOCUMENTO (para marcar a foto de página inteira).
    const noDoc = (r) => {
        const c = document.getElementById('content');
        const dy = window.scrollY + (c ? c.scrollTop : 0);
        return { l: Math.round(r.l + window.scrollX), t: Math.round(r.t + dy), r: Math.round(r.r + window.scrollX), b: Math.round(r.b + dy) };
    };

    const desc = (el) => {
        if (!el || el.nodeType !== 1) return String(el);
        let s = el.tagName.toLowerCase();
        if (el.id) s += '#' + el.id;
        const cls = [...el.classList].slice(0, 3);
        if (cls.length) s += '.' + cls.join('.');
        const p = el.parentElement;
        let ps = '';
        if (p) {
            ps = p.tagName.toLowerCase() + (p.id ? '#' + p.id : '') + ([...p.classList].slice(0, 2).map((c) => '.' + c).join(''));
        }
        const t = (el.innerText || el.value || el.getAttribute('aria-label') || '').replace(/\s+/g, ' ').trim().slice(0, 40);
        return (ps ? ps + ' > ' : '') + s + (t ? ` "${t}"` : '');
    };
    const visivel = (el) => el.checkVisibility({ opacityProperty: true, visibilityProperty: true, contentVisibilityAuto: true });

    // Rolagem principal: no desktop o #content rola; no celular, a janela. Elas não recortam.
    const principal = (el) => el === de || el === document.body || el.id === 'content';
    const fazCBFixo = (c) => c.transform !== 'none' || c.filter !== 'none' || c.perspective !== 'none'
        || /paint|layout|strict|content/.test(c.contain || '') || /transform|filter/.test(c.willChange || '')
        || (c.backdropFilter && c.backdropFilter !== 'none');
    // Retângulo que sobra depois de todos os ancestrais que recortam (overflow ≠ visible),
    // seguindo a cadeia de bloco de contenção: absolute/fixed escapam de ancestrais estáticos.
    // Devolve também quem recortou (para separar recorte de rolagem de recorte "hidden").
    function recorte(el, proprio) {
        let r = { ...INF };
        const quem = [];
        const aplicar = (a) => {
            if (principal(a)) return;
            const c = cs(a);
            const ox = c.overflowX !== 'visible';
            const oy = c.overflowY !== 'visible';
            if (!ox && !oy) return;
            const b = a.getBoundingClientRect();
            const box = { l: b.left + a.clientLeft, t: b.top + a.clientTop, r: b.left + a.clientLeft + a.clientWidth, b: b.top + a.clientTop + a.clientHeight };
            if (ox) { r.l = Math.max(r.l, box.l); r.r = Math.min(r.r, box.r); }
            if (oy) { r.t = Math.max(r.t, box.t); r.b = Math.min(r.b, box.b); }
            quem.push({ el: a, box, rola: /auto|scroll/.test(c.overflowX + c.overflowY) });
        };
        if (proprio) aplicar(el);
        let pos = cs(el).position;
        let cur = el.parentElement;
        while (cur && cur !== document.body && cur !== de) {
            const c = cs(cur);
            let pula = false;
            if (pos === 'fixed') pula = !fazCBFixo(c);
            else if (pos === 'absolute') pula = c.position === 'static' && !fazCBFixo(c);
            if (!pula) { aplicar(cur); pos = c.position; }
            cur = cur.parentElement;
        }
        return { r, quem };
    }
    // Camada fixa (ou sticky) a que o elemento pertence, se houver.
    const camadaFixa = (el) => {
        for (let a = el; a && a !== de; a = a.parentElement) {
            const p = cs(a).position;
            if (p === 'fixed' || p === 'sticky') return a;
        }
        return null;
    };
    const opaco = (el) => {
        const c = cs(el);
        if (['IMG', 'VIDEO', 'CANVAS'].includes(el.tagName)) return true;
        if (c.backgroundImage && c.backgroundImage !== 'none') return true;
        const m = c.backgroundColor.match(/rgba?\(([^)]+)\)/);
        if (!m) return false;
        const p = m[1].split(/[ ,/]+/).filter(Boolean);
        const a = p.length > 3 ? parseFloat(p[3]) : 1;
        return a >= 0.6;
    };
    const temReticencias = (el, ate) => {
        for (let a = el; a && a !== de; a = a.parentElement) {
            const c = cs(a);
            if (c.textOverflow === 'ellipsis' || (c.webkitLineClamp && c.webkitLineClamp !== 'none')) return true;
            if (a === ate) break;
        }
        return false;
    };

    // ---------- 1. Rolagem horizontal da página ----------
    if (de.scrollWidth > vw + 1) {
        achados.push({ tipo: 'rolagem-horizontal', detalhe: `scrollWidth ${de.scrollWidth} > ${vw}` });
    }

    // ---------- 2. Textos: coleta por linha ----------
    const textos = [];
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, {
        acceptNode: (n) => (n.nodeValue.trim() ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT),
    });
    const range = document.createRange();
    const cortados = new Set();
    for (let n = walker.nextNode(); n; n = walker.nextNode()) {
        const dono = n.parentElement;
        if (!dono || dono.closest('script,style,noscript,template,title,svg,.sr-only,[hidden]')) continue;
        if (!visivel(dono)) continue;
        const c = cs(dono);
        if (parseFloat(c.fontSize) < 4) continue;
        if (/rgba\([^)]*,\s*0\)|transparent/.test(c.color) && c.webkitTextFillColor !== 'currentcolor' && !/clip|text/.test(c.backgroundClip + c.webkitBackgroundClip)) continue;
        range.selectNodeContents(n);
        const { r: clip, quem } = recorte(dono, true);
        for (const rr of range.getClientRects()) {
            if (rr.width < 2 || rr.height < 2) continue;
            const cheio = deRect(rr);
            const vis = inter(cheio, clip);
            if (w(vis) < 1 || h(vis) < 1) {
                // Sumiu INTEIRO pela lateral de uma caixa que está na tela (ex.: o nome da
                // categoria espremido a 0px): é texto perdido, não um painel recolhido.
                const lateral = quem.find((q) => !q.rola && h(q.box) > 0
                    && cheio.t < q.box.b && cheio.b > q.box.t && (cheio.r <= q.box.l + 1 || cheio.l >= q.box.r - 1 || w(q.box) < 4));
                if (lateral && !temReticencias(dono, lateral.el) && !cortados.has(dono) && dentro(dono) && h(inter(cheio, { l: -1e9, t: clip.t, r: 1e9, b: clip.b })) > 0) {
                    cortados.add(dono);
                    achados.push({ tipo: 'texto-cortado', el: desc(dono), por: desc(lateral.el), rect: r4(cheio), visivel: 'nada' });
                }
                continue;
            }
            // Reticências que comem o texto quase todo também são texto perdido.
            if (temReticencias(dono, null) && dono.clientWidth > 0 && dono.clientWidth < Math.min(28, w(cheio) * 0.5) && !cortados.has(dono) && dentro(dono)) {
                cortados.add(dono);
                achados.push({ tipo: 'texto-cortado', el: desc(dono), detalhe: `espremido em ${dono.clientWidth}px`, rect: r4(cheio) });
            }
            textos.push({ n, dono, cheio, vis });
            // ---------- texto-cortado ----------
            const perdeuX = w(cheio) - w(vis) > 2;
            const perdeuY = h(vis) < h(cheio) * 0.6;
            if ((perdeuX || perdeuY) && !cortados.has(dono)) {
                const culpado = quem.find((q) => !q.rola && (cheio.l < q.box.l - 1 || cheio.r > q.box.r + 1 || (perdeuY && (cheio.t < q.box.t - 1 || cheio.b > q.box.b + 1))));
                if (culpado && !temReticencias(dono, culpado.el) && dentro(dono)) {
                    cortados.add(dono);
                    achados.push({ tipo: 'texto-cortado', el: desc(dono), por: desc(culpado.el), rect: r4(cheio), visivel: r4(vis) });
                }
            }
        }
    }

    // ---------- 3. Sobreposição de textos ----------
    textos.sort((a, b) => a.vis.t - b.vis.t);
    const candidatos = [];
    for (let i = 0; i < textos.length; i++) {
        const A = textos[i];
        for (let j = i + 1; j < textos.length; j++) {
            const B = textos[j];
            if (B.vis.t >= A.vis.b) break;
            if (A.n === B.n) continue;
            const I = inter(A.vis, B.vis);
            const iw = w(I), ih = h(I);
            if (iw < 3 || ih < 2) continue;
            const minH = Math.min(h(A.vis), h(B.vis));
            const minA = Math.min(w(A.vis) * h(A.vis), w(B.vis) * h(B.vis));
            if (ih < minH * 0.35 || iw * ih < minA * 0.1) continue;
            if (!dentro(A.dono) && !dentro(B.dono)) continue;
            candidatos.push([A, B]);
        }
    }
    const pilhaIdx = (pilha, el) => pilha.findIndex((e) => e === el || e.contains(el));
    const vistos = new Set();
    for (const [A, B] of candidatos.slice(0, 400)) {
        const chave = desc(A.dono) + '|' + desc(B.dono);
        if (vistos.has(chave)) continue;
        // Traz o ponto para dentro da viewport, se preciso.
        let I = inter(A.vis, B.vis);
        let x = (I.l + I.r) / 2, y = (I.t + I.b) / 2;
        if (y < 0 || y > vh || x < 0 || x > vw) {
            A.dono.scrollIntoView({ block: 'center', inline: 'nearest', behavior: 'instant' });
            range.selectNodeContents(A.n);
            const ra = [...range.getClientRects()].map(deRect);
            range.selectNodeContents(B.n);
            const rb = [...range.getClientRects()].map(deRect);
            let melhor = null;
            for (const a of ra) for (const b of rb) { const ii = inter(a, b); if (w(ii) >= 3 && h(ii) >= 2 && (!melhor || w(ii) * h(ii) > w(melhor) * h(melhor))) melhor = ii; }
            if (!melhor) continue;
            I = melhor; x = (I.l + I.r) / 2; y = (I.t + I.b) / 2;
            if (y < 0 || y > vh || x < 0 || x > vw) continue;
        }
        const pilha = document.elementsFromPoint(x, y);
        let ia = pilhaIdx(pilha, A.dono), ib = pilhaIdx(pilha, B.dono);
        if (ia < 0 || ib < 0) continue;
        const [cima, baixo, iBaixo] = ia <= ib ? [A, B, ib] : [B, A, ia];
        // O de cima numa camada fixa e o de baixo fora dela = conteúdo rolando por baixo do
        // topo/barra inferior (translúcidos de propósito). O fim da página é o sob-barra.
        const camCima = camadaFixa(cima.dono);
        if (camCima && !camCima.contains(baixo.dono)) { vistos.add(chave); continue; }
        // Algo opaco entre o de cima e o de baixo, que não seja ancestral do de baixo, cobre o de baixo.
        let cobre = null;
        for (let k = 0; k < iBaixo; k++) {
            const e = pilha[k];
            if (e.contains(baixo.dono) || baixo.dono.contains(e)) continue;
            if (opaco(e)) { cobre = e; break; }
        }
        vistos.add(chave);
        if (!cobre) {
            achados.push({ tipo: 'texto-sobreposto', el: desc(A.dono), com: desc(B.dono), rect: r4(I), doc: noDoc(I) });
        } else {
            // Coberto por camada fixa (topo, barra inferior, modal, drawer) = rola por baixo: normal.
            const camada = camadaFixa(cobre);
            if (camada && !camada.contains(baixo.dono)) continue;
            if (!dentro(baixo.dono) && !dentro(cobre)) continue;
            achados.push({ tipo: 'texto-coberto', el: desc(baixo.dono), por: desc(cobre), rect: r4(I), doc: noDoc(I) });
        }
    }
    window.scrollTo({ top: 0, left: 0, behavior: 'instant' });
    const cont = document.getElementById('content');
    if (cont) cont.scrollTo({ top: 0, behavior: 'instant' });

    // ---------- 4. Fora da tela / sai do container ----------
    const todos = [...(escopo || document.body).querySelectorAll('*')];
    const foraSet = new Set();
    const caixa = (el) => {
        const c = cs(el);
        return opaco(el) || parseFloat(c.borderLeftWidth) > 0 || parseFloat(c.borderRightWidth) > 0
            || (c.boxShadow && c.boxShadow !== 'none');
    };
    for (const el of todos) {
        if (el.closest('script,style,noscript,template,head,.sr-only,svg *')) continue;
        if (el.closest('.sm-falling')) continue; // fundo decorativo
        if (!visivel(el)) continue;
        const b = el.getBoundingClientRect();
        if (b.width < 1 || b.height < 1) continue;
        const { r: clip } = recorte(el, false);
        const v = inter(deRect(b), clip);
        if (w(v) < 1 || h(v) < 1) continue;
        // inteiramente fora pela esquerda = gaveta fechada (off-canvas), não conteúdo perdido
        if (v.r <= 0.5) continue;
        if (v.l < -1 || v.r > vw + 1) {
            foraSet.add(el);
            if (!foraSet.has(el.parentElement)) {
                achados.push({ tipo: 'fora-da-tela', el: desc(el), rect: r4(v), vw });
            }
            continue;
        }
        // sai-do-container: só para quem tem texto próprio ou é controle, em fluxo normal
        const temTexto = [...el.childNodes].some((n) => n.nodeType === 3 && n.nodeValue.trim());
        const controle = el.matches('a[href],button,input,select,textarea,summary,label');
        if (!temTexto && !controle) continue;
        const pos = cs(el).position;
        if (pos === 'absolute' || pos === 'fixed') continue;
        for (let a = el.parentElement; a && a !== document.body; a = a.parentElement) {
            const ca = cs(a);
            if (ca.position === 'fixed' || ca.overflowX !== 'visible') break;
            if (!caixa(a)) continue;
            const ba = a.getBoundingClientRect();
            if (b.right > ba.right + 2 || b.left < ba.left - 2) {
                achados.push({ tipo: 'sai-do-container', el: desc(el), de: desc(a), rect: r4(deRect(b)), container: r4(deRect(ba)) });
            }
            break;
        }
    }

    // ---------- 5. Alvos de toque ----------
    if (celular) {
        const sel = 'a[href],button,input:not([type=hidden]),select,textarea,summary,[role=button],[role=tab],[role=switch],[tabindex]:not([tabindex="-1"])';
        for (const el of (escopo || document.body).querySelectorAll(sel)) {
            if (!visivel(el) || el.disabled) continue;
            let alvo = el;
            if (el.matches('input[type=checkbox],input[type=radio]')) {
                const lab = el.closest('label') || (el.id && document.querySelector(`label[for="${CSS.escape(el.id)}"]`));
                const b0 = el.getBoundingClientRect();
                if (lab) alvo = lab; else if (b0.width < 2) continue;
            }
            const b = alvo.getBoundingClientRect();
            if (b.width < 1 || b.height < 1) continue;
            const { r: clip } = recorte(alvo, false);
            if (w(inter(deRect(b), clip)) < 1) continue;
            // Link dentro de frase: exceção do WCAG (o alvo é o texto corrido).
            if (cs(alvo).display === 'inline' && alvo.parentElement) {
                const pt = alvo.parentElement.textContent.trim().length;
                if (pt > alvo.textContent.trim().length + 12) continue;
            }
            // "Cresce o alvo, não o desenho": um ::before/::after absoluto e invisível que
            // estende a área de toque (padrão do projeto — .modal-x, .cc-act, .cc-del) conta.
            let alvoR = deRect(b);
            if (cs(alvo).position !== 'static') {
                for (const ps of ['::before', '::after']) {
                    const p = getComputedStyle(alvo, ps);
                    if (p.content === 'none' || p.position !== 'absolute' || p.display === 'none') continue;
                    const px = (v, base) => (v.endsWith('%') ? (parseFloat(v) / 100) * base : parseFloat(v));
                    const t = px(p.top, b.height), l = px(p.left, b.width), r = px(p.right, b.width), bo = px(p.bottom, b.height);
                    if ([t, l, r, bo].some(Number.isNaN)) continue;
                    alvoR = { l: Math.min(alvoR.l, b.left + l), t: Math.min(alvoR.t, b.top + t), r: Math.max(alvoR.r, b.right - r), b: Math.max(alvoR.b, b.bottom - bo) };
                }
            }
            if (w(alvoR) < 31.5 || h(alvoR) < 31.5) {
                achados.push({ tipo: 'alvo-pequeno', el: desc(alvo), tamanho: `${Math.round(w(alvoR))}×${Math.round(h(alvoR))}` });
            }
        }
    }

    // ---------- 6. Modal ----------
    if (escopo && escopo.classList.contains('modal')) {
        const m = escopo.getBoundingClientRect();
        const top = insets ? insets.top : 0, bottom = insets ? insets.bottom : 0;
        if (m.top < top - 1 || m.bottom > vh - bottom + 1 || m.left < -1 || m.right > vw + 1) {
            achados.push({ tipo: 'modal-fora-da-tela', el: desc(escopo), rect: r4(deRect(m)), viewport: `${vw}×${vh}` });
        }
        const corpo = escopo.querySelector('.modal-body');
        if (corpo && corpo.scrollHeight > corpo.clientHeight + 1 && !/auto|scroll/.test(cs(corpo).overflowY)) {
            achados.push({ tipo: 'modal-sem-rolagem', el: desc(corpo) });
        }
        const rolaveis = [escopo, corpo].filter(Boolean).filter((e) => /auto|scroll/.test(cs(e).overflowY) && e.scrollHeight > e.clientHeight + 1);
        if (!rolaveis.length && escopo.scrollHeight > escopo.clientHeight + 1 && cs(escopo).overflowY !== 'visible') {
            achados.push({ tipo: 'modal-sem-rolagem', el: desc(escopo), detalhe: `${escopo.scrollHeight} > ${escopo.clientHeight}` });
        }
        for (const parte of escopo.querySelectorAll('.modal-head, .modal-foot')) {
            const b = parte.getBoundingClientRect();
            if (b.height && (b.top < top - 1 || b.bottom > vh - bottom + 1)) {
                achados.push({ tipo: 'modal-parte-fora', el: desc(parte), rect: r4(deRect(b)) });
            }
        }
    }

    // ---------- 7. Área segura do PWA (camadas fixas) ----------
    if (insets) {
        const zonas = [];
        if (insets.top) zonas.push({ nome: 'topo', l: 0, t: 0, r: vw, b: insets.top });
        if (insets.bottom) zonas.push({ nome: 'barra de gestos', l: 0, t: vh - insets.bottom, r: vw, b: vh });
        if (insets.left) zonas.push({ nome: 'notch esquerdo', l: 0, t: 0, r: insets.left, b: vh });
        if (insets.right) zonas.push({ nome: 'notch direito', l: vw - insets.right, t: 0, r: vw, b: vh });
        const ja = new Set();
        for (const el of (escopo || document.body).querySelectorAll('*')) {
            if (!visivel(el) || el.closest('.sm-falling,svg *,.sr-only')) continue;
            const temTexto = [...el.childNodes].some((n) => n.nodeType === 3 && n.nodeValue.trim());
            const controle = el.matches('a[href],button,input,select,textarea,summary,label,svg');
            if (!temTexto && !controle) continue;
            const fixo = camadaFixa(el);
            // Em fluxo normal: só a faixa lateral importa (o resto rola para fora das bordas).
            if (!fixo && !insets.left) continue;
            // Dentro de uma camada fixa que ROLA (a gaveta, o corpo do modal), o que está na
            // borda agora sobe com a rolagem — e o fim dela tem o respiro da área segura.
            let rolaNaCamada = false;
            if (fixo) {
                for (let a = el.parentElement; a; a = a.parentElement) {
                    const ca = cs(a);
                    if (/auto|scroll/.test(ca.overflowY) && a.scrollHeight > a.clientHeight + 1) { rolaNaCamada = true; break; }
                    if (a === fixo) break;
                }
            }
            if (rolaNaCamada) continue;
            const b = el.getBoundingClientRect();
            const { r: clip } = recorte(el, false);
            const v = inter(deRect(b), clip);
            if (w(v) < 1 || h(v) < 1) continue;
            for (const z of zonas) {
                if (!fixo && z.nome !== 'notch esquerdo' && z.nome !== 'notch direito') continue;
                const I = inter(v, z);
                if (w(I) >= 1 && h(I) >= 1) {
                    const k = desc(el) + z.nome;
                    if (ja.has(k)) continue;
                    ja.add(k);
                    achados.push({ tipo: 'pwa-area-segura', el: desc(el), zona: z.nome, rect: r4(v) });
                }
            }
        }
    }
    return { achados, vw, vh, scrollWidth: de.scrollWidth };
}

// Fim da página: o que fica no fundo e nunca sai de baixo de uma camada fixa inferior.
function sobBarra() {
    const achados = [];
    const de = document.documentElement;
    const cont = document.getElementById('content');
    // `behavior: 'instant'`: o html tem scroll-behavior suave, e a medição viria antes da rolagem.
    window.scrollTo({ top: de.scrollHeight, behavior: 'instant' });
    if (cont) cont.scrollTo({ top: cont.scrollHeight, behavior: 'instant' });
    const vh = window.innerHeight;
    const desc = (el) => {
        let s = el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + [...el.classList].slice(0, 2).map((c) => '.' + c).join('');
        const t = (el.innerText || el.getAttribute('aria-label') || '').replace(/\s+/g, ' ').trim().slice(0, 40);
        return s + (t ? ` "${t}"` : '');
    };
    const fixo = (el) => { for (let a = el; a && a !== de; a = a.parentElement) { const p = getComputedStyle(a).position; if (p === 'fixed' || p === 'sticky') return a; } return null; };
    for (const el of document.querySelectorAll('#content *, main *')) {
        const temTexto = [...el.childNodes].some((n) => n.nodeType === 3 && n.nodeValue.trim());
        if (!temTexto && !el.matches('a[href],button,input,select,textarea')) continue;
        if (!el.checkVisibility({ opacityProperty: true, visibilityProperty: true })) continue;
        if (fixo(el)) continue;
        const b = el.getBoundingClientRect();
        if (b.height < 2 || b.bottom < vh * 0.5 || b.top > vh) continue;
        // Dentro de uma lista que rola sozinha (ex.: .tx-list), o que está fora da caixa
        // dela não aparece — e rola para dentro da caixa, longe da barra.
        let escondido = false;
        for (let a = el.parentElement; a && a !== document.body && a !== cont; a = a.parentElement) {
            const ca = getComputedStyle(a);
            if (ca.overflowY === 'visible') continue;
            const ra = a.getBoundingClientRect();
            const cy = b.top + b.height / 2;
            if (cy < ra.top || cy > ra.bottom) { escondido = true; break; }
        }
        if (escondido) continue;
        const x = Math.min(Math.max(b.left + b.width / 2, 1), de.clientWidth - 1);
        const y = Math.min(b.top + b.height / 2, vh - 1);
        const topo = document.elementFromPoint(x, y);
        if (!topo || el.contains(topo) || topo.contains(el)) continue;
        const camada = fixo(topo);
        if (camada && !camada.contains(el) && camada.getBoundingClientRect().top > vh * 0.5) {
            achados.push({ tipo: 'sob-barra', el: desc(el), por: desc(camada) });
        }
    }
    window.scrollTo({ top: 0, behavior: 'instant' });
    if (cont) cont.scrollTo({ top: 0, behavior: 'instant' });
    return achados;
}

async function marcarEFotografar(page, achados, arquivo, escopo) {
    await page.evaluate((lista) => {
        window.scrollTo({ top: 0, behavior: 'instant' });
        for (const a of lista) {
            const r = a.doc || a.rect || a.visivel;
            if (!r) continue;
            const d = document.createElement('div');
            d.setAttribute('data-marca-responsividade', '');
            // Absoluto no documento: a foto é de página inteira (no celular quem rola é a janela).
            d.style.cssText = `position:absolute;z-index:2147483647;pointer-events:none;left:${r.l - 2}px;top:${r.t - 2}px;width:${r.r - r.l + 4}px;height:${r.b - r.t + 4}px;outline:2px solid #e11d48;background:rgba(225,29,72,.12)`;
            document.body.appendChild(d);
        }
    }, achados);
    // Com modal/drawer aberto, a página inteira não ajuda: foto da tela.
    await page.screenshot({ path: arquivo, type: 'jpeg', quality: 72, fullPage: !escopo });
    await page.evaluate(() => document.querySelectorAll('[data-marca-responsividade]').forEach((d) => d.remove()));
}

/* ====================================================================================== */
async function rodarCombinacao(browser, { tela, w, h, tema, pwa }) {
    const celular = w < 768;
    const ctx = await browser.newContext({
        viewport: { width: w, height: h }, deviceScaleFactor: 1, isMobile: w <= 768, hasTouch: w <= 768,
        colorScheme: tema === 'escuro' ? 'dark' : 'light', reducedMotion: 'reduce', serviceWorkers: 'block',
        locale: 'pt-BR', timezoneId: 'America/Sao_Paulo',
    });
    if (!tela.guest) {
        await ctx.addCookies([{ name: COOKIE.name, value: COOKIE.value, url: BASE, httpOnly: true, sameSite: 'Lax' }]);
    }
    await ctx.addInitScript((standalone) => {
        try { localStorage.setItem('sm-cookie-consent', '1'); localStorage.setItem('sm-instalar-dispensado', '1'); } catch (e) { /* */ }
        if (standalone) {
            const mm = window.matchMedia.bind(window);
            window.matchMedia = (q) => (/display-mode:\s*standalone/.test(q)
                ? { matches: true, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {}, onchange: null, dispatchEvent() { return false; } }
                : mm(q));
            try { Object.defineProperty(navigator, 'standalone', { get: () => true }); } catch (e) { /* */ }
        }
    }, !!pwa);
    const page = await ctx.newPage();
    const erros = [];
    page.on('pageerror', (e) => erros.push(String(e.message || e)));
    try {
        if (pwa) {
            const s = await ctx.newCDPSession(page);
            await s.send('Emulation.setSafeAreaInsetsOverride', { insets: pwa.insets });
            await s.send('Emulation.setEmulatedMedia', { features: [{ name: 'display-mode', value: 'standalone' }] }).catch(() => {});
        }
        const url = typeof tela.url === 'function' ? await tela.url(page) : tela.url;
        const resp = await page.goto(BASE + url, { waitUntil: 'networkidle' });
        const status = resp ? resp.status() : 0;
        if (status !== (tela.status || 200) || (!tela.guest && new URL(page.url()).pathname.startsWith('/login'))) {
            return { achados: [{ tipo: 'erro-de-carga', detalhe: `${url} → ${status} ${page.url()}` }] };
        }
        await page.evaluate(() => document.fonts.ready);
        await page.waitForTimeout(250);
        let escopoSel = null;
        if (tela.preparar) escopoSel = (await tela.preparar(page)) || null;
        const r = await page.evaluate(analisar, { escopoSel, celular, insets: pwa ? pwa.insets : null });
        let achados = r.achados;
        if (!escopoSel && !tela.guest) achados = achados.concat(await page.evaluate(sobBarra));
        if (erros.length) achados.push({ tipo: 'erro-js', detalhe: [...new Set(erros)].join(' | ').slice(0, 300) });
        if (args.fotos && achados.length) {
            const nome = `${tela.id}-${pwa ? pwa.id : w}-${tema}.jpg`;
            await marcarEFotografar(page, achados, path.join(SAIDA, 'fotos', nome), escopoSel);
        }
        return { achados };
    } catch (e) {
        return { achados: [{ tipo: 'erro-da-varredura', detalhe: String(e.message || e).slice(0, 300) }] };
    } finally {
        await ctx.close();
    }
}

(async () => {
    const filtroL = args.larguras ? String(args.larguras).split(',').map(Number) : null;
    const filtroT = args.telas ? String(args.telas).split(',') : null;
    const temas = args.temas ? String(args.temas).split(',') : ['claro', 'escuro'];
    const casa = (id) => !filtroT || filtroT.some((f) => (f.endsWith('*') ? id.startsWith(f.slice(0, -1)) : id === f));
    if (args.fotos) fs.mkdirSync(path.join(SAIDA, 'fotos'), { recursive: true });

    const combos = [];
    for (const tela of TELAS.filter((t) => casa(t.id))) {
        for (const tema of temas) {
            if (!args['so-pwa']) {
                for (const [w, h] of LARGURAS) {
                    if (filtroL && !filtroL.includes(w)) continue;
                    if (tela.maxLargura && w > tela.maxLargura) continue;
                    combos.push({ tela, w, h, tema });
                }
            }
            if (args.pwa || args['so-pwa']) {
                for (const p of PERFIS_PWA) {
                    if (tela.maxLargura && p.w > tela.maxLargura) continue;
                    combos.push({ tela, w: p.w, h: p.h, tema, pwa: p });
                }
            }
        }
    }
    console.log(`${combos.length} combinações contra ${BASE}`);
    const browser = await chromium.launch();
    const resultados = [];
    let i = 0, feitos = 0;
    const t0 = Date.now();
    await Promise.all(Array.from({ length: PARALELO }, async () => {
        while (i < combos.length) {
            const c = combos[i++];
            const { achados } = await rodarCombinacao(browser, c);
            for (const a of achados) resultados.push({ tela: c.tela.id, largura: c.pwa ? c.pwa.id : `${c.w}×${c.h}`, tema: c.tema, ...a });
            if (++feitos % 50 === 0) process.stdout.write(`  ${feitos}/${combos.length} (${Math.round((Date.now() - t0) / 1000)}s)\n`);
        }
    }));
    await browser.close();

    fs.writeFileSync(path.join(SAIDA, 'achados.json'), JSON.stringify(resultados, null, 1));
    // Resumo: agrupa o mesmo achado (tipo + elemento) e lista onde aparece.
    const grupos = new Map();
    for (const r of resultados) {
        const k = `${r.tipo} | ${r.tela} | ${r.el || r.detalhe || ''}${r.com ? ' ⟷ ' + r.com : ''}${r.por ? ' ◀ ' + r.por : ''}`;
        if (!grupos.has(k)) grupos.set(k, new Set());
        grupos.get(k).add(`${r.largura}${r.tema === 'escuro' ? '/e' : ''}`);
    }
    const porTipo = {};
    for (const r of resultados) porTipo[r.tipo] = (porTipo[r.tipo] || 0) + 1;
    console.log('\nPor tipo:', porTipo);
    for (const [k, ls] of [...grupos].sort()) console.log(`- ${k}\n    em: ${[...ls].join(', ')}`);
    console.log(`\n${resultados.length} achados em ${combos.length} combinações (${Math.round((Date.now() - t0) / 1000)}s). Detalhes: ${path.join(SAIDA, 'achados.json')}`);
    process.exit(resultados.length ? 1 : 0);
})();
