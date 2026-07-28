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

// Desenha o gráfico de fluxo de caixa (BARRAS AGRUPADAS) e devolve o estado
// usado pelo hover. `p` = { labels: [], receitas: [], despesas: [] }
//
// Por que barras e não linhas: com poucos períodos (ex.: 4 semanas) e valores
// esparsos, a linha vira um "pico" pontiagudo que engana a leitura. Duas barras
// por período (receitas x despesas) comparam direto e nunca distorcem.
export function buildCashflow(svg, p, reduceMotion) {
    if (!svg || !p || !Array.isArray(p.labels) || !p.labels.length) return null;

    svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
    const all = p.receitas.concat(p.despesas);
    const max = (Math.max(...all) || 1) * 1.12; // "|| 1" evita divisão por zero com tudo zerado
    const n = p.labels.length;

    const plotW = W - PAD_L - PAD_R;
    const base = H - PAD_B;                 // y da linha de base (valor zero)
    const groupW = plotW / n;               // faixa horizontal de cada período
    const GAP = 5;                          // respiro entre a barra de receita e a de despesa
    // Barra confortável, limitada para não engordar quando há poucos períodos
    const barW = Math.max(7, Math.min(26, (groupW - GAP) / 2 - 8));
    const centerAt = (i) => PAD_L + groupW * (i + 0.5);
    const yAt = (v) => base - (Math.max(0, v) / max) * (H - PAD_T - PAD_B);
    const xAt = centerAt; // compat: o hover posiciona o tooltip pelo centro do grupo

    // linhas-guia horizontais + rótulos do eixo X
    let grid = '<g class="cf-grid">';
    for (let g = 0; g <= 4; g++) {
        const y = PAD_T + (g / 4) * (H - PAD_T - PAD_B);
        grid += `<line x1="${PAD_L}" y1="${y}" x2="${W - PAD_R}" y2="${y}"/>`;
    }
    grid += '</g>';

    let xlabels = '<g class="cf-axis">';
    p.labels.forEach((l, i) => { xlabels += `<text x="${centerAt(i).toFixed(1)}" y="${H - 8}" text-anchor="middle">${l}</text>`; });
    xlabels += '</g>';

    // Realce do período sob o cursor (fica atrás das barras)
    const hover = `<rect class="cf-hover" id="cfHover" x="0" y="${PAD_T}" width="${groupW.toFixed(1)}" height="${(base - PAD_T).toFixed(1)}" rx="10" style="opacity:0"/>`;

    // Uma dupla de barras por período. Começam com altura 0 e crescem (anima).
    const barras = p.labels.map((_, i) => {
        const cx = centerAt(i);
        const xRec = cx - GAP / 2 - barW;
        const xDesp = cx + GAP / 2;
        const rec = `<rect class="cf-bar cf-bar-rec" data-i="${i}" x="${xRec.toFixed(1)}" y="${base}" width="${barW.toFixed(1)}" height="0" rx="${Math.min(5, barW / 2).toFixed(1)}" fill="#1C9A70"/>`;
        const desp = `<rect class="cf-bar cf-bar-desp" data-i="${i}" x="${xDesp.toFixed(1)}" y="${base}" width="${barW.toFixed(1)}" height="0" rx="${Math.min(5, barW / 2).toFixed(1)}" fill="#F0A93B"/>`;
        return rec + desp;
    }).join('');

    // Linha de base sólida, para as barras "descansarem" sobre algo
    const baseline = `<line class="cf-baseline" x1="${PAD_L}" y1="${base}" x2="${W - PAD_R}" y2="${base}"/>`;

    svg.innerHTML = grid + hover + xlabels + baseline + barras;

    // Anima o crescimento de cada barra (de baixo para cima), em cascata
    const crescer = (el, valor, atraso) => {
        const alturaFinal = Math.max(0, base - yAt(valor));
        const aplicar = () => {
            el.setAttribute('y', yAt(valor).toFixed(1));
            el.setAttribute('height', alturaFinal.toFixed(1));
        };
        if (reduceMotion) { aplicar(); return; }
        el.style.transition = 'y .55s var(--ease-out), height .55s var(--ease-out)';
        setTimeout(aplicar, atraso);
    };
    $$('.cf-bar-rec', svg).forEach((el, i) => crescer(el, p.receitas[i], 60 + i * 55));
    $$('.cf-bar-desp', svg).forEach((el, i) => crescer(el, p.despesas[i], 110 + i * 55));

    return { p, xAt, yAt, n, groupW, centerAt };
}

// Liga o tooltip/guide do fluxo de caixa. `getState` devolve o estado atual
// (retorno de buildCashflow) — assim o redraw por período não exige rebind.
export function bindCashflowHover(wrap, svg, tip, getState) {
    if (!wrap || !svg || !tip) return;
    const realce = () => $('#cfHover', svg);

    function move(clientX) {
        const st = getState();
        if (!st || !realce()) return;
        const rect = svg.getBoundingClientRect();
        const rel = (clientX - rect.left) / rect.width * W;
        // Índice do GRUPO de barras sob o cursor (faixa de largura groupW)
        let i = Math.floor((rel - PAD_L) / st.groupW);
        i = Math.max(0, Math.min(st.n - 1, i));

        // Realça a faixa do período e apaga levemente as barras dos outros
        realce().setAttribute('x', (PAD_L + st.groupW * i).toFixed(1));
        realce().style.opacity = 1;
        $$('.cf-bar', svg).forEach((b) => { b.style.opacity = +b.dataset.i === i ? 1 : .38; });

        tip.innerHTML =
            `<div class="tt">${st.p.labels[i]}</div>` +
            `<div class="row"><span class="d" style="background:#1C9A70"></span><span class="n">Receitas</span><span class="v">R$ ${BRL(st.p.receitas[i])}</span></div>` +
            `<div class="row"><span class="d" style="background:#F0A93B"></span><span class="n">Despesas</span><span class="v">R$ ${BRL(st.p.despesas[i])}</span></div>`;
        // Posição: o tooltip fica DENTRO do gráfico e se ajusta para nunca ser
        // cortado. Horizontalmente segue o grupo, mas é "empurrado" de volta ao
        // encostar numa borda. Verticalmente fica no alto da área de plotagem,
        // onde as barras (que crescem de baixo) não chegam.
        //
        // O clamp é feito nas coordenadas do WRAP, que é o offsetParent do
        // tooltip — medir pelo SVG deixava o primeiro ponto 2px para fora.
        const wrapRect = wrap.getBoundingClientRect();
        const deslocaSvg = rect.left - wrapRect.left; // svg pode ter recuo dentro do wrap
        const meio = (tip.offsetWidth || 160) / 2;
        const margem = 8;

        let px = deslocaSvg + (st.centerAt(i) / W) * rect.width;
        px = Math.max(meio + margem, Math.min(wrapRect.width - meio - margem, px));

        tip.style.left = px + 'px';
        tip.style.top = (rect.top - wrapRect.top) + (PAD_T / H) * rect.height + 'px';
        tip.classList.add('show');
    }
    function leave() {
        tip.classList.remove('show');
        if (realce()) realce().style.opacity = 0;
        $$('.cf-bar', svg).forEach((b) => { b.style.opacity = 1; });
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
            // Categoria fixa sem gasto no período: entra só na legenda. Sem isto
            // o arco mínimo (0,1°) com linecap round viraria uma bolinha no donut.
            if (!(c.value > 0)) return '';
            const span = (c.value / total) * 360;
            const d0 = acc + GAP / 2, d1 = acc + span - GAP / 2;
            acc += span;
            return `<path class="donut-seg" data-i="${i}" d="${arc(d0, Math.max(d0 + 0.1, d1))}" fill="none" stroke="${c.color}" stroke-width="13" stroke-linecap="round"/>`;
        }).join('') +
        `<g class="donut-center" text-anchor="middle"><text class="dc-amt" x="${C}" y="${C - 2}">${donutTotalLabel(total)}</text><text class="dc-lbl" x="${C}" y="${C + 14}">Total no mês</text></g>`;

    // Legenda lista TODAS (inclusive as fixas zeradas, marcadas com .is-zero).
    // Montada com createElement + textContent, NÃO com innerHTML: `c.name` é o nome
    // da categoria, texto livre do usuário, e categorias são compartilhadas na família
    // — interpolar isso em HTML deixava um dependente executar script no dashboard do
    // titular (ex.: categoria chamada `<img src=x onerror=...>`).
    legend.replaceChildren(...cats.map((c, i) => {
        const row = document.createElement('div');
        row.className = 'cat-row' + (c.value > 0 ? '' : ' is-zero');
        row.dataset.i = i;

        const dot = document.createElement('span');
        dot.className = 'cd';
        dot.style.background = c.color;

        const nome = document.createElement('span');
        nome.className = 'cn';
        nome.textContent = c.name;

        const valor = document.createElement('span');
        valor.className = 'cv';
        valor.textContent = `R$ ${BRL(c.value, 0)}`;

        const pct = document.createElement('span');
        pct.className = 'cp';
        pct.textContent = `${Math.round(c.value / total * 100)}%`;

        row.append(dot, nome, valor, pct);
        return row;
    }));

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
