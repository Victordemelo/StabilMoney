/* ============ StabilMoney — Dashboard (consome o JSON embutido) ============ */
// Só roda se a página tiver #sm-dashboard-data (contrato definido na view do
// dashboard). Anima contadores, desenha sparklines/fluxo de caixa/donut e
// controla o segmented de período SEM reload.

import { BRL, drawSpark, buildCashflow, bindCashflowHover, buildDonut } from './charts';

const $$ = (s, c) => Array.from((c || document).querySelectorAll(s));

// Cores das sparklines por indicador (mesmas do protótipo)
const SPARK_COLORS = {
    saldo: 'var(--brand-500)',
    receitas: 'var(--c-alimentacao)',
    despesas: 'var(--c-saude)',
    economia: 'var(--c-lazer)',
};

// Ordem dos cards de stat na grade (mesma do protótipo)
const STAT_ORDER = ['saldo', 'receitas', 'despesas', 'economia'];

const ARROW_UP = 'M7 17 17 7M17 7h-7M17 7v7';
const ARROW_DOWN = 'M7 7 17 17M17 17h-7M17 17v-7';

/* ---------- Contadores animados ---------- */

// O sinal de menos vive num <span class="sign"> ANTES do "R$", não colado no
// número: a regra do app é `−R$ 1.234,56` (traço U+2212 antes do símbolo), nunca
// `R$ -1.234,56`. Por isso o contador anima o valor ABSOLUTO e só liga/desliga o
// sinal — senão o valor abria formatado certo pelo servidor e "pulava" para o
// formato errado no primeiro clique do segmented.
const MENOS = '\u2212';

function pintarSinal(el, valor) {
    const caixa = el.closest('.value');
    const sinal = caixa && caixa.querySelector('.sign');
    if (sinal) sinal.textContent = valor < 0 ? MENOS : '';
    if (caixa) caixa.classList.toggle('neg', valor < 0);
}

function animateCount(el, to, dec, reduceMotion) {
    pintarSinal(el, to);
    if (reduceMotion) { el.textContent = BRL(Math.abs(to), dec); return; }
    const from = parseFloat(el.dataset.from || '0');
    el.dataset.from = to;
    const dur = 1100, t0 = performance.now();
    const ease = (t) => 1 - Math.pow(1 - t, 3);
    let done = false;
    function step(now) {
        const p = Math.min(1, (now - t0) / dur);
        const atual = from + (to - from) * ease(p);
        // O sinal acompanha a animação: um valor que cruza o zero troca de cor e
        // de sinal no meio do caminho, em vez de mentir até o fim.
        pintarSinal(el, atual);
        el.textContent = BRL(Math.abs(atual), dec);
        if (p < 1) requestAnimationFrame(step); else done = true;
    }
    requestAnimationFrame(step);
    // Fallback: timers disparam mesmo com rAF estrangulado — garante o valor final
    setTimeout(() => { if (!done) { pintarSinal(el, to); el.textContent = BRL(Math.abs(to), dec); } }, dur + 80);
}

function runCounters(scope, reduceMotion) {
    $$('[data-count]', scope).forEach((el) =>
        animateCount(el, parseFloat(el.dataset.count), parseInt(el.dataset.dec || '2'), reduceMotion));
}

/* ---------- Período (stats, trends, sub e cashflow) ---------- */

// Variação percentual no padrão pt-BR (ex.: "4,2")
const pct = (v) => Math.abs(v).toLocaleString('pt-BR', { maximumFractionDigits: 1 });

function applyPeriod(data, period, drawCf, reduceMotion) {
    const p = data.periods && data.periods[period];
    if (!p) return;

    const sub = document.getElementById('periodSub');
    if (sub) sub.textContent = p.sub;

    // Stats: mapeia pela ordem dos cards (saldo, receitas, despesas, economia)
    $$('.stat .num').forEach((el, i) => {
        const k = STAT_ORDER[i];
        if (k && p.stats && p.stats[k] != null) {
            el.dataset.count = p.stats[k];
            animateCount(el, p.stats[k], 2, reduceMotion);
            // Valor negativo fica vermelho (mesma regra do server-render).
            const box = el.closest('.value');
            if (box) box.classList.toggle('neg', p.stats[k] < 0);
        }
    });

    // Trends: null => "—" neutro (sem período anterior para comparar)
    $$('[data-trend]').forEach((el) => {
        const k = el.dataset.trend;
        const val = p.trends ? p.trends[k] : null;
        if (val == null) {
            el.className = 'trend neutral';
            el.textContent = '—';
            return;
        }
        // Duas decisões separadas (espelha o Blade):
        //  - `subiu` = o que aconteceu com o número → escolhe a FLECHA.
        //  - `bom`   = se isso é bom → escolhe a COR (em despesas, cair é bom).
        // Unificar as duas fazia a flecha apontar para baixo quando a despesa subia.
        const subiu = val >= 0;
        const bom = (k === 'despesas') ? val < 0 : val >= 0;
        el.className = 'trend ' + (bom ? 'up' : 'down');
        el.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="${subiu ? ARROW_UP : ARROW_DOWN}"/></svg>${pct(val)}%`;
    });

    drawCf(period);
}

function initPeriod(data, drawCf, reduceMotion) {
    const seg = document.getElementById('period');
    const pill = document.getElementById('segPill');
    if (!seg || !pill) return;

    const btns = $$('button[data-p]', seg);
    if (!btns.length) return;

    // Pílula animada acompanha o botão ativo.
    // O deslocamento é a diferença entre a posição de LAYOUT do botão e a da
    // própria pílula (offsetLeft ignora transform, então serve de referência
    // estável). Assim a pílula cobre o botão exatamente, sem depender de
    // padding/borda do trilho — o desconto fixo de 4px que havia aqui a deixava
    // desalinhada, com folga desigual dos dois lados.
    const setPill = (btn) => {
        pill.style.width = btn.offsetWidth + 'px';
        pill.style.transform = `translateX(${btn.offsetLeft - pill.offsetLeft}px)`;
    };

    btns.forEach((b) => b.addEventListener('click', () => {
        btns.forEach((x) => x.classList.remove('active'));
        b.classList.add('active');
        setPill(b);
        applyPeriod(data, b.dataset.p, drawCf, reduceMotion);
    }));

    const active = seg.querySelector('button.active') || btns[0];
    requestAnimationFrame(() => setPill(active));
    window.addEventListener('resize', () => {
        const a = seg.querySelector('button.active');
        if (a) setPill(a);
    });
}

/* ---------- Init ---------- */

export function initDashboard() {
    const dataEl = document.getElementById('sm-dashboard-data');
    if (!dataEl) return; // não é a página do dashboard

    let data;
    try { data = JSON.parse(dataEl.textContent); } catch (e) { return; }
    if (!data || !data.periods) return;

    const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Sparklines dos stat cards (arrays vazios não desenham)
    $$('[data-spark]').forEach((svg) => {
        const key = svg.dataset.spark;
        const vals = (data.sparks && data.sparks[key]) || [];
        if (vals.length > 1) drawSpark(svg, vals, SPARK_COLORS[key] || 'var(--brand-500)', reduceMotion);
    });

    // Fluxo de caixa (redesenhado a cada troca de período)
    const cfSvg = document.getElementById('cfSvg');
    const cfWrap = document.getElementById('cfWrap');
    const cfTip = document.getElementById('cfTip');
    let cfState = null;
    const drawCf = (period) => {
        if (!cfSvg) return;
        const p = data.periods[period];
        if (p) cfState = buildCashflow(cfSvg, p, reduceMotion);
    };
    if (cfWrap && cfSvg && cfTip) bindCashflowHover(cfWrap, cfSvg, cfTip, () => cfState);

    // Donut de gastos por categoria (se cats = [], o Blade nem renderiza o container)
    buildDonut(document.getElementById('donut'), document.getElementById('catLegend'), data.cats || []);

    // Contadores de todos os [data-count] da página
    runCounters(document, reduceMotion);

    // Período inicial = botão ativo do segmented (o servidor marca qual é,
    // via DashboardService::DEFAULT_PERIOD; hoje "semana")
    const seg = document.getElementById('period');
    const activeBtn = seg && seg.querySelector('button.active');
    const initial = (activeBtn && activeBtn.dataset.p) || 'semana';
    initPeriod(data, drawCf, reduceMotion);
    applyPeriod(data, initial, drawCf, reduceMotion);
}

