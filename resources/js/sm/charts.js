/* ============ StabilMoney — Gráficos SVG (funções puras) ============ */
// Adaptado do protótipo (design/project/app.js): mesmas curvas, animações e
// interações, mas orientado a dados — nada de valores hardcoded aqui.

const $ = (s, c) => (c || document).querySelector(s);
const $$ = (s, c) => Array.from((c || document).querySelectorAll(s));

// Formata número no padrão pt-BR (idêntico ao protótipo)
export const BRL = (v, dec) =>
    v.toLocaleString('pt-BR', { minimumFractionDigits: dec ?? 2, maximumFractionDigits: dec ?? 2 });

/* ---------- SPARKLINES ---------- */

// Gera o "d" de um path ligando os valores normalizados dentro de w x h
export function sparkPath(vals, w, h, pad) {
    const min = Math.min(...vals), max = Math.max(...vals), rng = max - min || 1;
    return vals.map((v, i) => {
        const x = pad + (i / (vals.length - 1)) * (w - pad * 2);
        const y = h - pad - ((v - min) / rng) * (h - pad * 2);
        return (i ? 'L' : 'M') + x.toFixed(1) + ' ' + y.toFixed(1);
    }).join(' ');
}

let sparkSeq = 0;

// Desenha uma sparkline (área + linha animada) dentro do <svg> informado
export function drawSpark(svg, vals, color, reduceMotion, opts) {
    if (!svg || !Array.isArray(vals) || vals.length < 2) return;
    const w = (opts && opts.w) || 120;
    const h = (opts && opts.h) || 34;
    const pad = (opts && opts.pad) || 3;
    const id = 'sg-' + (svg.dataset.spark || svg.id || ++sparkSeq);
    const d = sparkPath(vals, w, h, pad);

    svg.innerHTML =
        `<defs><linearGradient id="${id}" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="${color}" stop-opacity=".22"/><stop offset="1" stop-color="${color}" stop-opacity="0"/></linearGradient></defs>` +
        `<path d="${d} L${w - pad} ${h} L${pad} ${h} Z" fill="url(#${id})"/>` +
        `<path d="${d}" fill="none" stroke="${color}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="spark-line"/>`;

    const line = $('.spark-line', svg);
    if (line && !reduceMotion) {
        const len = line.getTotalLength();
        line.style.strokeDasharray = len;
        line.style.strokeDashoffset = len;
        line.style.transition = 'stroke-dashoffset 1.2s var(--ease-out)';
        setTimeout(() => { line.style.strokeDashoffset = 0; }, 60);
    }
}

/* ---------- FLUXO DE CAIXA (área + linhas + pontos) ---------- */

const W = 760, H = 230, PAD_L = 8, PAD_R = 8, PAD_T = 16, PAD_B = 28;

// Desenha o gráfico de fluxo de caixa e devolve o estado usado pelo hover.
// `p` = { labels: [], receitas: [], despesas: [] }
export function buildCashflow(svg, p, reduceMotion) {
    if (!svg || !p || !Array.isArray(p.labels) || p.labels.length < 2) return null;

    svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
    const all = p.receitas.concat(p.despesas);
    const max = (Math.max(...all) || 1) * 1.12, min = 0; // "|| 1" evita NaN com tudo zerado
    const n = p.labels.length;
    const xAt = (i) => PAD_L + (i / (n - 1)) * (W - PAD_L - PAD_R);
    const yAt = (v) => H - PAD_B - ((v - min) / (max - min)) * (H - PAD_T - PAD_B);

    const lineOf = (arr) => arr.map((v, i) => (i ? 'L' : 'M') + xAt(i).toFixed(1) + ' ' + yAt(v).toFixed(1)).join(' ');
    const areaOf = (arr) => lineOf(arr) + ` L${xAt(n - 1).toFixed(1)} ${(H - PAD_B)} L${xAt(0).toFixed(1)} ${(H - PAD_B)} Z`;

    // linhas-guia + rótulos do eixo X
    let grid = '<g class="cf-grid">';
    for (let g = 0; g <= 4; g++) {
        const y = PAD_T + (g / 4) * (H - PAD_T - PAD_B);
        grid += `<line x1="${PAD_L}" y1="${y}" x2="${W - PAD_R}" y2="${y}"/>`;
    }
    grid += '</g>';
    let xlabels = '<g class="cf-axis">';
    p.labels.forEach((l, i) => { xlabels += `<text x="${xAt(i)}" y="${H - 8}" text-anchor="middle">${l}</text>`; });
    xlabels += '</g>';

    svg.innerHTML =
        `<defs><linearGradient id="cfArea" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#1C9A70" stop-opacity=".24"/><stop offset="1" stop-color="#1C9A70" stop-opacity="0"/></linearGradient></defs>` +
        grid + xlabels +
        `<path class="cf-area" d="${areaOf(p.receitas)}" fill="url(#cfArea)" opacity="1"/>` +
        `<line class="cf-guide" id="cfGuide" y1="${PAD_T}" y2="${H - PAD_B}"/>` +
        `<path class="cf-line" id="cfDesp" d="${lineOf(p.despesas)}" stroke="#F0A93B" stroke-dasharray="6 5"/>` +
        `<path class="cf-line" id="cfRec" d="${lineOf(p.receitas)}" stroke="#1C9A70"/>` +
        p.receitas.map((v, i) => `<circle class="cf-point" data-i="${i}" cx="${xAt(i)}" cy="${yAt(v)}" r="0" fill="#1C9A70" stroke="var(--surface)" stroke-width="2.5"/>`).join('') +
        `<circle class="cf-dot-d" cx="0" cy="0" r="0" fill="#F0A93B" stroke="var(--surface)" stroke-width="2.5" style="opacity:0"/>`;

    // anima o "desenho" das linhas
    [['#cfRec', 1], ['#cfDesp', 0]].forEach(([sel]) => {
        const line = $(sel, svg);
        if (!line) return;
        if (reduceMotion) { line.style.strokeDasharray = sel === '#cfDesp' ? '6 5' : 'none'; return; }
        const len = line.getTotalLength();
        line.style.strokeDasharray = len;
        line.style.strokeDashoffset = len;
        line.style.transition = 'none';
        setTimeout(() => {
            line.style.transition = 'stroke-dashoffset 1.2s var(--ease-out)';
            line.style.strokeDashoffset = 0;
            // limpa as props de dash depois do draw pro traço descansar nítido
            setTimeout(() => {
                line.style.strokeDasharray = sel === '#cfDesp' ? '6 5' : 'none';
                line.style.strokeDashoffset = '0';
            }, 1250);
        }, 60);
    });
    const area = $('.cf-area', svg);
    if (area) setTimeout(() => { area.style.transition = 'opacity .6s'; area.style.opacity = 1; }, reduceMotion ? 0 : 350);
    $$('.cf-point', svg).forEach((c, i) => setTimeout(() => { c.setAttribute('r', 3.2); }, reduceMotion ? 0 : 700 + i * 40));

    return { p, xAt, yAt, n };
}

// Liga o tooltip/guide do fluxo de caixa. `getState` devolve o estado atual
// (retorno de buildCashflow) — assim o redraw por período não exige rebind.
export function bindCashflowHover(wrap, svg, tip, getState) {
    if (!wrap || !svg || !tip) return;
    const guide = () => $('#cfGuide', svg);
    const dotD = () => $('.cf-dot-d', svg);

    function move(clientX) {
        const st = getState();
        if (!st || !guide() || !dotD()) return;
        const rect = svg.getBoundingClientRect();
        const rel = (clientX - rect.left) / rect.width * W;
        let i = Math.round((rel - PAD_L) / ((W - PAD_L - PAD_R) / (st.n - 1)));
        i = Math.max(0, Math.min(st.n - 1, i));
        const x = st.xAt(i);
        guide().setAttribute('x1', x); guide().setAttribute('x2', x); guide().style.opacity = 1;
        $$('.cf-point', svg).forEach((c) => c.setAttribute('r', +c.dataset.i === i ? 5.5 : 3.2));
        const dY = st.yAt(st.p.despesas[i]);
        dotD().setAttribute('cx', x); dotD().setAttribute('cy', dY); dotD().setAttribute('r', 4.5); dotD().style.opacity = 1;
        tip.innerHTML =
            `<div class="tt">${st.p.labels[i]}</div>` +
            `<div class="row"><span class="d" style="background:var(--brand-300)"></span><span class="n">Receitas</span><span class="v">R$ ${BRL(st.p.receitas[i])}</span></div>` +
            `<div class="row"><span class="d" style="background:var(--c-saude)"></span><span class="n">Despesas</span><span class="v">R$ ${BRL(st.p.despesas[i])}</span></div>`;
        const px = x / W * svg.getBoundingClientRect().width;
        tip.style.left = px + 'px';
        tip.style.top = (st.yAt(Math.max(st.p.receitas[i], st.p.despesas[i])) / H * svg.getBoundingClientRect().height) + 'px';
        tip.classList.add('show');
    }
    function leave() {
        tip.classList.remove('show');
        if (guide()) guide().style.opacity = 0;
        $$('.cf-point', svg).forEach((c) => c.setAttribute('r', 3.2));
        if (dotD()) dotD().style.opacity = 0;
    }
    wrap.addEventListener('mousemove', (e) => move(e.clientX));
    wrap.addEventListener('mouseleave', leave);
    wrap.addEventListener('touchstart', (e) => move(e.touches[0].clientX), { passive: true });
    wrap.addEventListener('touchmove', (e) => move(e.touches[0].clientX), { passive: true });
    wrap.addEventListener('touchend', leave);
}

/* ---------- DONUT (gastos por categoria) ---------- */

// Texto do centro quando nada está focado (ex.: "R$ 6,1k")
function donutTotalLabel(total) {
    if (total >= 1000) return `R$ ${(total / 1000).toFixed(1).replace('.', ',')}k`;
    return `R$ ${BRL(total, 0)}`;
}

// Desenha o donut + legenda interativa. `cats` = [{ name, value, color }]
export function buildDonut(svg, legend, cats) {
    if (!svg || !legend || !Array.isArray(cats) || !cats.length) return;
    const total = cats.reduce((s, c) => s + c.value, 0);
    if (total <= 0) return;

    const R = 46, C = 60;
    const polar = (deg) => { const a = (deg - 90) * Math.PI / 180; return [C + R * Math.cos(a), C + R * Math.sin(a)]; };
    const arc = (d0, d1) => {
        const [x0, y0] = polar(d0), [x1, y1] = polar(d1);
        const large = (d1 - d0) > 180 ? 1 : 0;
        return `M${x0.toFixed(2)} ${y0.toFixed(2)} A${R} ${R} 0 ${large} 1 ${x1.toFixed(2)} ${y1.toFixed(2)}`;
    };
    const GAP = 2; // graus entre os segmentos
    let acc = 0;
    svg.innerHTML =
        `<circle cx="${C}" cy="${C}" r="${R}" fill="none" stroke="var(--surface-3)" stroke-width="13"/>` +
        cats.map((c, i) => {
            const span = (c.value / total) * 360;
            const d0 = acc + GAP / 2, d1 = acc + span - GAP / 2;
            acc += span;
            return `<path class="donut-seg" data-i="${i}" d="${arc(d0, Math.max(d0 + 0.1, d1))}" fill="none" stroke="${c.color}" stroke-width="13" stroke-linecap="round"/>`;
        }).join('') +
        `<g class="donut-center" text-anchor="middle"><text class="dc-amt" x="${C}" y="${C - 2}">${donutTotalLabel(total)}</text><text class="dc-lbl" x="${C}" y="${C + 14}">Total no mês</text></g>`;

    legend.innerHTML = cats.map((c, i) =>
        `<div class="cat-row" data-i="${i}"><span class="cd" style="background:${c.color}"></span><span class="cn">${c.name}</span><span class="cv">R$ ${BRL(c.value, 0)}</span><span class="cp">${Math.round(c.value / total * 100)}%</span></div>`
    ).join('');

    const amtEl = $('.dc-amt', svg), lblEl = $('.dc-lbl', svg);
    // Sincroniza segmento <-> linha da legenda e troca o texto do centro
    function focus(i) {
        $$('.donut-seg', svg).forEach((s) => {
            const on = +s.dataset.i === i;
            s.style.strokeWidth = on ? 17 : 13;
            s.style.opacity = i == null || on ? 1 : .35;
        });
        $$('.cat-row', legend).forEach((r) => r.classList.toggle('hot', +r.dataset.i === i));
        if (i == null) { amtEl.textContent = donutTotalLabel(total); lblEl.textContent = 'Total no mês'; }
        else { amtEl.textContent = `R$ ${BRL(cats[i].value, 0)}`; lblEl.textContent = cats[i].name; }
    }
    $$('.donut-seg', svg).forEach((s) => {
        s.addEventListener('mouseenter', () => focus(+s.dataset.i));
        s.addEventListener('mouseleave', () => focus(null));
    });
    $$('.cat-row', legend).forEach((r) => {
        r.addEventListener('mouseenter', () => focus(+r.dataset.i));
        r.addEventListener('mouseleave', () => focus(null));
    });
}
