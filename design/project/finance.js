/* ============ StabilMoney — Finance engine v2 ============
   Lançamentos · Faturas · Métodos de pagamento · Categorias (drag) · Investimentos · Metas
*/
(function () {
  'use strict';
  const $ = (s, c) => (c || document).querySelector(s);
  const $$ = (s, c) => Array.from((c || document).querySelectorAll(s));
  const BRL = (v) => Number(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const BRL0 = (v) => Number(v || 0).toLocaleString('pt-BR', { maximumFractionDigits: 0 });
  const uid = () => Math.random().toString(36).slice(2, 9);
  const todayISO = () => '2026-06-10';
  function parseMoney(s) {
    if (typeof s === 'number') return s;
    if (!s) return 0;
    let m = String(s).replace(/[^\d,.-]/g, '');
    if (m.indexOf(',') > -1) m = m.replace(/\./g, '').replace(',', '.');
    const v = parseFloat(m); return isNaN(v) ? 0 : v;
  }
  const initials = (n) => n.trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('').toUpperCase();

  /* ---------- responsáveis (titular + dependentes) ---------- */
  function loadResponsibles() {
    const admin = { name: 'Alison Carter', role: 'Titular', color: '#0E5A3F', admin: true };
    let deps = [];
    try { const s = localStorage.getItem('sm-deps-v2'); if (s) deps = JSON.parse(s); } catch (e) {}
    if (!deps.length) deps = [{ name: 'Sofia Carter', rel: 'Filha', color: '#18B6BE' }, { name: 'Lucas Carter', rel: 'Filho', color: '#F0A93B' }];
    return [admin].concat(deps.map(d => ({ name: d.name, role: d.rel || 'Dependente', color: d.color || '#1FA06E' })));
  }

  /* ===================== STATE ===================== */
  const DEFAULT = {
    methods: [
      { id: 'm_cc1', kind: 'cartao', name: 'StabilMoney Black', last4: '4729', limit: 15000, venc: 9, c1: '#0E5A3F', c2: '#0B3A28' },
      { id: 'm_cc2', kind: 'cartao', name: 'Nubank Ultravioleta', last4: '1204', limit: 8000, venc: 15, c1: '#6B2D8F', c2: '#3A1456' },
      { id: 'm_ac1', kind: 'conta', name: 'Conta Corrente', bank: 'Banco do Brasil', balance: 18320.5, c1: '#1B4D89', c2: '#0E2A4D' },
      { id: 'm_ac2', kind: 'conta', name: 'Conta Pagamentos', bank: 'Inter', balance: 6140, c1: '#C77F2A', c2: '#7A4910' },
      { id: 'm_db1', kind: 'debito', name: 'Débito Visa', last4: '7781', linked: 'm_ac1', c1: '#0E5A3F', c2: '#0B3A28' },
      { id: 'm_px1', kind: 'pix', name: 'Pix CPF', pixType: 'CPF', pixKey: '•••.123.456-••', linked: 'm_ac1' },
    ],
    categories: [
      // despesa
      { id: uid(), tipo: 'despesa', name: 'Moradia', emoji: '🏠', color: '#0F6B47' },
      { id: uid(), tipo: 'despesa', name: 'Mercado', emoji: '🛒', color: '#1C9A70' },
      { id: uid(), tipo: 'despesa', name: 'Alimentação', emoji: '🍽️', color: '#1FA06E' },
      { id: uid(), tipo: 'despesa', name: 'Transporte', emoji: '🚗', color: '#59C497' },
      { id: uid(), tipo: 'despesa', name: 'Lazer', emoji: '🎮', color: '#18B6BE' },
      { id: uid(), tipo: 'despesa', name: 'Saúde', emoji: '💊', color: '#F0A93B' },
      { id: uid(), tipo: 'despesa', name: 'Educação', emoji: '📚', color: '#9078D8' },
      { id: uid(), tipo: 'despesa', name: 'Compras', emoji: '🛍️', color: '#E5604D' },
      { id: uid(), tipo: 'despesa', name: 'Assinaturas', emoji: '📺', color: '#3E84D8' },
      { id: uid(), tipo: 'despesa', name: 'Pets', emoji: '🐾', color: '#C77F2A' },
      { id: uid(), tipo: 'despesa', name: 'Contas & Serviços', emoji: '🧾', color: '#7C8C84' },
      { id: uid(), tipo: 'despesa', name: 'Outros', emoji: '📦', color: '#9FB0A7' },
      // receita
      { id: uid(), tipo: 'receita', name: 'Salário', emoji: '💼', color: '#1C9A70' },
      { id: uid(), tipo: 'receita', name: 'Freelance', emoji: '💻', color: '#18B6BE' },
      { id: uid(), tipo: 'receita', name: 'Investimentos', emoji: '📈', color: '#9078D8' },
      { id: uid(), tipo: 'receita', name: 'Vendas', emoji: '🏷️', color: '#59C497' },
      { id: uid(), tipo: 'receita', name: 'Aluguel', emoji: '🏘️', color: '#0F6B47' },
      { id: uid(), tipo: 'receita', name: 'Presente', emoji: '🎁', color: '#F0A93B' },
      { id: uid(), tipo: 'receita', name: 'Outros', emoji: '💰', color: '#9FB0A7' },
    ],
    entries: [
      { id: uid(), tipo: 'despesa', desc: 'Notebook Dell XPS', valor: 4800, cat: 'Compras', date: '2026-04-10', method: 'm_cc1', mode: 'parcelado', parcelas: 6, parcelaAtual: 2, quem: { name: 'Alison Carter', color: '#0E5A3F' } },
      { id: uid(), tipo: 'despesa', desc: 'Supermercado Pão de Açúcar', valor: 284.7, cat: 'Mercado', date: '2026-06-09', method: 'm_cc1', mode: 'avista', quem: { name: 'Alison Carter', color: '#0E5A3F' } },
      { id: uid(), tipo: 'despesa', desc: 'Passagem aérea GRU–LIS', valor: 3600, cat: 'Lazer', date: '2026-05-20', method: 'm_cc1', mode: 'parcelado', parcelas: 10, parcelaAtual: 1, quem: { name: 'Alison Carter', color: '#0E5A3F' } },
      { id: uid(), tipo: 'despesa', desc: 'Material escolar', valor: 230, cat: 'Educação', date: '2026-06-07', method: 'm_cc2', mode: 'avista', quem: { name: 'Sofia Carter', color: '#18B6BE' } },
      { id: uid(), tipo: 'despesa', desc: 'Spotify + Netflix', valor: 76.8, cat: 'Assinaturas', date: '2026-06-01', method: 'm_cc2', mode: 'recorrente', quem: { name: 'Alison Carter', color: '#0E5A3F' } },
      { id: uid(), tipo: 'despesa', desc: 'Lanche da escola', valor: 48, cat: 'Alimentação', date: '2026-06-06', method: 'm_db1', mode: 'avista', quem: { name: 'Lucas Carter', color: '#F0A93B' } },
      { id: uid(), tipo: 'despesa', desc: 'Uber para o trabalho', valor: 32.5, cat: 'Transporte', date: '2026-06-09', method: 'm_px1', mode: 'avista', quem: { name: 'Alison Carter', color: '#0E5A3F' } },
    ],
    investments: [
      { id: uid(), name: 'Tesouro Selic 2029', classe: 'Renda fixa', indexador: 'Selic', taxa: 100, aplicado: 32400, aporte: 800, rentab: 10.6 },
      { id: uid(), name: 'CDB Banco Inter', classe: 'Renda fixa', indexador: 'CDI', taxa: 110, aplicado: 18000, aporte: 500, rentab: 11.4 },
      { id: uid(), name: 'BOVA11', classe: 'Renda variável', indexador: '—', taxa: 0, aplicado: 21800, aporte: 600, rentab: 8.2 },
      { id: uid(), name: 'Bitcoin', classe: 'Cripto', indexador: '—', taxa: 0, aplicado: 11810, aporte: 300, rentab: 21.5 },
    ],
    goals: [
      { id: uid(), name: 'Viagem ao Japão', emoji: '✈️', target: 15000, saved: 8400, color: '#18B6BE', prazo: '2026-12' },
      { id: uid(), name: 'Entrada do apê', emoji: '🏠', target: 60000, saved: 32000, color: '#0F6B47', prazo: '2028-06' },
      { id: uid(), name: 'Carro novo', emoji: '🚗', target: 90000, saved: 24500, color: '#1C9A70', prazo: '2027-09' },
      { id: uid(), name: 'Moto', emoji: '🏍️', target: 32000, saved: 9800, color: '#9078D8', prazo: '2026-11' },
      { id: uid(), name: 'Reserva de emergência', emoji: '🛡️', target: 24000, saved: 18600, color: '#F0A93B', prazo: '2026-10' },
    ],
  };
  const SKEY = 'sm-finance-v3';
  let S;
  function load() { try { const s = localStorage.getItem(SKEY); if (s) return JSON.parse(s); } catch (e) {} return JSON.parse(JSON.stringify(DEFAULT)); }
  function save() { try { localStorage.setItem(SKEY, JSON.stringify(S)); } catch (e) {} }
  S = load();

  /* ===================== DERIVED ===================== */
  const KIND_LABEL = { cartao: 'Cartão de crédito', conta: 'Conta corrente', debito: 'Cartão de débito', pix: 'Pix' };
  const KIND_ICON = {
    cartao: '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 9.5h19M6 15h4"/>',
    conta: '<path d="M3 10 12 4l9 6M5 10v9h14v-9M9 19v-5h6v5"/>',
    debito: '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 9.5h19M15 15h3"/>',
    pix: '<path d="M12 3 6 9l6 6 6-6-6-6ZM4 12l3 3M20 12l-3 3M12 14v7"/>',
  };
  const method = (id) => S.methods.find(m => m.id === id);
  const cards = () => S.methods.filter(m => m.kind === 'cartao');
  const accounts = () => S.methods.filter(m => m.kind === 'conta');
  const catBy = (name) => S.categories.find(c => c.name === name) || { emoji: '📦', color: '#9FB0A7' };
  const monthly = (e) => e.mode === 'parcelado' ? e.valor / (e.parcelas || 1) : e.valor;
  const cardEntries = (id) => S.entries.filter(e => e.tipo === 'despesa' && e.method === id);
  const faturaTotal = (id) => cardEntries(id).reduce((s, e) => s + monthly(e), 0);
  function committed(id) {
    return cardEntries(id).reduce((s, e) => {
      if (e.mode === 'parcelado') { const paid = (e.parcelaAtual || 1) - 1; return s + e.valor * ((e.parcelas - paid) / e.parcelas); }
      return s + monthly(e);
    }, 0);
  }
  const limitAvail = (c) => Math.max(0, c.limit - committed(c.id));
  const totalEmConta = () => accounts().reduce((s, a) => s + (a.balance || 0), 0);
  const totalInvest = () => S.investments.reduce((s, i) => s + i.aplicado, 0);
  const patrimonio = () => totalEmConta() + totalInvest();

  /* ===================== DASHBOARD SYNC ===================== */
  function updatePatrimonio() {
    const el = $('#sbValue'); if (el) { const [int, dec] = BRL(patrimonio()).split(','); el.innerHTML = 'R$ ' + int + '<span>,' + dec + '</span>'; }
    const sub = $('#sbSubline'); if (sub) sub.textContent = 'Em conta: R$ ' + BRL(totalEmConta());
  }
  function updateMeuCartao() {
    const c = cards()[0]; const el = $('#ccLimit'); if (el && c) el.textContent = 'R$ ' + BRL(limitAvail(c));
  }
  function renderDashGoals() {
    const box = $('#goalsList'); if (!box) return;
    box.innerHTML = S.goals.slice(0, 3).map(g => {
      const pct = Math.min(100, Math.round(g.saved / g.target * 100));
      return `<div class="goal"><div class="goal-top"><div class="goal-ico">${g.emoji}</div><div><div class="goal-name">${g.name}</div><div class="goal-sub">Meta R$ ${BRL0(g.target)}</div></div><div class="goal-pct">${pct}%</div></div>` +
        `<div class="bar"><i style="right:${100 - pct}%;background:linear-gradient(90deg, ${g.color}, color-mix(in srgb, ${g.color} 60%, white))"></i></div>` +
        `<div class="goal-amt"><b>R$ ${BRL0(g.saved)}</b><span>de R$ ${BRL0(g.target)}</span></div></div>`;
    }).join('');
  }
  function renderDashInvest() {
    const box = $('#invList'); if (!box) return;
    const tot = totalInvest();
    const totalEl = $('.inv-total .num'); if (totalEl) { totalEl.dataset.count = tot; totalEl.textContent = BRL(tot); }
    box.innerHTML = S.investments.slice(0, 4).map(v => {
      const col = v.classe === 'Cripto' ? '#C77F2A' : v.classe === 'Renda variável' ? '#18B6BE' : '#1C9A70';
      return `<div class="inv"><div class="il" style="background:${col}">${initials(v.name)}</div>` +
        `<div><div class="in">${v.name}</div><div class="is">${v.classe}${v.indexador !== '—' ? ' · ' + v.taxa + '% ' + v.indexador : ''}</div></div>` +
        `<div class="iv"><b>R$ ${BRL0(v.aplicado)}</b><span class="pos">▲ ${v.rentab}%</span></div></div>`;
    }).join('');
  }
  function syncDashboard() { updatePatrimonio(); updateMeuCartao(); renderDashGoals(); renderDashInvest(); }

  /* ===================== FATURAS ===================== */
  function badgeOf(e) {
    if (e.mode === 'parcelado') return `${e.parcelaAtual || 1}/${e.parcelas}x`;
    if (e.mode === 'recorrente') return 'Recorrente';
    return 'À vista';
  }
  function whoBadge(e) {
    if (!e.quem) return '';
    return ` · <em class="fi-who"><span class="fw-av" style="background:${e.quem.color}">${initials(e.quem.name)}</span>${e.quem.name.split(' ')[0]}</em>`;
  }
  function itemRow(e) {
    const c = catBy(e.cat);
    return `<div class="fatura-item"><span class="fi-ico" style="background:${c.color}1f">${c.emoji}</span>` +
      `<div class="fi-txt"><strong>${e.desc}</strong><span>${e.cat} · <em class="fi-badge ${e.mode}">${badgeOf(e)}</em>${whoBadge(e)}</span></div>` +
      `<div class="fi-val"><b>R$ ${BRL(monthly(e))}</b>${e.mode === 'parcelado' ? `<small>de R$ ${BRL(e.valor)}</small>` : ''}</div>` +
      `<button class="fi-rm" data-id="${e.id}" aria-label="Remover" title="Remover"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg></button></div>`;
  }
  function renderFaturas() {
    const body = $('#faturasBody'); if (!body) return;
    const filter = $('#faturaFilter') ? $('#faturaFilter').value : 'all';
    const list = (filter && filter !== 'all') ? cards().filter(c => c.id === filter) : cards();
    const grand = cards().reduce((s, c) => s + faturaTotal(c.id), 0);
    let html = `<div class="grid">` +
      statCard('g3', '<path d="M6 3h9l3 3v15l-2-1.3L13 21l-2-1.3L9 21l-2-1.3L5 21V5a2 2 0 0 1 1-2Z"/><path d="M9 8h6M9 12h6"/>', 'Total das faturas', 'R$ ' + BRL(grand)) +
      statCard('g1', '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 9.5h19"/>', 'Cartões cadastrados', cards().length) +
      statCard('g4', '<path d="M12 2v4M5 9a7 7 0 1 1 14 0c0 4-3 5-3 8H8c0-3-3-4-3-8Z"/>', 'Limite disponível', 'R$ ' + BRL(cards().reduce((s, c) => s + limitAvail(c), 0))) +
      `</div>`;
    html += list.map(c => {
      const items = cardEntries(c.id), total = faturaTotal(c.id), usado = c.limit - limitAvail(c);
      const pct = Math.min(100, usado / c.limit * 100);
      return `<div class="card fatura-card span12"><div class="fatura-head">` +
        `<div class="fh-card" style="background:linear-gradient(135deg,${c.c1},${c.c2})">${initials(c.name)}</div>` +
        `<div class="fh-info"><strong>${c.name}</strong><span>•••• ${c.last4} · vence dia ${c.venc}</span></div>` +
        `<div class="fh-total"><span>Fatura atual</span><b>R$ ${BRL(total)}</b></div></div>` +
        `<div class="fh-limit"><div class="dp-bar"><i style="width:${pct}%"></i></div><div class="dp-meta"><span>Limite usado</span><span>R$ ${BRL(usado)} de R$ ${BRL(c.limit)}</span></div></div>` +
        `<div class="fatura-items">${items.length ? items.map(itemRow).join('') : '<div class="fi-empty">Nenhuma despesa neste cartão ainda.</div>'}</div></div>`;
    }).join('');
    if (!cards().length) html += emptyState('Nenhum cartão cadastrado', 'Adicione um cartão em Métodos de Pagamento para acompanhar faturas.');

    // ----- despesas pagas em conta/débito/Pix (não-cartão) -----
    if (!filter || filter === 'all') {
      const naoCartao = S.entries.filter(e => e.tipo === 'despesa' && (() => { const m = method(e.method); return !m || m.kind !== 'cartao'; })());
      const totalAvulso = naoCartao.reduce((s, e) => s + e.valor, 0);
      html += `<div class="card fatura-card span12"><div class="fatura-head">` +
        `<div class="fh-card" style="background:linear-gradient(135deg,#1B4D89,#0E2A4D)"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.8" width="22" height="22"><path d="M3 10 12 4l9 6M5 10v9h14v-9M9 19v-5h6v5"/></svg></div>` +
        `<div class="fh-info"><strong>Despesas em conta</strong><span>Pagas via débito, Pix ou conta corrente</span></div>` +
        `<div class="fh-total"><span>Total no período</span><b>R$ ${BRL(totalAvulso)}</b></div></div>` +
        `<div class="fatura-items">${naoCartao.length ? naoCartao.map(avulsoRow).join('') : '<div class="fi-empty">Nenhuma despesa avulsa registrada.</div>'}</div></div>`;
    }

    body.innerHTML = html;
    $$('.fi-rm', body).forEach(b => b.addEventListener('click', () => { S.entries = S.entries.filter(e => e.id !== b.dataset.id); save(); renderFaturas(); syncDashboard(); updatePatrimonio(); }));
  }
  function avulsoRow(e) {
    const c = catBy(e.cat); const m = method(e.method);
    return `<div class="fatura-item"><span class="fi-ico" style="background:${c.color}1f">${c.emoji}</span>` +
      `<div class="fi-txt"><strong>${e.desc}</strong><span>${e.cat} · ${m ? m.name : 'Conta'}${whoBadge(e)}</span></div>` +
      `<div class="fi-val"><b>R$ ${BRL(e.valor)}</b></div>` +
      `<button class="fi-rm" data-id="${e.id}" aria-label="Remover" title="Remover"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg></button></div>`;
  }
  function statCard(g, icon, label, value) {
    return `<div class="card stat span4"><div class="stat-top"><div class="ico ${g}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor">${icon}</svg></div><span class="label">${label}</span></div><div class="value">${value}</div></div>`;
  }
  function emptyState(t, d) {
    return `<div class="card span12"><div class="empty-block"><div class="eb-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg></div><strong>${t}</strong><span>${d}</span></div></div>`;
  }

  /* ===================== MÉTODOS DE PAGAMENTO ===================== */
  function methodCard(m) {
    if (m.kind === 'cartao') {
      const avail = limitAvail(m), pct = Math.min(100, (m.limit - avail) / m.limit * 100);
      return `<div class="card span6 pm-card"><div class="cc" style="background:linear-gradient(135deg,${m.c1},${m.c2})">` +
        `<div class="cc-top"><span class="net">${m.name}</span><span class="pm-flag">Crédito</span></div>` +
        `<div class="cc-chip"></div><div class="cc-num">•••• •••• •••• ${m.last4}</div>` +
        `<div class="cc-bot"><div><div class="lbl">Limite disponível</div><div class="cc-balance">R$ ${BRL(avail)}</div></div>` +
        `<div style="text-align:right"><div class="lbl">Vencimento</div><div class="val">dia ${m.venc}</div></div></div></div>` +
        `<div class="pm-meta"><div class="dp-bar"><i style="width:${pct}%"></i></div><div class="dp-meta"><span>Fatura R$ ${BRL(faturaTotal(m.id))}</span><span>Limite R$ ${BRL0(m.limit)}</span></div></div>` +
        `<button class="pm-del" data-id="${m.id}">Remover</button></div>`;
    }
    if (m.kind === 'conta') {
      return `<div class="card span6 pm-card"><div class="cc" style="background:linear-gradient(135deg,${m.c1},${m.c2})">` +
        `<div class="cc-top"><span class="net">${m.name}</span><span class="pm-flag">${m.bank || 'Conta'}</span></div>` +
        `<div class="cc-acct-ico"><svg viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,.9)" stroke-width="1.7"><path d="M3 10 12 4l9 6M5 10v9h14v-9M9 19v-5h6v5"/></svg></div>` +
        `<div class="cc-bot"><div><div class="lbl">Saldo em conta</div><div class="cc-balance">R$ ${BRL(m.balance)}</div></div>` +
        `<div style="text-align:right"><div class="lbl">Conta corrente</div><div class="val">${m.bank || '—'}</div></div></div></div>` +
        `<button class="pm-del" data-id="${m.id}">Remover</button></div>`;
    }
    // debito / pix — compact rows
    const sub = m.kind === 'debito' ? '•••• ' + m.last4 : m.pixType + ' · ' + m.pixKey;
    const linked = method(m.linked);
    return `<div class="card span6 pm-mini"><span class="pm-mini-ico ${m.kind}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor">${KIND_ICON[m.kind]}</svg></span>` +
      `<div class="pm-mini-txt"><strong>${m.name}</strong><span>${KIND_LABEL[m.kind]} · ${sub}</span>${linked ? `<span class="pm-link">Vinculado a ${linked.name}</span>` : ''}</div>` +
      `<button class="pm-del mini" data-id="${m.id}" aria-label="Remover"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg></button></div>`;
  }
  function renderMetodos() {
    const body = $('#cartoesBody'); if (!body) return;
    const order = ['cartao', 'conta', 'debito', 'pix'];
    const titles = { cartao: 'Cartões de crédito', conta: 'Contas correntes', debito: 'Cartões de débito', pix: 'Chaves Pix' };
    let html = '';
    order.forEach(k => {
      const list = S.methods.filter(m => m.kind === k);
      if (!list.length) return;
      html += `<div class="pm-group-label">${titles[k]} <span>${list.length}</span></div><div class="grid">${list.map(methodCard).join('')}</div>`;
    });
    body.innerHTML = html;
    $$('.pm-del', body).forEach(b => b.addEventListener('click', () => {
      const m = method(b.dataset.id);
      if (m.kind === 'cartao' && cards().length <= 1) return toast('Mantenha ao menos um cartão');
      if (m.kind === 'conta' && accounts().length <= 1) return toast('Mantenha ao menos uma conta');
      S.methods = S.methods.filter(x => x.id !== b.dataset.id); save(); renderMetodos(); syncDashboard(); fillFaturaFilter(); renderFaturas();
    }));
  }

  /* ===================== CATEGORIAS (drag entre colunas) ===================== */
  function catCard(c) {
    return `<div class="cat-chip" draggable="true" data-id="${c.id}">` +
      `<span class="cc-emoji" style="background:${c.color}22">${c.emoji}</span>` +
      `<span class="cc-name">${c.name}</span>` +
      `<span class="cc-dot" style="background:${c.color}"></span>` +
      `<button class="cc-del" data-id="${c.id}" aria-label="Remover"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg></button>` +
      `<svg class="cc-grip" viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="9" cy="6" r="1.4"/><circle cx="15" cy="6" r="1.4"/><circle cx="9" cy="12" r="1.4"/><circle cx="15" cy="12" r="1.4"/><circle cx="9" cy="18" r="1.4"/><circle cx="15" cy="18" r="1.4"/></svg>` +
      `</div>`;
  }
  function renderCategorias() {
    const body = $('#categoriasBody'); if (!body) return;
    const desp = S.categories.filter(c => c.tipo === 'despesa');
    const rec = S.categories.filter(c => c.tipo === 'receita');
    body.innerHTML = `<div class="cat-cols">` +
      colHTML('despesa', 'Despesas', '#E5604D', desp) +
      colHTML('receita', 'Receitas', '#1C9A70', rec) +
      `</div>`;
    bindCatDrag(body);
    $$('.cc-del', body).forEach(b => b.addEventListener('click', (e) => {
      e.stopPropagation();
      S.categories = S.categories.filter(c => c.id !== b.dataset.id); save(); renderCategorias();
    }));
  }
  function colHTML(tipo, title, color, list) {
    return `<div class="cat-col" data-tipo="${tipo}">` +
      `<div class="cat-col-head"><span class="cch-dot" style="background:${color}"></span><h3>${title}</h3><span class="cch-count">${list.length}</span></div>` +
      `<div class="cat-drop" data-tipo="${tipo}">${list.map(catCard).join('')}<div class="cat-drop-hint">Solte aqui para mover para ${title}</div></div></div>`;
  }
  let dragId = null;
  function bindCatDrag(scope) {
    $$('.cat-chip', scope).forEach(el => {
      el.addEventListener('dragstart', () => { dragId = el.dataset.id; setTimeout(() => el.classList.add('dragging'), 0); });
      el.addEventListener('dragend', () => { el.classList.remove('dragging'); dragId = null; $$('.cat-drop', scope).forEach(d => d.classList.remove('over')); });
    });
    $$('.cat-drop', scope).forEach(drop => {
      drop.addEventListener('dragover', (e) => { e.preventDefault(); drop.classList.add('over'); });
      drop.addEventListener('dragleave', (e) => { if (!drop.contains(e.relatedTarget)) drop.classList.remove('over'); });
      drop.addEventListener('drop', (e) => {
        e.preventDefault(); drop.classList.remove('over');
        const cat = S.categories.find(c => c.id === dragId); if (!cat) return;
        const newTipo = drop.dataset.tipo;
        // reorder: move to end of target group
        S.categories = S.categories.filter(c => c.id !== cat.id);
        cat.tipo = newTipo;
        S.categories.push(cat);
        save(); renderCategorias();
        toast(`“${cat.name}” movida para ${newTipo === 'despesa' ? 'Despesas' : 'Receitas'}`);
      });
    });
  }

  /* ===================== INVESTIMENTOS ===================== */
  // projected gross annual rate by indexer
  const IDX_BASE = { CDI: 10.65, Selic: 10.5, 'IPCA+': 4.5, Prefixado: 0, '—': 0 };
  function grossRate(inv) {
    if (inv.indexador === 'CDI' || inv.indexador === 'Selic') return IDX_BASE[inv.indexador] * (inv.taxa / 100);
    if (inv.indexador === 'IPCA+') return IDX_BASE['IPCA+'] + inv.taxa;
    if (inv.indexador === 'Prefixado') return inv.taxa;
    return inv.rentab; // RV / cripto use observed rentab
  }
  function renderInvestimentos() {
    const body = $('#investimentosBody'); if (!body) return;
    const tot = totalInvest();
    const aporteMes = S.investments.reduce((s, i) => s + (i.aporte || 0), 0);
    const wAvg = tot ? S.investments.reduce((s, i) => s + grossRate(i) * i.aplicado, 0) / tot : 0;
    let html = `<div class="grid">` +
      statCard('g1', '<path d="M4 19V6M4 19h16M8 16v-4M12 16V8M16 16v-7"/>', 'Patrimônio investido', 'R$ ' + BRL(tot)) +
      statCard('g2', '<path d="M12 2v20M17 6H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>', 'Aporte mensal', 'R$ ' + BRL(aporteMes)) +
      statCard('g4', '<path d="M3 12h4l3 8 4-16 3 8h4"/>', 'Rentab. média', wAvg.toFixed(1) + '% a.a.') +
      `</div>`;
    // allocation chart
    const byClasse = {};
    S.investments.forEach(i => { byClasse[i.classe] = (byClasse[i.classe] || 0) + i.aplicado; });
    const CLASSE_COL = { 'Renda fixa': '#1C9A70', 'Renda variável': '#18B6BE', 'Cripto': '#C77F2A', 'Fundos': '#9078D8' };
    let acc = 0; const segs = Object.keys(byClasse).map(k => { const frac = byClasse[k] / tot; const o = { k, frac, start: acc, col: CLASSE_COL[k] || '#9FB0A7', val: byClasse[k] }; acc += frac; return o; });
    html += `<div class="grid" style="margin-top:18px"><div class="card span5"><div class="card-head"><h3>Alocação da carteira</h3></div>` +
      `<div class="alloc-wrap"><svg class="alloc-svg" viewBox="0 0 120 120">` +
      segs.map(s => arcSeg(s.start, s.frac, s.col)).join('') +
      `<g text-anchor="middle"><text x="60" y="57" style="font:700 16px var(--font-head);fill:var(--ink)">R$ ${(tot / 1000).toFixed(0)}k</text><text x="60" y="72" style="font:500 9px var(--font-body);fill:var(--ink-3)">investido</text></g></svg>` +
      `<div class="alloc-legend">${segs.map(s => `<div class="al-row"><span class="al-dot" style="background:${s.col}"></span><span class="al-name">${s.k}</span><span class="al-val">${Math.round(s.frac * 100)}%</span></div>`).join('')}</div></div></div>`;
    // holdings list
    html += `<div class="card span7"><div class="card-head"><h3>Meus ativos</h3><button class="mini-btn" id="invAddInline">Novo aporte<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg></button></div><div class="inv-rows">` +
      S.investments.map(invRow).join('') + `</div></div></div>`;
    body.innerHTML = html;
    $$('.inv-rm', body).forEach(b => b.addEventListener('click', () => { S.investments = S.investments.filter(i => i.id !== b.dataset.id); save(); renderInvestimentos(); syncDashboard(); }));
    $$('.inv-aporte', body).forEach(b => b.addEventListener('click', () => aporteModal(b.dataset.id)));
    const ia = $('#invAddInline'); if (ia) ia.addEventListener('click', () => investModal());
  }
  function invRow(v) {
    const col = v.classe === 'Cripto' ? '#C77F2A' : v.classe === 'Renda variável' ? '#18B6BE' : v.classe === 'Fundos' ? '#9078D8' : '#1C9A70';
    const idx = v.indexador !== '—' ? `${v.taxa}% ${v.indexador}` : v.classe;
    return `<div class="invr"><div class="invr-l" style="background:${col}">${initials(v.name)}</div>` +
      `<div class="invr-main"><strong>${v.name}</strong><span>${idx} · aporte R$ ${BRL0(v.aporte)}/mês</span></div>` +
      `<div class="invr-val"><b>R$ ${BRL0(v.aplicado)}</b><span class="pos">▲ ${grossRate(v).toFixed(1)}% a.a.</span></div>` +
      `<button class="invr-btn inv-aporte" data-id="${v.id}" title="Aportar">+ Aportar</button>` +
      `<button class="inv-rm" data-id="${v.id}" aria-label="Remover"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg></button></div>`;
  }
  function arcSeg(start, frac, color) {
    const R = 46, C = 60, GAP = frac < 0.999 ? 3 : 0;
    const a0 = start * 360 + GAP / 2, a1 = (start + frac) * 360 - GAP / 2;
    const p = (deg) => { const a = (deg - 90) * Math.PI / 180; return [C + R * Math.cos(a), C + R * Math.sin(a)]; };
    const [x0, y0] = p(a0), [x1, y1] = p(Math.max(a0 + 0.1, a1)); const large = (a1 - a0) > 180 ? 1 : 0;
    return `<path d="M${x0.toFixed(2)} ${y0.toFixed(2)} A${R} ${R} 0 ${large} 1 ${x1.toFixed(2)} ${y1.toFixed(2)}" fill="none" stroke="${color}" stroke-width="13" stroke-linecap="round"/>`;
  }

  /* ===================== METAS ===================== */
  function renderMetas() {
    const body = $('#metasBody'); if (!body) return;
    const totSaved = S.goals.reduce((s, g) => s + g.saved, 0);
    const totTarget = S.goals.reduce((s, g) => s + g.target, 0);
    let html = `<div class="grid">` +
      statCard('g1', '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/><circle cx="12" cy="12" r=".7" fill="currentColor"/>', 'Metas ativas', S.goals.length) +
      statCard('g2', '<path d="M12 2v4M5 9a7 7 0 1 1 14 0c0 4-3 5-3 8H8c0-3-3-4-3-8Z"/>', 'Total guardado', 'R$ ' + BRL(totSaved)) +
      statCard('g4', '<path d="M3 12h4l3 8 4-16 3 8h4"/>', 'Progresso geral', (totTarget ? Math.round(totSaved / totTarget * 100) : 0) + '%') +
      `</div><div class="grid meta-grid">`;
    html += S.goals.map(metaCard).join('');
    html += `<div class="card span4 meta-add" id="metaAddCard"><div class="pm-add-inner"><span class="pm-plus"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 5v14M5 12h14"/></svg></span><strong>Nova meta</strong><span>Viagem, carro, reserva…</span></div></div>`;
    html += `</div>`;
    body.innerHTML = html;
    $$('.meta-aporte', body).forEach(b => b.addEventListener('click', () => aporteMetaModal(b.dataset.id)));
    $$('.meta-del', body).forEach(b => b.addEventListener('click', () => { S.goals = S.goals.filter(g => g.id !== b.dataset.id); save(); renderMetas(); syncDashboard(); }));
    const ma = $('#metaAddCard', body); if (ma) ma.addEventListener('click', () => metaModal());
  }
  function metaCard(g) {
    const pct = Math.min(100, Math.round(g.saved / g.target * 100));
    const falta = Math.max(0, g.target - g.saved);
    const prazo = g.prazo ? new Date(g.prazo + '-01').toLocaleDateString('pt-BR', { month: 'short', year: 'numeric' }) : '';
    return `<div class="card span4 meta-card"><button class="meta-del" data-id="${g.id}" aria-label="Remover"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg></button>` +
      `<div class="meta-emoji" style="background:${g.color}1f">${g.emoji}</div>` +
      `<h3 class="meta-name">${g.name}</h3>${prazo ? `<span class="meta-prazo">🎯 ${prazo}</span>` : ''}` +
      `<div class="meta-ring"><svg viewBox="0 0 90 90"><circle cx="45" cy="45" r="38" fill="none" stroke="var(--surface-3)" stroke-width="9"/>` +
      `<circle cx="45" cy="45" r="38" fill="none" stroke="${g.color}" stroke-width="9" stroke-linecap="round" stroke-dasharray="${(pct / 100 * 238.7).toFixed(1)} 238.7" transform="rotate(-90 45 45)"/>` +
      `<text x="45" y="50" text-anchor="middle" style="font:700 18px var(--font-head);fill:var(--ink)">${pct}%</text></svg></div>` +
      `<div class="meta-amounts"><div><span>Guardado</span><b>R$ ${BRL0(g.saved)}</b></div><div style="text-align:right"><span>Faltam</span><b>R$ ${BRL0(falta)}</b></div></div>` +
      `<button class="btn primary meta-aporte" data-id="${g.id}">+ Aportar</button></div>`;
  }

  /* ===================== SELECTS ===================== */
  function catOptions(tipo) { return S.categories.filter(c => c.tipo === tipo).map(c => `<option value="${c.name}">${c.emoji}  ${c.name}</option>`).join(''); }
  function fillMethodSelect(sel) {
    const groups = [['cartao', 'Cartões de crédito'], ['conta', 'Contas (débito)'], ['debito', 'Cartão de débito'], ['pix', 'Pix']];
    sel.innerHTML = groups.map(([k, label]) => {
      const list = S.methods.filter(m => m.kind === k); if (!list.length) return '';
      return `<optgroup label="${label}">${list.map(m => `<option value="${m.id}">${m.name}${m.last4 ? ' •••• ' + m.last4 : ''}</option>`).join('')}</optgroup>`;
    }).join('');
  }
  function fillFaturaFilter() {
    const sel = $('#faturaFilter'); if (!sel) return; const cur = sel.value;
    sel.innerHTML = '<option value="all">Todos os cartões</option>' + cards().map(c => `<option value="${c.id}">${c.name}</option>`).join('');
    if (cur) sel.value = cur;
  }

  /* ===================== GENERIC MINI MODAL ===================== */
  function miniModal(opts) {
    const scrim = document.createElement('div');
    scrim.className = 'modal-scrim';
    scrim.innerHTML = `<div class="modal ${opts.cls || ''}" role="dialog" aria-modal="true">` +
      `<div class="modal-head"><div class="modal-ico ${opts.icoCls || ''}">${opts.ico}</div><div><h3>${opts.title}</h3><p>${opts.sub || ''}</p></div>` +
      `<button class="modal-x" data-x aria-label="Fechar"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6 6 18"/></svg></button></div>` +
      `<form class="modal-body">${opts.body}<div class="modal-foot"><button type="button" class="btn ghost" data-x>Cancelar</button><button type="submit" class="btn primary">${opts.ok || 'Salvar'}</button></div></form></div>`;
    document.body.appendChild(scrim);
    // force reflow then add .open so the transition plays even if rAF is throttled
    void scrim.offsetHeight;
    scrim.classList.add('open');
    const close = () => { scrim.classList.remove('open'); setTimeout(() => scrim.remove(), 250); };
    scrim.addEventListener('click', e => { if (e.target === scrim) close(); });
    $$('[data-x]', scrim).forEach(b => b.addEventListener('click', close));
    if (opts.onMount) opts.onMount(scrim);
    $('form', scrim).addEventListener('submit', e => { e.preventDefault(); if (opts.submit(scrim) !== false) close(); });
    const f = scrim.querySelector('input,select'); if (f) setTimeout(() => f.focus(), 120);
    return scrim;
  }

  /* ---- add payment method ---- */
  const CARD_PAL = [['#0E5A3F', '#0B3A28'], ['#6B2D8F', '#3A1456'], ['#1B4D89', '#0E2A4D'], ['#B5453B', '#6E231C'], ['#C77F2A', '#7A4910'], ['#2A6F6B', '#123E3B']];
  function addMethodModal() {
    miniModal({
      title: 'Novo método de pagamento', sub: 'Cartão, conta, débito ou Pix', icoCls: 'ico-in',
      ico: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor">${KIND_ICON.cartao}</svg>`,
      ok: 'Adicionar',
      body: `<label class="field"><span>Tipo</span><div class="select-wrap full"><select id="mKind">` +
        `<option value="cartao">Cartão de crédito</option><option value="conta">Conta corrente</option><option value="debito">Cartão de débito</option><option value="pix">Chave Pix</option>` +
        `</select><svg class="sel-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 9l6 6 6-6"/></svg></div></label>` +
        `<label class="field"><span>Nome / apelido</span><input id="mName" placeholder="Ex.: Itaú Platinum" required></label>` +
        `<div id="mFields"></div>`,
      onMount: (sc) => {
        const kindSel = $('#mKind', sc), wrap = $('#mFields', sc);
        const render = () => {
          const k = kindSel.value;
          if (k === 'cartao') wrap.innerHTML = `<div class="field-row"><label class="field"><span>Final (4 díg.)</span><input id="mLast" maxlength="4" inputmode="numeric" placeholder="0000"></label><label class="field"><span>Vencimento</span><input id="mVenc" type="number" min="1" max="28" placeholder="10"></label></div><label class="field"><span>Limite</span><input id="mLimit" inputmode="decimal" placeholder="R$ 5.000,00"></label>`;
          else if (k === 'conta') wrap.innerHTML = `<div class="field-row"><label class="field"><span>Banco</span><input id="mBank" placeholder="Ex.: Nubank"></label><label class="field"><span>Saldo inicial</span><input id="mBal" inputmode="decimal" placeholder="R$ 0,00"></label></div>`;
          else if (k === 'debito') wrap.innerHTML = `<div class="field-row"><label class="field"><span>Final (4 díg.)</span><input id="mLast" maxlength="4" inputmode="numeric" placeholder="0000"></label><label class="field"><span>Conta vinculada</span><div class="select-wrap full"><select id="mLink">${accounts().map(a => `<option value="${a.id}">${a.name}</option>`).join('')}</select><svg class="sel-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 9l6 6 6-6"/></svg></div></label></div>`;
          else wrap.innerHTML = `<div class="field-row"><label class="field"><span>Tipo de chave</span><div class="select-wrap full"><select id="mPxType"><option>CPF</option><option>E-mail</option><option>Telefone</option><option>Aleatória</option></select><svg class="sel-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 9l6 6 6-6"/></svg></div></label><label class="field"><span>Conta vinculada</span><div class="select-wrap full"><select id="mLink">${accounts().map(a => `<option value="${a.id}">${a.name}</option>`).join('')}</select><svg class="sel-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 9l6 6 6-6"/></svg></div></label></div><label class="field"><span>Chave</span><input id="mPxKey" placeholder="sua chave Pix"></label>`;
        };
        kindSel.addEventListener('change', render); render();
      },
      submit: (sc) => {
        const k = $('#mKind', sc).value, name = $('#mName', sc).value.trim(); if (!name) return false;
        const pal = CARD_PAL[S.methods.length % CARD_PAL.length];
        if (k === 'cartao') S.methods.push({ id: uid(), kind: 'cartao', name, last4: ($('#mLast', sc).value || '0000').slice(-4).padStart(4, '0'), limit: parseMoney($('#mLimit', sc).value) || 1000, venc: +$('#mVenc', sc).value || 10, c1: pal[0], c2: pal[1] });
        else if (k === 'conta') S.methods.push({ id: uid(), kind: 'conta', name, bank: $('#mBank', sc).value.trim() || '—', balance: parseMoney($('#mBal', sc).value), c1: pal[0], c2: pal[1] });
        else if (k === 'debito') S.methods.push({ id: uid(), kind: 'debito', name, last4: ($('#mLast', sc).value || '0000').slice(-4).padStart(4, '0'), linked: $('#mLink', sc).value, c1: pal[0], c2: pal[1] });
        else S.methods.push({ id: uid(), kind: 'pix', name, pixType: $('#mPxType', sc).value, pixKey: $('#mPxKey', sc).value.trim() || '—', linked: $('#mLink', sc).value });
        save(); renderMetodos(); fillFaturaFilter(); renderFaturas(); syncDashboard(); toast('Método adicionado');
      },
    });
  }

  /* ---- add category ---- */
  const EMOJIS = ['🏠', '🛒', '🍽️', '🚗', '🎮', '💊', '📚', '🛍️', '📺', '🐾', '🧾', '✈️', '🏋️', '🎁', '💼', '💻', '📈', '🏷️', '🏘️', '💰', '☕', '🍿', '⛽', '🎓', '💡'];
  const CAT_PAL = ['#1FA06E', '#18B6BE', '#F0A93B', '#9078D8', '#0F6B47', '#E5604D', '#59C497', '#3E84D8', '#C77F2A', '#7C8C84'];
  function addCatModal() {
    miniModal({
      title: 'Nova categoria', sub: 'Escolha um emoji e uma cor', icoCls: 'ico-in',
      ico: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="3" width="8" height="8" rx="2"/><rect x="13" y="3" width="8" height="8" rx="2"/><rect x="3" y="13" width="8" height="8" rx="2"/><rect x="13" y="13" width="8" height="8" rx="2"/></svg>',
      ok: 'Criar categoria',
      body: `<label class="field"><span>Nome</span><input id="cName" placeholder="Ex.: Viagens" required></label>` +
        `<div class="field-row"><label class="field"><span>Tipo</span><div class="select-wrap full"><select id="cTipo"><option value="despesa">Despesa</option><option value="receita">Receita</option></select><svg class="sel-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 9l6 6 6-6"/></svg></div></label></div>` +
        `<label class="field"><span>Emoji</span><div class="emoji-grid" id="cEmoji">${EMOJIS.map((e, i) => `<button type="button" class="emoji-pick${i === 0 ? ' on' : ''}" data-e="${e}">${e}</button>`).join('')}</div></label>` +
        `<label class="field"><span>Cor</span><div class="swatches" id="cSw">${CAT_PAL.map((c, i) => `<button type="button" class="sw${i === 0 ? ' on' : ''}" data-c="${c}" style="background:${c}"></button>`).join('')}</div></label>`,
      onMount: (sc) => {
        sc.addEventListener('click', (e) => {
          const em = e.target.closest('.emoji-pick'); if (em) { $$('.emoji-pick', sc).forEach(x => x.classList.remove('on')); em.classList.add('on'); }
          const sw = e.target.closest('.sw'); if (sw) { $$('.sw', sc).forEach(x => x.classList.remove('on')); sw.classList.add('on'); }
        });
      },
      submit: (sc) => {
        const name = $('#cName', sc).value.trim(); if (!name) return false;
        S.categories.push({ id: uid(), tipo: $('#cTipo', sc).value, name, emoji: ($('.emoji-pick.on', sc) || {}).dataset.e || '📦', color: ($('.sw.on', sc) || {}).dataset.c || '#1FA06E' });
        save(); renderCategorias(); toast('Categoria criada');
      },
    });
  }

  /* ---- investment modal (aporte / novo) ---- */
  function investModal() {
    miniModal({
      title: 'Novo investimento', sub: 'Defina o indexador e o aporte', icoCls: 'ico-in', cls: 'modal-lg',
      ico: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 19V6M4 19h16M8 16v-4M12 16V8M16 16v-7"/></svg>',
      ok: 'Adicionar investimento',
      body: `<label class="field"><span>Nome do ativo</span><input id="iName" placeholder="Ex.: CDB Banco XP" required></label>` +
        `<div class="field-row"><label class="field"><span>Classe</span><div class="select-wrap full"><select id="iClasse"><option>Renda fixa</option><option>Renda variável</option><option>Fundos</option><option>Cripto</option></select><svg class="sel-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 9l6 6 6-6"/></svg></div></label>` +
        `<label class="field"><span>Indexador</span><div class="select-wrap full"><select id="iIdx"><option>CDI</option><option>Selic</option><option>IPCA+</option><option>Prefixado</option><option value="—">Não indexado</option></select><svg class="sel-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 9l6 6 6-6"/></svg></div></label></div>` +
        `<div class="field-row"><label class="field"><span id="iTaxaLbl">% do CDI</span><input id="iTaxa" inputmode="decimal" placeholder="110"></label>` +
        `<label class="field"><span>Valor aplicado</span><input id="iAplic" inputmode="decimal" placeholder="R$ 1.000,00" required></label></div>` +
        `<label class="field"><span>Aporte mensal</span><input id="iAporte" inputmode="decimal" placeholder="R$ 200,00"></label>` +
        `<div class="inv-preview" id="iPreview"></div>`,
      onMount: (sc) => {
        const idx = $('#iIdx', sc), taxaLbl = $('#iTaxaLbl', sc), taxa = $('#iTaxa', sc), aplic = $('#iAplic', sc), prev = $('#iPreview', sc);
        const upd = () => {
          const lbls = { CDI: '% do CDI', Selic: '% da Selic', 'IPCA+': 'IPCA + (% a.a.)', Prefixado: 'Taxa fixa (% a.a.)', '—': 'Rentab. (% a.a.)' };
          taxaLbl.textContent = lbls[idx.value] || '% a.a.';
          const tmp = { indexador: idx.value, taxa: parseMoney(taxa.value), rentab: parseMoney(taxa.value) };
          const r = grossRate(tmp), v = parseMoney(aplic.value);
          const liquido = r * 0.85; // ~15% IR estimado
          prev.innerHTML = v ? `<div class="ivp-row"><span>Rentabilidade bruta estimada</span><b>${r.toFixed(2)}% a.a.</b></div><div class="ivp-row"><span>Líquido (após IR/IOF ~15%)</span><b class="pos">${liquido.toFixed(2)}% a.a.</b></div><div class="ivp-row"><span>Projeção em 12 meses</span><b>R$ ${BRL(v * (1 + liquido / 100))}</b></div>` : `<div class="ivp-hint">Preencha o valor para ver a projeção de rendimento.</div>`;
        };
        idx.addEventListener('change', upd); taxa.addEventListener('input', upd); aplic.addEventListener('input', upd); upd();
      },
      submit: (sc) => {
        const name = $('#iName', sc).value.trim(), v = parseMoney($('#iAplic', sc).value); if (!name || v <= 0) return false;
        const idx = $('#iIdx', sc).value, t = parseMoney($('#iTaxa', sc).value);
        const inv = { id: uid(), name, classe: $('#iClasse', sc).value, indexador: idx, taxa: t, aplicado: v, aporte: parseMoney($('#iAporte', sc).value), rentab: t || 9 };
        inv.rentab = grossRate(inv);
        S.investments.unshift(inv); save(); renderInvestimentos(); syncDashboard(); toast('Investimento adicionado');
      },
    });
  }
  function aporteModal(id) {
    const inv = S.investments.find(i => i.id === id); if (!inv) return;
    miniModal({
      title: 'Aportar em ' + inv.name, sub: 'Adicione um valor à posição', icoCls: 'ico-in',
      ico: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg>', ok: 'Aportar',
      body: `<label class="field"><span>Valor do aporte</span><input id="apVal" inputmode="decimal" placeholder="R$ 500,00" required></label>` +
        `<div class="inv-preview"><div class="ivp-row"><span>Posição atual</span><b>R$ ${BRL(inv.aplicado)}</b></div></div>`,
      submit: (sc) => { const v = parseMoney($('#apVal', sc).value); if (v <= 0) return false; inv.aplicado += v; save(); renderInvestimentos(); syncDashboard(); toast('Aporte de R$ ' + BRL(v) + ' realizado'); },
    });
  }

  /* ---- goal modals ---- */
  const GOAL_EMOJIS = ['✈️', '🏠', '🚗', '🏍️', '🛡️', '🎓', '💍', '🏖️', '💻', '👶', '🏥', '🎸'];
  function metaModal() {
    miniModal({
      title: 'Nova meta', sub: 'Defina seu objetivo', icoCls: 'ico-in', cls: 'modal-lg',
      ico: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/></svg>', ok: 'Criar meta',
      body: `<label class="field"><span>Nome da meta</span><input id="gName" placeholder="Ex.: Viagem para a Europa" required></label>` +
        `<div class="field-row"><label class="field"><span>Valor alvo</span><input id="gTarget" inputmode="decimal" placeholder="R$ 15.000,00" required></label>` +
        `<label class="field"><span>Já guardado</span><input id="gSaved" inputmode="decimal" placeholder="R$ 0,00"></label></div>` +
        `<label class="field"><span>Prazo</span><input id="gPrazo" type="month"></label>` +
        `<label class="field"><span>Ícone</span><div class="emoji-grid" id="gEmoji">${GOAL_EMOJIS.map((e, i) => `<button type="button" class="emoji-pick${i === 0 ? ' on' : ''}" data-e="${e}">${e}</button>`).join('')}</div></label>` +
        `<label class="field"><span>Cor</span><div class="swatches" id="gSw">${CAT_PAL.map((c, i) => `<button type="button" class="sw${i === 0 ? ' on' : ''}" data-c="${c}" style="background:${c}"></button>`).join('')}</div></label>`,
      onMount: (sc) => {
        sc.addEventListener('click', (e) => {
          const em = e.target.closest('.emoji-pick'); if (em) { $$('.emoji-pick', sc).forEach(x => x.classList.remove('on')); em.classList.add('on'); }
          const sw = e.target.closest('.sw'); if (sw) { $$('.sw', sc).forEach(x => x.classList.remove('on')); sw.classList.add('on'); }
        });
      },
      submit: (sc) => {
        const name = $('#gName', sc).value.trim(), t = parseMoney($('#gTarget', sc).value); if (!name || t <= 0) return false;
        S.goals.push({ id: uid(), name, emoji: ($('.emoji-pick.on', sc) || {}).dataset.e || '🎯', target: t, saved: parseMoney($('#gSaved', sc).value), color: ($('.sw.on', sc) || {}).dataset.c || '#1C9A70', prazo: $('#gPrazo', sc).value });
        save(); renderMetas(); syncDashboard(); toast('Meta criada');
      },
    });
  }
  function aporteMetaModal(id) {
    const g = S.goals.find(x => x.id === id); if (!g) return;
    const falta = Math.max(0, g.target - g.saved);
    miniModal({
      title: 'Aportar — ' + g.name, sub: 'Guarde um valor para esta meta', icoCls: 'ico-in',
      ico: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12h14"/></svg>`, ok: 'Guardar',
      body: `<label class="field"><span>Valor</span><input id="gmVal" inputmode="decimal" placeholder="R$ 500,00" required></label>` +
        `<div class="inv-preview"><div class="ivp-row"><span>Guardado</span><b>R$ ${BRL(g.saved)}</b></div><div class="ivp-row"><span>Faltam</span><b>R$ ${BRL(falta)}</b></div></div>`,
      submit: (sc) => { const v = parseMoney($('#gmVal', sc).value); if (v <= 0) return false; g.saved += v; save(); renderMetas(); syncDashboard(); toast('R$ ' + BRL(v) + ' guardado em ' + g.name); },
    });
  }

  /* ===================== LANÇAMENTO ===================== */
  const lanc = {
    type: 'despesa', mode: 'avista', who: null,
    renderWho() {
      const box = $('#lancWho'); if (!box) return;
      const people = loadResponsibles();
      if (!this.who || !people.some(p => p.name === this.who.name)) this.who = people[0];
      box.innerHTML = people.map(p =>
        `<button type="button" class="who-chip${p.name === this.who.name ? ' on' : ''}" data-name="${p.name}">` +
        `<span class="wa" style="background:${p.color}">${initials(p.name)}</span>` +
        `<span class="wn">${p.name.split(' ')[0]}<small>${p.admin ? 'Titular' : p.role}</small></span></button>`
      ).join('');
      $$('.who-chip', box).forEach(b => b.addEventListener('click', () => {
        this.who = people.find(p => p.name === b.dataset.name);
        $$('.who-chip', box).forEach(x => x.classList.toggle('on', x === b));
      }));
    },
    open(type) {
      this.type = type || 'despesa'; this.mode = 'avista';
      const f = $('#lancForm'); f.reset();
      $('#lancData').value = todayISO();
      const ps = $('#lancParcelas'); ps.innerHTML = ''; for (let i = 2; i <= 24; i++) ps.innerHTML += `<option value="${i}">${i}x</option>`;
      fillMethodSelect($('#lancMethod'));
      this.renderWho();
      $$('#lancType button').forEach(b => b.classList.toggle('active', b.dataset.t === this.type));
      this.syncType();
      $('#lancScrim').classList.add('open');
      setTimeout(() => $('#lancDesc').focus(), 130);
    },
    close() { $('#lancScrim').classList.remove('open'); },
    syncType() {
      const isDesp = this.type === 'despesa';
      $('#lancTitle').textContent = isDesp ? 'Nova despesa' : 'Nova receita';
      $('#lancSubmit').textContent = isDesp ? 'Lançar despesa' : 'Lançar receita';
      $('#lancIco').className = 'modal-ico' + (isDesp ? ' ico-out' : ' ico-in');
      $('#lancPayWrap').hidden = !isDesp;
      $('#lancRecorrente').hidden = isDesp;
      $('#lancCat').innerHTML = catOptions(this.type);
      if (isDesp) this.syncMethod();
    },
    syncMethod() {
      const m = method($('#lancMethod').value);
      const isCard = m && m.kind === 'cartao';
      $('#lancCardOpts').hidden = !isCard;
      if (!isCard) this.mode = 'avista';
      this.syncMode();
    },
    syncMode() {
      $$('#payMode button').forEach(b => b.classList.toggle('active', b.dataset.m === this.mode));
      $('#parcelasField').hidden = this.mode !== 'parcelado';
      this.syncHint();
    },
    syncHint() {
      const val = parseMoney($('#lancValor').value), n = +$('#lancParcelas').value || 2;
      $('#parcelaHint').textContent = val ? `${n}x de R$ ${BRL(val / n)} (sem juros)` : '';
    },
    submit() {
      const desc = $('#lancDesc').value.trim(), valor = parseMoney($('#lancValor').value);
      if (!desc || valor <= 0) return toast('Preencha descrição e valor');
      const cat = $('#lancCat').value, date = $('#lancData').value || todayISO();
      if (this.type === 'receita') {
        S.entries.unshift({ id: uid(), tipo: 'receita', desc, valor, cat, date, recorrente: $('#lancRec').checked });
        const acc = accounts()[0]; if (acc) acc.balance += valor;
        toast('Receita lançada · saldo atualizado');
      } else {
        const m = method($('#lancMethod').value);
        const quem = this.who ? { name: this.who.name, color: this.who.color } : null;
        if (m && m.kind === 'cartao') {
          const e = { id: uid(), tipo: 'despesa', desc, valor, cat, date, method: m.id, mode: this.mode, quem };
          if (this.mode === 'parcelado') { e.parcelas = +$('#lancParcelas').value || 2; e.parcelaAtual = 1; }
          S.entries.unshift(e); toast('Despesa lançada na fatura');
        } else {
          // débito/pix/conta — sai do saldo da conta vinculada
          const accId = m && m.kind === 'conta' ? m.id : (m && m.linked) || (accounts()[0] && accounts()[0].id);
          const acc = method(accId); if (acc) acc.balance -= valor;
          S.entries.unshift({ id: uid(), tipo: 'despesa', desc, valor, cat, date, method: m ? m.id : 'conta', mode: 'avista', quem });
          toast('Despesa paga · saldo atualizado');
        }
      }
      save(); syncDashboard(); fillFaturaFilter(); renderFaturas();
      this.close();
    },
  };

  /* ===================== TOAST ===================== */
  let toEl;
  function toast(msg) {
    if (!toEl) { toEl = document.createElement('div'); toEl.className = 'sm-toast'; document.body.appendChild(toEl); }
    toEl.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M20 6 9 17l-5-5"/></svg>${msg}`;
    toEl.classList.add('show'); clearTimeout(toEl._t); toEl._t = setTimeout(() => toEl.classList.remove('show'), 2600);
  }

  /* ===================== WIRE ===================== */
  function init() {
    syncDashboard(); fillFaturaFilter();
    const on = (sel, fn) => { const el = $(sel); if (el) el.addEventListener('click', fn); };
    on('#launchBtn', () => lanc.open('despesa'));
    on('.bn-item.fab', () => lanc.open('despesa'));
    on('#newDespesaBtn', () => lanc.open('despesa'));
    on('#newCardBtn', addMethodModal);
    on('#newCatBtn', addCatModal);
    on('#newInvBtn', () => investModal());
    on('#newMetaBtn', () => metaModal());
    const ff = $('#faturaFilter'); if (ff) ff.addEventListener('change', renderFaturas);

    $('#lancClose').addEventListener('click', () => lanc.close());
    $('#lancCancel').addEventListener('click', () => lanc.close());
    $('#lancScrim').addEventListener('click', e => { if (e.target === $('#lancScrim')) lanc.close(); });
    $$('#lancType button').forEach(b => b.addEventListener('click', () => { lanc.type = b.dataset.t; $$('#lancType button').forEach(x => x.classList.toggle('active', x === b)); lanc.syncType(); }));
    $('#lancMethod').addEventListener('change', () => lanc.syncMethod());
    $$('#payMode button').forEach(b => b.addEventListener('click', () => { lanc.mode = b.dataset.m; lanc.syncMode(); }));
    $('#lancParcelas').addEventListener('change', () => lanc.syncHint());
    $('#lancValor').addEventListener('input', () => lanc.syncHint());
    $('#lancForm').addEventListener('submit', e => { e.preventDefault(); lanc.submit(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') lanc.close(); });

    document.addEventListener('sm:viewchange', (e) => {
      if (e.detail === 'faturas') renderFaturas();
      else if (e.detail === 'cartoes') renderMetodos();
      else if (e.detail === 'categorias') renderCategorias();
      else if (e.detail === 'investimentos') renderInvestimentos();
      else if (e.detail === 'metas') renderMetas();
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
