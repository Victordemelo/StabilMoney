/* ============ StabilMoney — App logic ============ */
(function () {
  'use strict';

  const $ = (s, c) => (c || document).querySelector(s);
  const $$ = (s, c) => Array.from((c || document).querySelectorAll(s));
  const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;

  const BRL = (v, dec) => v.toLocaleString('pt-BR', { minimumFractionDigits: dec ?? 2, maximumFractionDigits: dec ?? 2 });

  /* ---------- DATA ---------- */
  const PERIODS = {
    semana: {
      sub: 'Esta semana',
      labels: ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'],
      receitas: [320, 180, 540, 260, 1180, 90, 60],
      despesas: [210, 340, 180, 290, 410, 520, 160],
      stats: { saldo: 48250, receitas: 2630, despesas: 2110, economia: 520 },
      trends: { saldo: 1.2, receitas: 5.4, despesas: -3.1, economia: 9 },
    },
    mes: {
      sub: 'Junho de 2026',
      labels: ['Sem 1', 'Sem 2', 'Sem 3', 'Sem 4'],
      receitas: [2480, 3120, 1840, 2400],
      despesas: [1620, 1380, 1720, 1400],
      stats: { saldo: 48250, receitas: 9840, despesas: 6120, economia: 3720 },
      trends: { saldo: 4.2, receitas: 8.1, despesas: -2.3, economia: 12 },
    },
    ano: {
      sub: '2026',
      labels: ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'],
      receitas: [8200, 7600, 9100, 8800, 9400, 9840, 0, 0, 0, 0, 0, 0].map((v, i) => v || [0, 0, 0, 0, 0, 0, 9200, 8700, 9600, 10100, 9800, 11200][i]),
      despesas: [6100, 5800, 6400, 6000, 6300, 6120, 5900, 6200, 6050, 6400, 6700, 7100],
      stats: { saldo: 48250, receitas: 109740, despesas: 75070, economia: 34670 },
      trends: { saldo: 18.5, receitas: 11.2, despesas: 4.8, economia: 22 },
    },
  };

  const CATS = [
    { name: 'Moradia', value: 2180, color: '#0F6B47' },
    { name: 'Alimentação', value: 1460, color: '#1FA06E' },
    { name: 'Transporte', value: 880, color: '#59C497' },
    { name: 'Lazer', value: 720, color: '#18B6BE' },
    { name: 'Saúde', value: 540, color: '#F0A93B' },
    { name: 'Outros', value: 340, color: '#9FB0A7' },
  ];

  const ICONS = {
    cart: '<path d="M5 7h14l-1.2 8.5a2 2 0 0 1-2 1.7H8.2a2 2 0 0 1-2-1.7L5 7Z"/><path d="M9 7a3 3 0 0 1 6 0M9 11v2M15 11v2"/>',
    food: '<path d="M5 3v8a2 2 0 0 0 2 2v8M5 3v4M8 3v4M19 3c-1.5 0-2.5 2-2.5 5s1 4 1 4v8"/>',
    car: '<path d="M5 13l1.5-4.5A2 2 0 0 1 8.4 7h7.2a2 2 0 0 1 1.9 1.5L19 13v5h-3v-2H8v2H5v-5Z"/><circle cx="8" cy="15.5" r="0"/>',
    salary: '<rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/>',
    home: '<path d="M4 11l8-6 8 6M6 10v9h12v-9"/>',
    play: '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="M11 9l4 3-4 3V9Z"/>',
    health: '<path d="M12 21s-7-4.5-7-10a4 4 0 0 1 7-2.5A4 4 0 0 1 19 11c0 5.5-7 10-7 10Z"/>',
    bolt: '<path d="M13 3 5 13h6l-1 8 8-10h-6l1-8Z"/>',
    phone: '<rect x="7" y="3" width="10" height="18" rx="2.5"/><path d="M11 18h2"/>',
  };

  const TX = [
    { ico: 'salary', name: 'Salário — Acme Corp', cat: 'Receita', date: 'Hoje, 09:12', amt: 6800, pos: true },
    { ico: 'cart', name: 'Supermercado Pão de Açúcar', cat: 'Alimentação', date: 'Hoje, 08:40', amt: -284.7 },
    { ico: 'play', name: 'Netflix', cat: 'Assinatura', date: 'Ontem, 21:10', amt: -55.9 },
    { ico: 'car', name: 'Posto Shell', cat: 'Transporte', date: 'Ontem, 18:22', amt: -210 },
    { ico: 'home', name: 'Aluguel — Apto 72', cat: 'Moradia', date: '07 jun', amt: -1850 },
    { ico: 'food', name: 'iFood — Almoço', cat: 'Alimentação', date: '06 jun', amt: -47.8 },
    { ico: 'health', name: 'Farmácia Drogasil', cat: 'Saúde', date: '05 jun', amt: -92.3 },
    { ico: 'bolt', name: 'Conta de luz — Enel', cat: 'Moradia', date: '04 jun', amt: -176.4 },
  ];

  const GOALS = [
    { ico: '✈️', name: 'Viagem ao Japão', sub: 'Meta para Dez 2026', cur: 8400, tgt: 15000, color: 'var(--c-lazer)' },
    { ico: '🏠', name: 'Entrada do apê', sub: 'Reserva imóvel', cur: 32000, tgt: 60000, color: 'var(--brand-600)' },
    { ico: '🛡️', name: 'Reserva de emergência', sub: '6 meses de custos', cur: 18600, tgt: 24000, color: 'var(--c-alimentacao)' },
  ];

  const BILLS = [
    { day: 12, mon: 'Jun', name: 'Cartão StabilMoney', cat: 'Fatura', amt: 2480.5, status: 'soon', due: 'vence em 3 dias' },
    { day: 15, mon: 'Jun', name: 'Internet — Vivo Fibra', cat: 'Serviços', amt: 119.9, status: '', due: 'vence em 6 dias' },
    { day: 20, mon: 'Jun', name: 'Plano de saúde', cat: 'Saúde', amt: 540, status: '', due: 'vence em 11 dias' },
    { day: 8, mon: 'Jun', name: 'Academia SmartFit', cat: 'Bem-estar', amt: 99.9, status: 'late', due: 'atrasada' },
  ];

  const INV = [
    { l: 'TS', name: 'Tesouro Selic 2029', sub: 'Renda fixa', val: 32400, chg: 0.42, color: 'var(--brand-600)' },
    { l: 'BV', name: 'BOVA11', sub: 'ETF Ibovespa', val: 21800, chg: 1.84, color: 'var(--c-lazer)' },
    { l: 'IV', name: 'IVVB11', sub: 'ETF S&P 500', val: 18200, chg: -0.63, color: 'var(--c-saude)', neg: true },
    { l: 'CR', name: 'Cripto', sub: 'BTC + ETH', val: 11810, chg: 3.21, color: 'var(--c-alimentacao)' },
  ];

  /* ---------- COUNTERS ---------- */
  function animateCount(el, to, dec) {
    if (reduceMotion) { el.textContent = BRL(to, dec); return; }
    const from = parseFloat((el.dataset.from || '0')); el.dataset.from = to;
    const dur = 1100, t0 = performance.now();
    const ease = (t) => 1 - Math.pow(1 - t, 3);
    let done = false;
    function step(now) {
      const p = Math.min(1, (now - t0) / dur);
      el.textContent = BRL(from + (to - from) * ease(p), dec);
      if (p < 1) requestAnimationFrame(step); else done = true;
    }
    requestAnimationFrame(step);
    // Fallback: timers fire even when rAF is throttled — guarantee final value
    setTimeout(() => { if (!done) el.textContent = BRL(to, dec); }, dur + 80);
  }
  function runCounters(scope) {
    $$('[data-count]', scope).forEach(el => animateCount(el, parseFloat(el.dataset.count), parseInt(el.dataset.dec || '2')));
  }

  /* ---------- SPARKLINES ---------- */
  function sparkPath(vals, w, h, pad) {
    const min = Math.min(...vals), max = Math.max(...vals), rng = max - min || 1;
    return vals.map((v, i) => {
      const x = pad + (i / (vals.length - 1)) * (w - pad * 2);
      const y = h - pad - ((v - min) / rng) * (h - pad * 2);
      return (i ? 'L' : 'M') + x.toFixed(1) + ' ' + y.toFixed(1);
    }).join(' ');
  }
  function drawSparks() {
    const data = {
      saldo: [40, 42, 41, 44, 43, 46, 48], receitas: [6, 7, 6.5, 8, 7.5, 9, 9.8],
      despesas: [7, 6.5, 7.2, 6, 6.8, 6.4, 6.1], economia: [1.5, 2, 1.8, 2.6, 3, 3.4, 3.7],
    };
    const colors = { saldo: 'var(--brand-500)', receitas: 'var(--c-alimentacao)', despesas: 'var(--c-saude)', economia: 'var(--c-lazer)' };
    $$('[data-spark]').forEach(svg => {
      const key = svg.dataset.spark, vals = data[key] || data.saldo;
      const d = sparkPath(vals, 120, 34, 3);
      const col = colors[key];
      svg.innerHTML =
        `<defs><linearGradient id="sg-${key}" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="${col}" stop-opacity=".22"/><stop offset="1" stop-color="${col}" stop-opacity="0"/></linearGradient></defs>` +
        `<path d="${d} L117 34 L3 34 Z" fill="url(#sg-${key})"/>` +
        `<path d="${d}" fill="none" stroke="${col}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="spark-line"/>`;
      const line = $('.spark-line', svg);
      if (line && !reduceMotion) {
        const len = line.getTotalLength();
        line.style.strokeDasharray = len; line.style.strokeDashoffset = len;
        line.style.transition = 'stroke-dashoffset 1.2s var(--ease-out)';
        setTimeout(() => { line.style.strokeDashoffset = 0; }, 60);
      }
    });
  }

  /* ---------- CASHFLOW CHART ---------- */
  const W = 760, H = 230, PAD_L = 8, PAD_R = 8, PAD_T = 16, PAD_B = 28;
  let cfState = null;

  function buildCashflow(period) {
    const p = PERIODS[period];
    const svg = $('#cfSvg'); svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
    const all = p.receitas.concat(p.despesas);
    const max = Math.max(...all) * 1.12, min = 0;
    const n = p.labels.length;
    const xAt = i => PAD_L + (i / (n - 1)) * (W - PAD_L - PAD_R);
    const yAt = v => H - PAD_B - ((v - min) / (max - min)) * (H - PAD_T - PAD_B);

    const lineOf = arr => arr.map((v, i) => (i ? 'L' : 'M') + xAt(i).toFixed(1) + ' ' + yAt(v).toFixed(1)).join(' ');
    const areaOf = arr => lineOf(arr) + ` L${xAt(n - 1).toFixed(1)} ${(H - PAD_B)} L${xAt(0).toFixed(1)} ${(H - PAD_B)} Z`;

    // gridlines + labels
    let grid = '<g class="cf-grid">';
    for (let g = 0; g <= 4; g++) { const y = PAD_T + (g / 4) * (H - PAD_T - PAD_B); grid += `<line x1="${PAD_L}" y1="${y}" x2="${W - PAD_R}" y2="${y}"/>`; }
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

    cfState = { p, xAt, yAt, n };

    // animate draw
    [['#cfRec', 1], ['#cfDesp', 0]].forEach(([sel, delay]) => {
      const line = $(sel, svg); if (!line) return;
      if (reduceMotion) { line.style.strokeDasharray = sel === '#cfDesp' ? '6 5' : 'none'; return; }
      const len = line.getTotalLength();
      line.style.strokeDasharray = len;
      line.style.strokeDashoffset = len;
      line.style.transition = 'none';
      setTimeout(() => {
        line.style.transition = 'stroke-dashoffset 1.2s var(--ease-out)';
        line.style.strokeDashoffset = 0;
        // clean up dash props after the draw so the resting stroke renders crisply
        setTimeout(() => { line.style.strokeDasharray = sel === '#cfDesp' ? '6 5' : 'none'; line.style.strokeDashoffset = '0'; }, 1250);
      }, 60);
    });
    const area = $('.cf-area', svg);
    setTimeout(() => { area.style.transition = 'opacity .6s'; area.style.opacity = 1; }, reduceMotion ? 0 : 350);
    $$('.cf-point', svg).forEach((c, i) => setTimeout(() => { c.setAttribute('r', 3.2); }, reduceMotion ? 0 : 700 + i * 40));
  }

  function bindCashflowHover() {
    const wrap = $('#cfWrap'), svg = $('#cfSvg'), tip = $('#cfTip');
    const guide = () => $('#cfGuide'), dotD = () => $('.cf-dot-d');
    function move(clientX) {
      if (!cfState) return;
      const rect = svg.getBoundingClientRect();
      const rel = (clientX - rect.left) / rect.width * W;
      let i = Math.round((rel - PAD_L) / ((W - PAD_L - PAD_R) / (cfState.n - 1)));
      i = Math.max(0, Math.min(cfState.n - 1, i));
      const x = cfState.xAt(i);
      guide().setAttribute('x1', x); guide().setAttribute('x2', x); guide().style.opacity = 1;
      $$('.cf-point', svg).forEach(c => c.setAttribute('r', +c.dataset.i === i ? 5.5 : 3.2));
      const dY = cfState.yAt(cfState.p.despesas[i]);
      dotD().setAttribute('cx', x); dotD().setAttribute('cy', dY); dotD().setAttribute('r', 4.5); dotD().style.opacity = 1;
      tip.innerHTML =
        `<div class="tt">${cfState.p.labels[i]}</div>` +
        `<div class="row"><span class="d" style="background:var(--brand-300)"></span><span class="n">Receitas</span><span class="v">R$ ${BRL(cfState.p.receitas[i])}</span></div>` +
        `<div class="row"><span class="d" style="background:var(--c-saude)"></span><span class="n">Despesas</span><span class="v">R$ ${BRL(cfState.p.despesas[i])}</span></div>`;
      const px = x / W * svg.getBoundingClientRect().width;
      tip.style.left = px + 'px';
      tip.style.top = (cfState.yAt(Math.max(cfState.p.receitas[i], cfState.p.despesas[i])) / H * svg.getBoundingClientRect().height) + 'px';
      tip.classList.add('show');
    }
    function leave() {
      tip.classList.remove('show'); guide().style.opacity = 0;
      $$('.cf-point', svg).forEach(c => c.setAttribute('r', 3.2));
      dotD().style.opacity = 0;
    }
    wrap.addEventListener('mousemove', e => move(e.clientX));
    wrap.addEventListener('mouseleave', leave);
    wrap.addEventListener('touchstart', e => move(e.touches[0].clientX), { passive: true });
    wrap.addEventListener('touchmove', e => move(e.touches[0].clientX), { passive: true });
    wrap.addEventListener('touchend', leave);
  }

  /* ---------- DONUT ---------- */
  function buildDonut() {
    const svg = $('#donut'), legend = $('#catLegend');
    const total = CATS.reduce((s, c) => s + c.value, 0);
    const R = 46, C = 60;
    const polar = (deg) => { const a = (deg - 90) * Math.PI / 180; return [C + R * Math.cos(a), C + R * Math.sin(a)]; };
    const arc = (d0, d1) => { const [x0, y0] = polar(d0), [x1, y1] = polar(d1); const large = (d1 - d0) > 180 ? 1 : 0; return `M${x0.toFixed(2)} ${y0.toFixed(2)} A${R} ${R} 0 ${large} 1 ${x1.toFixed(2)} ${y1.toFixed(2)}`; };
    const GAP = 2; // degrees between segments
    let acc = 0;
    svg.innerHTML =
      `<circle cx="${C}" cy="${C}" r="${R}" fill="none" stroke="var(--surface-3)" stroke-width="13"/>` +
      CATS.map((c, i) => {
        const span = (c.value / total) * 360;
        const d0 = acc + GAP / 2, d1 = acc + span - GAP / 2;
        acc += span;
        return `<path class="donut-seg" data-i="${i}" d="${arc(d0, Math.max(d0 + 0.1, d1))}" fill="none" stroke="${c.color}" stroke-width="13" stroke-linecap="round"/>`;
      }).join('') +
      `<g class="donut-center" text-anchor="middle"><text class="dc-amt" x="${C}" y="${C - 2}">R$ ${(total / 1000).toFixed(1)}k</text><text class="dc-lbl" x="${C}" y="${C + 14}">Total no mês</text></g>`;

    // legend
    legend.innerHTML = CATS.map((c, i) =>
      `<div class="cat-row" data-i="${i}"><span class="cd" style="background:${c.color}"></span><span class="cn">${c.name}</span><span class="cv">R$ ${BRL(c.value, 0)}</span><span class="cp">${Math.round(c.value / total * 100)}%</span></div>`
    ).join('');

    const amtEl = $('.dc-amt', svg), lblEl = $('.dc-lbl', svg);
    function focus(i) {
      $$('.donut-seg', svg).forEach(s => { const on = +s.dataset.i === i; s.style.strokeWidth = on ? 17 : 13; s.style.opacity = i == null || on ? 1 : .35; });
      $$('.cat-row', legend).forEach(r => r.classList.toggle('hot', +r.dataset.i === i));
      if (i == null) { amtEl.textContent = `R$ ${(total / 1000).toFixed(1)}k`; lblEl.textContent = 'Total no mês'; }
      else { amtEl.textContent = `R$ ${BRL(CATS[i].value, 0)}`; lblEl.textContent = CATS[i].name; }
    }
    $$('.donut-seg', svg).forEach(s => { s.addEventListener('mouseenter', () => focus(+s.dataset.i)); s.addEventListener('mouseleave', () => focus(null)); });
    $$('.cat-row', legend).forEach(r => { r.addEventListener('mouseenter', () => focus(+r.dataset.i)); r.addEventListener('mouseleave', () => focus(null)); });
  }

  /* ---------- LISTS ---------- */
  function txRow(t) {
    const sign = t.pos ? '+' : '−';
    return `<div class="tx"><div class="tx-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor">${ICONS[t.ico] || ICONS.cart}</svg></div>` +
      `<div><div class="tx-name">${t.name}</div><div class="tx-meta">${t.cat} · ${t.date}</div></div>` +
      `<div class="tx-amt ${t.pos ? 'pos' : ''}">${sign} R$ ${BRL(Math.abs(t.amt))}</div></div>`;
  }
  function buildLists() {
    $('#txList').innerHTML = TX.slice(0, 6).map(txRow).join('');
    $('#txListFull').innerHTML = TX.concat(TX).map(txRow).join('');

    $('#goalsList').innerHTML = GOALS.map(g => {
      const pct = Math.round(g.cur / g.tgt * 100);
      return `<div class="goal"><div class="goal-top"><div class="goal-ico">${g.ico}</div><div><div class="goal-name">${g.name}</div><div class="goal-sub">${g.sub}</div></div><div class="goal-pct">${pct}%</div></div>` +
        `<div class="bar"><i data-pct="${pct}" style="background:linear-gradient(90deg, ${g.color}, color-mix(in srgb, ${g.color} 60%, white))"></i></div>` +
        `<div class="goal-amt"><b>R$ ${BRL(g.cur, 0)}</b><span>de R$ ${BRL(g.tgt, 0)}</span></div></div>`;
    }).join('');

    $('#billsList').innerHTML = BILLS.map(b =>
      `<div class="bill"><div class="bd"><span class="day">${b.day}</span><span class="mon">${b.mon}</span></div>` +
      `<div><div class="bn">${b.name}</div><div class="bt">${b.cat} · <span class="due ${b.status}">${b.due}</span></div></div>` +
      `<div class="bv"><b>R$ ${BRL(b.amt)}</b><div class="pay">Pagar →</div></div></div>`
    ).join('');

    $('#invList').innerHTML = INV.map(v =>
      `<div class="inv"><div class="il" style="background:${v.color}">${v.l}</div>` +
      `<div><div class="in">${v.name}</div><div class="is">${v.sub}</div></div>` +
      `<div class="iv"><b>R$ ${BRL(v.val, 0)}</b><span class="${v.neg ? 'neg' : 'pos'}">${v.neg ? '▼' : '▲'} ${Math.abs(v.chg)}%</span></div></div>`
    ).join('');

    // inv spark
    const iv = [70, 72, 71, 74, 73, 76, 75, 78, 80, 79, 82, 84];
    const d = sparkPath(iv, 260, 44, 3);
    const sp = $('#invSpark');
    sp.innerHTML = `<defs><linearGradient id="ivg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="var(--brand-500)" stop-opacity=".24"/><stop offset="1" stop-color="var(--brand-500)" stop-opacity="0"/></linearGradient></defs>` +
      `<path d="${d} L257 44 L3 44 Z" fill="url(#ivg)"/><path d="${d}" fill="none" stroke="var(--brand-500)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" class="iv-line"/>`;
    const line = $('.iv-line', sp);
    if (line && !reduceMotion) { const len = line.getTotalLength(); line.style.strokeDasharray = len; line.style.strokeDashoffset = len; line.style.transition = 'stroke-dashoffset 1.4s var(--ease-out)'; setTimeout(() => { line.style.strokeDashoffset = 0; }, 60); }
  }
  function animateBars() {
    $$('.bar i').forEach((b, i) => setTimeout(() => { b.style.right = (100 - parseFloat(b.dataset.pct)) + '%'; }, reduceMotion ? 0 : 400 + i * 130));
  }

  /* ---------- STATS UPDATE ---------- */
  function applyPeriod(period) {
    const p = PERIODS[period];
    $('#periodSub').textContent = p.sub;
    const map = { saldo: 'saldo', receitas: 'receitas', despesas: 'despesas', economia: 'economia' };
    const order = ['saldo', 'receitas', 'despesas', 'economia'];
    $$('.stat .num').forEach((el, i) => { const k = order[i]; if (k) { el.dataset.count = p.stats[k]; animateCount(el, p.stats[k], 2); } });
    $$('[data-trend]').forEach(el => {
      const k = el.dataset.trend, val = p.trends[k]; const up = (k === 'despesas') ? val < 0 : val >= 0;
      el.className = 'trend ' + (up ? 'up' : 'down');
      el.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="${up ? 'M7 17 17 7M17 7h-7M17 7v7' : 'M7 7 17 17M17 17h-7M17 17v-7'}"/></svg>${Math.abs(val)}%`;
    });
    buildCashflow(period);
  }

  /* ---------- PERIOD SEGMENTED ---------- */
  function initPeriod() {
    const seg = $('#period'), pill = $('#segPill'), btns = $$('button', seg);
    function setPill(btn) { pill.style.width = btn.offsetWidth + 'px'; pill.style.transform = `translateX(${btn.offsetLeft - 4}px)`; }
    btns.forEach(b => b.addEventListener('click', () => {
      btns.forEach(x => x.classList.remove('active')); b.classList.add('active');
      setPill(b); applyPeriod(b.dataset.p);
    }));
    requestAnimationFrame(() => setPill(seg.querySelector('.active')));
    window.addEventListener('resize', () => setPill(seg.querySelector('.active')));
  }

  /* ---------- NAVIGATION ---------- */
  const VIEW_META = {
    cartoes: { t: 'Cartões', d: 'Gerencie seus cartões, limites e faturas em um só lugar.', icon: '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 9.5h19M6 15h4"/>' },
    investimentos: { t: 'Investimentos', d: 'Acompanhe sua carteira, rentabilidade e novas oportunidades.', icon: '<path d="M4 19V6M4 19h16M8 16v-4M12 16V8M16 16v-7"/>' },
    metas: { t: 'Metas', d: 'Crie objetivos, acompanhe o progresso e conquiste seus sonhos.', icon: '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/>' },
    faturas: { t: 'Faturas', d: 'Todas as suas contas a pagar organizadas por vencimento.', icon: '<path d="M6 3h9l3 3v15l-2-1.3L13 21l-2-1.3L9 21l-2-1.3L5 21V5a2 2 0 0 1 1-2Z"/><path d="M9 8h6M9 12h6"/>' },
    relatorios: { t: 'Relatórios', d: 'Exporte e visualize relatórios detalhados das suas finanças.', icon: '<path d="M5 3h9l5 5v13H5V3Z"/><path d="M14 3v5h5M8 13l2.5 2.5L16 10"/>' },
    config: { t: 'Configurações', d: 'Personalize sua conta, segurança e preferências do app.', icon: '<circle cx="12" cy="12" r="3.2"/><path d="M12 2.5v2.5M12 19v2.5M4.2 4.2l1.8 1.8M18 18l1.8 1.8M2.5 12H5M19 12h2.5"/>' },
    ajuda: { t: 'Ajuda', d: 'Central de ajuda, tutoriais e suporte StabilMoney.', icon: '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 0 1 4.5 1.5c0 1.7-2.5 1.8-2.5 3.5"/>' },
  };
  function showView(view) {
    $$('.view').forEach(v => v.classList.remove('active'));
    const direct = $('#view-' + view);
    if (direct) { direct.classList.add('active'); }
    else {
      const m = VIEW_META[view]; const g = $('#view-generic');
      g.innerHTML = `<div class="section-head"><h2>${m.t}</h2></div><div class="placeholder"><div class="pico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">${m.icon}</svg></div><h2>${m.t}</h2><p>${m.d}</p></div>`;
      g.classList.add('active');
    }
    $$('.nav-item').forEach(n => n.classList.toggle('active', n.dataset.view === view));
    $$('.bn-item[data-view]').forEach(n => n.classList.toggle('active', n.dataset.view === view));
    const content = $('#content');
    content.scrollTop = 0;
    content.classList.add('anim-in');
    setTimeout(() => content.classList.remove('anim-in'), 600);
    if (view === 'dashboard') { runCounters($('#view-dashboard')); animateBars(); }
    closeDrawer();
  }
  function initNav() {
    $$('.nav-item').forEach(n => n.addEventListener('click', e => { e.preventDefault(); showView(n.dataset.view); }));
    $$('.bn-item[data-view]').forEach(n => n.addEventListener('click', () => showView(n.dataset.view)));
    $$('[data-go]').forEach(b => b.addEventListener('click', () => showView(b.dataset.go)));
  }

  /* ---------- DRAWER / COLLAPSE ---------- */
  function closeDrawer() { $('#sidebar').classList.remove('open'); $('#scrim').classList.remove('show'); }
  function initShell() {
    $('#collapseBtn').addEventListener('click', () => $('#app').classList.toggle('collapsed'));
    $('#mMenu').addEventListener('click', () => { $('#sidebar').classList.add('open'); $('#scrim').classList.add('show'); });
    $('#scrim').addEventListener('click', closeDrawer);
  }

  /* ---------- THEME ---------- */
  function setTheme(t) {
    document.documentElement.setAttribute('data-theme', t);
    try { localStorage.setItem('sm-theme', t); } catch (e) {}
    const sun = '<circle cx="12" cy="12" r="4.5"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5l1.5 1.5M17.5 17.5 19 19M19 5l-1.5 1.5M6.5 17.5 5 19"/>';
    const moon = '<path d="M20 14.5A8 8 0 1 1 9.5 4a6.3 6.3 0 0 0 10.5 10.5Z"/>';
    const ic = t === 'dark' ? moon : sun;
    const themeIcon = $('#themeIcon'); if (themeIcon) themeIcon.innerHTML = ic;
    const mt = $('#mTheme svg'); if (mt) mt.innerHTML = ic;
  }
  function initTheme() {
    let saved = 'light'; try { saved = localStorage.getItem('sm-theme') || 'light'; } catch (e) {}
    setTheme(saved);
    const toggle = () => setTheme(document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
    $('#themeBtn').addEventListener('click', toggle);
    $('#mTheme').addEventListener('click', toggle);
  }

  /* ---------- DATE ---------- */
  function initDate() {
    const d = new Date(2026, 5, 9);
    const fmt = d.toLocaleDateString('pt-BR', { weekday: 'long', day: 'numeric', month: 'long' });
    $('#todayLine').textContent = fmt.charAt(0).toUpperCase() + fmt.slice(1) + ' · resumo das suas finanças';
  }

  /* ---------- INIT ---------- */
  function init() {
    initTheme(); initDate(); initShell(); initNav(); initPeriod();
    buildLists(); buildDonut(); applyPeriod('mes'); drawSparks();
    bindCashflowHover();
    runCounters($('#view-dashboard'));
    setTimeout(animateBars, 150);
    // Entrance flourish: add then remove so the resting state is always clean/visible
    const content = $('#content');
    content.classList.add('anim-in');
    setTimeout(() => content.classList.remove('anim-in'), 1300);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
