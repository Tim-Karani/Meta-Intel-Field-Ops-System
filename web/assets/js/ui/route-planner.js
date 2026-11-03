import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api } from '../core/api.js';
import { lookup, enumOptions } from '../core/lookups.js';
import { openModal, toast, toastError } from './feedback.js';

/* The route planner: one agent's outlets by weekday, built for hundreds of outlets.
   - Board: one row per outlet, one cell per weekday. Click a cell to switch that day on or off.
   - Brush: when the project has more than one activity, pick the activity you are painting with; a cell shows which
     activity runs there, so the same outlet can run one activity on weekdays and another on the weekend.
   - Add outlets: filter the project's outlets (search, chain, type, territory), tick many at once, choose the days and add.
   - Bulk: tick rows on the board, then set their days in one go or remove them.
   The server stores one stop per (outlet, weekday); saving replaces the plan and creates the next two weeks of visits. */

const DAYS = [[1, 'Mon'], [2, 'Tue'], [3, 'Wed'], [4, 'Thu'], [5, 'Fri'], [6, 'Sat'], [7, 'Sun']];
const SETS = { 'Mon–Fri': [1, 2, 3, 4, 5], Weekend: [6, 7], 'Every day': [1, 2, 3, 4, 5, 6, 7] };
const plural = (n, w) => `${n} ${w}${n === 1 ? '' : 's'}`;

export async function openRoutePlanner({ plan, onSaved }) {
  let full;
  try { full = (await api.get(`journey-plans/${plan.id}`)).data; } catch (e) { toastError(e); return null; }
  const campaignId = full.campaign_id;
  const [activities, owners, chains, types] = await Promise.all([
    api.get('activities', { campaign_id: campaignId, per_page: 100 }).then((r) => r.data.filter((a) => !['cancelled', 'completed'].includes(a.status))).catch(() => []),
    api.get('journey-plans/owners', { campaign_id: campaignId, except_plan: plan.id }).then((r) => r.data).catch(() => ({})),
    lookup('channels').catch(() => []), enumOptions('location_types').catch(() => []),
  ]);

  /* ---------- state ---------- */
  const rows = new Map();             // location id -> { id, name, town, chain, days: Map(weekday -> activity id | null), picked, cells: Map, tr }
  for (const s of full.stops || []) {
    rows.set(s.location_id, { id: s.location_id, name: s.location?.name || 'Outlet', town: s.location?.town || '', chain: s.location?.chain || '', days: new Map((s.days || s.weekdays.map((d) => ({ weekday: d, activity_id: s.activity_id }))).map((d) => [d.weekday, d.activity_id ?? null])), picked: false });
  }
  let brush = activities.length === 1 ? activities[0].id : null;     // activity painted onto a cell (null = none / the project's form)
  const filter = { text: '', chain: '', show: 'all' };
  const actIndex = (id) => Math.max(0, activities.findIndex((a) => a.id === id));
  const actName = (id) => activities.find((a) => a.id === id)?.name || 'No activity';

  /* ---------- board ---------- */
  const summary = h('div', { class: 'rp-summary' });
  const banner = h('div', { class: 'hidden', role: 'alert' });
  const bulk = h('div', { class: 'bulk-bar hidden' });
  const tbody = h('tbody');
  const headCells = DAYS.map(([n, label]) => h('th', { class: 'rp-day' }, h('button', { class: 'rp-dayhead', type: 'button', title: `Switch ${label} on for every outlet shown, or off if they are all on`, onclick: () => dayColumn(n) }, label, h('span', { class: 'rp-count' }))));
  const checkAll = h('input', { type: 'checkbox', 'aria-label': 'Select every outlet shown', onchange: () => { visibleRows().forEach((r) => { r.picked = checkAll.checked; r.box.checked = checkAll.checked; }); paintBulk(); } });
  const table = h('table', { class: 'rp-board' }, h('thead', null, h('tr', null, h('th', { class: 'rp-pick' }, checkAll), h('th', null, 'Outlet'), ...headCells)), tbody);
  const emptyNote = h('p', { class: 'muted', style: { padding: '18px' } });

  const visibleRows = () => [...rows.values()].filter((r) => {
    if (filter.text && !`${r.name} ${r.town} ${r.chain}`.toLowerCase().includes(filter.text)) return false;
    if (filter.chain && r.chain !== filter.chain) return false;
    if (filter.show === 'nodays' && r.days.size) return false;
    if (filter.show === 'others' && !(owners[r.id] || []).length) return false;
    return true;
  });

  const cellState = (r, n, btn) => {
    const has = r.days.has(n);
    const act = r.days.get(n);
    btn.className = `rp-cell${has ? ` on a${actIndex(act) % 4}` : ''}`;
    btn.textContent = has ? (act && activities.length > 1 ? (actName(act)[0] || '•').toUpperCase() : '✓') : '';
    btn.title = has ? `${DAYS[n - 1][1]}: ${act ? actName(act) : 'visit'}. Click to ${act === brush ? 'switch off' : 'paint with the selected activity'}.` : `${DAYS[n - 1][1]}: not visited. Click to add.`;
    btn.setAttribute('aria-pressed', String(has));
  };
  const clickCell = (r, n) => {
    if (r.days.has(n) && r.days.get(n) === brush) r.days.delete(n); else r.days.set(n, brush);
    r.cells.forEach((btn, d) => cellState(r, d, btn)); paintSummary();
  };
  const buildRow = (r) => {
    r.cells = new Map();
    r.box = h('input', { type: 'checkbox', 'aria-label': `Select ${r.name}`, checked: r.picked, onchange: () => { r.picked = r.box.checked; paintBulk(); } });
    const others = owners[r.id] || [];
    r.tr = h('tr', null,
      h('td', { class: 'rp-pick' }, r.box),
      h('td', { class: 'rp-name' }, h('strong', null, r.name), h('div', { class: 'small muted' }, [r.chain, r.town].filter(Boolean).join(' · ') || ' '), others.length > 0 && h('div', { class: 'small warn-text', title: `Also on the plan of ${others.join(', ')}` }, `Also covered by ${others.slice(0, 2).join(', ')}${others.length > 2 ? ` +${others.length - 2}` : ''}`)),
      ...DAYS.map(([n]) => { const btn = h('button', { type: 'button', onclick: () => clickCell(r, n) }); r.cells.set(n, btn); cellState(r, n, btn); return h('td', { class: 'rp-day' }, btn); }));
    return r.tr;
  };
  const paintBoard = () => {
    const list = visibleRows();
    list.forEach((r) => { if (!r.tr) buildRow(r); });
    tbody.replaceChildren(...list.map((r) => r.tr));
    emptyNote.textContent = rows.size ? 'No outlets match these filters.' : 'No outlets yet. Use "Add outlets" to bring in the ones this agent visits.';
    emptyNote.hidden = list.length > 0;
    table.hidden = list.length === 0;
    paintSummary(); paintBulk();
  };
  function paintSummary() {
    const all = [...rows.values()];
    const visits = all.reduce((n, r) => n + r.days.size, 0);
    const nodays = all.filter((r) => !r.days.size).length;
    summary.replaceChildren(...[h('strong', null, plural(all.length, 'outlet')), ` · ${plural(visits, 'visit')} a week`, nodays ? h('span', { class: 'warn-text' }, ` · ${nodays} still need a day`) : null].filter(Boolean));
    DAYS.forEach(([n], i) => { headCells[i].querySelector('.rp-count').textContent = String(all.filter((r) => r.days.has(n)).length); });
    all.forEach((r) => r.cells?.forEach((btn, d) => cellState(r, d, btn)));
  }
  function dayColumn(n) {
    const list = visibleRows();
    const allOn = list.length > 0 && list.every((r) => r.days.get(n) === brush && r.days.has(n));
    list.forEach((r) => { if (allOn) r.days.delete(n); else r.days.set(n, brush); });
    paintSummary();
  }
  function paintBulk() {
    const picked = [...rows.values()].filter((r) => r.picked);
    bulk.classList.toggle('hidden', picked.length === 0);
    if (!picked.length) return;
    const apply = (days) => { picked.forEach((r) => days.forEach((d) => r.days.set(d, brush))); paintSummary(); };
    bulk.replaceChildren(h('strong', null, `${plural(picked.length, 'outlet')} selected`), h('span', { class: 'muted small' }, 'Set days:'),
      ...Object.entries(SETS).map(([label, days]) => h('button', { class: 'btn sm', type: 'button', onclick: () => apply(days) }, label)),
      h('button', { class: 'btn sm', type: 'button', onclick: () => { picked.forEach((r) => r.days.clear()); paintSummary(); } }, 'Clear days'),
      h('button', { class: 'btn sm danger', type: 'button', onclick: () => { picked.forEach((r) => rows.delete(r.id)); checkAll.checked = false; paintBoard(); } }, icon('trash', { size: 14 }), 'Remove'),
      h('button', { class: 'btn sm ghost', type: 'button', onclick: () => { picked.forEach((r) => { r.picked = false; r.box.checked = false; }); paintBulk(); } }, 'Unselect'));
  }

  /* ---------- toolbar: search, chain, quick views, brush ---------- */
  const search = h('input', { class: 'input', type: 'search', placeholder: 'Search the plan…', 'aria-label': 'Search outlets in the plan' });
  let t; search.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => { filter.text = search.value.trim().toLowerCase(); paintBoard(); }, 200); });
  const chainSel = h('select', { class: 'select', 'aria-label': 'Filter by chain', onchange: () => { filter.chain = chainSel.value; paintBoard(); } }, h('option', { value: '' }, 'All chains'));
  const refreshChains = () => { const names = [...new Set([...rows.values()].map((r) => r.chain).filter(Boolean))].sort(); chainSel.replaceChildren(h('option', { value: '' }, 'All chains'), ...names.map((c) => h('option', { value: c }, c))); chainSel.value = filter.chain; };
  const showSel = h('select', { class: 'select', 'aria-label': 'Show', onchange: () => { filter.show = showSel.value; paintBoard(); } },
    h('option', { value: 'all' }, 'All outlets'), h('option', { value: 'nodays' }, 'Needing a day'), h('option', { value: 'others' }, 'Also covered by someone else'));
  const brushBar = h('div', { class: 'rp-brush' });
  const paintBrush = () => {
    if (activities.length <= 1) { brushBar.replaceChildren(); return; }
    brushBar.replaceChildren(h('span', { class: 'small muted' }, 'Painting with'),
      h('button', { class: ['btn', 'sm', brush === null && 'primary'], type: 'button', onclick: () => { brush = null; paintBrush(); } }, 'No activity'),
      ...activities.map((a, i) => h('button', { class: ['btn', 'sm', brush === a.id && 'primary'], type: 'button', onclick: () => { brush = a.id; paintBrush(); } }, h('span', { class: `rp-dot a${i % 4}` }), a.name)));
  };

  /* ---------- adding outlets ---------- */
  const adder = h('section', { class: 'rp-add hidden' });
  const openAdder = () => {
    adder.classList.remove('hidden');
    const f = { text: '', chain: '', type: '', territory: '' };
    const picked = new Set(); const seen = new Map(); let page = 0; let last = 1; let loading = false;
    const days = new Set([1, 2, 3, 4, 5]);
    const list = h('div', { class: 'rp-results' });
    const status = h('div', { class: 'small muted' });
    const addBtn = h('button', { class: 'btn primary rp-addbtn', type: 'button' });
    const more = h('button', { class: 'btn sm', type: 'button', onclick: () => load(false) }, 'Load more');
    const pickAll = h('button', { class: 'btn sm', type: 'button' }, 'Select all matching');
    const dayChips = h('div', { class: 'row wrap', style: { gap: '6px' } });
    const hideOthers = h('input', { type: 'checkbox', id: 'rp-hide' });

    const paintChips = () => {
      dayChips.replaceChildren(...DAYS.map(([n, label]) => h('button', { class: ['btn', 'sm', days.has(n) && 'primary'], type: 'button', 'aria-pressed': String(days.has(n)), onclick: () => { days.has(n) ? days.delete(n) : days.add(n); paintChips(); } }, label)),
        ...Object.entries(SETS).map(([label, d]) => h('button', { class: 'btn sm ghost', type: 'button', onclick: () => { days.clear(); d.forEach((x) => days.add(x)); paintChips(); } }, label)));
      addBtn.textContent = picked.size ? `Add ${plural(picked.size, 'outlet')}${days.size ? ` on ${days.size === 7 ? 'every day' : [...days].sort().map((d) => DAYS[d - 1][1]).join(', ')}` : ' (choose days)'}` : 'Add outlets';
      addBtn.disabled = !picked.size;
    };
    const paintList = () => {
      const shown = [...seen.values()].filter((o) => !rows.has(o.id) && !(hideOthers.checked && (owners[o.id] || []).length));
      list.replaceChildren(...shown.map((o) => h('label', { class: 'rp-opt' },
        h('input', { type: 'checkbox', checked: picked.has(o.id), onchange: (e) => { e.target.checked ? picked.add(o.id) : picked.delete(o.id); paintChips(); } }),
        h('span', null, h('strong', null, o.name), h('span', { class: 'small muted' }, ` ${[o.channel?.name, o.town].filter(Boolean).join(' · ')}`), (owners[o.id] || []).length > 0 && h('span', { class: 'small warn-text' }, ` · also with ${owners[o.id].slice(0, 2).join(', ')}`)))));
      if (!shown.length) list.append(h('p', { class: 'muted small', style: { padding: '10px' } }, loading ? 'Loading…' : 'No outlets to add with these filters. Outlets must be linked to the project first (Campaigns > Teams, locations & more).'));
      more.hidden = page >= last;
      status.textContent = `${plural(shown.length, 'outlet')} shown${page < last ? ' so far' : ''}.`;
    };
    async function load(reset) {
      if (loading) return;
      if (reset) { seen.clear(); page = 0; last = 1; }
      loading = true; paintList();
      try {
        const res = await api.get('locations', { campaign_id: campaignId, search: f.text || undefined, channel_id: f.chain || undefined, type: f.type || undefined, territory_id: f.territory || undefined, status: 'active', per_page: 100, page: page + 1, sort: 'name' });
        res.data.forEach((o) => seen.set(o.id, o)); page = res.meta?.page ?? page + 1; last = res.meta?.last_page ?? page;
      } catch (e) { toastError(e); }
      loading = false; paintList();
    }
    const s = h('input', { class: 'input', type: 'search', placeholder: 'Search outlets…', 'aria-label': 'Search outlets to add' });
    let st; s.addEventListener('input', () => { clearTimeout(st); st = setTimeout(() => { f.text = s.value.trim(); load(true); }, 300); });
    const sel = (label, blank, opts, key) => h('select', { class: 'select', 'aria-label': label, onchange: (e) => { f[key] = e.target.value; load(true); } }, h('option', { value: '' }, blank), ...opts.map((o) => h('option', { value: o.value }, o.label)));
    pickAll.addEventListener('click', async () => {
      pickAll.disabled = true;
      while (page < last && seen.size < 500) await load(false);
      [...seen.values()].filter((o) => !rows.has(o.id) && !(hideOthers.checked && (owners[o.id] || []).length)).slice(0, 500).forEach((o) => picked.add(o.id));
      pickAll.disabled = false; paintList(); paintChips();
    });
    hideOthers.addEventListener('change', paintList);
    addBtn.addEventListener('click', () => {
      if (!days.size) { toast('Choose at least one day first.', 'error'); return; }
      let n = 0;
      picked.forEach((id) => { const o = seen.get(id); if (!o || rows.has(id)) return; rows.set(id, { id, name: o.name, town: o.town || '', chain: o.channel?.name || '', days: new Map([...days].map((d) => [d, brush])), picked: false }); n++; });
      picked.clear(); refreshChains(); paintBoard(); paintList(); paintChips();
      toast(`${plural(n, 'outlet')} added. Review the board, then save.`);
    });
    adder.replaceChildren(
      h('div', { class: 'rp-add-head' }, h('strong', null, 'Add outlets from the project'), h('button', { class: 'btn sm ghost', type: 'button', onclick: () => adder.classList.add('hidden') }, 'Close')),
      h('div', { class: 'toolbar' }, h('div', { class: 'field grow' }, s), sel('Chain', 'All chains', chains, 'chain'), sel('Type', 'All types', types, 'type')),
      h('div', { class: 'row wrap', style: { gap: '10px' } }, pickAll, h('label', { class: 'check', for: 'rp-hide' }, hideOthers, 'Hide outlets already on another agent\'s plan'), status),
      list, h('div', null, more),
      h('div', { class: 'rp-add-foot' }, h('div', { class: 'small muted' }, 'Visit on'), dayChips, addBtn));
    paintChips(); load(true);
  };

  /* ---------- save ---------- */
  const save = h('button', { class: 'btn primary', type: 'button' }, 'Save plan');
  const body = h('div', { class: 'stack rp' },
    h('p', { class: 'muted' }, `${full.campaign?.name}. Saving creates the next two weeks of visits for ${full.agent?.name}; outlets or days you take off lose their upcoming visits that have not been started.`),
    banner,
    h('div', { class: 'rp-top' }, summary, h('button', { class: 'btn', type: 'button', onclick: openAdder }, icon('plus', { size: 16 }), 'Add outlets')),
    adder, brushBar,
    h('div', { class: 'toolbar' }, h('div', { class: 'field grow' }, search), chainSel, showSel), bulk,
    h('div', { class: 'rp-wrap' }, table, emptyNote));
  const modal = openModal({ title: `Journey plan · ${full.agent?.name}`, size: 'wide', body, footer: (close) => [h('button', { class: 'btn', type: 'button', onclick: () => close() }, 'Close'), save] });

  save.addEventListener('click', async () => {
    banner.className = 'hidden';
    const bare = [...rows.values()].filter((r) => !r.days.size);
    if (bare.length) {
      filter.show = 'nodays'; showSel.value = 'nodays'; paintBoard();
      banner.className = 'alert error'; banner.textContent = `${plural(bare.length, 'outlet')} ha${bare.length === 1 ? 's' : 've'} no day. Click the days on the board, or remove them. They are shown now.`; return;
    }
    const stops = [];
    for (const r of rows.values()) {
      const byActivity = new Map();
      r.days.forEach((act, d) => { const k = act ?? ''; if (!byActivity.has(k)) byActivity.set(k, []); byActivity.get(k).push(d); });
      byActivity.forEach((weekdays, k) => stops.push({ location_id: r.id, weekdays: weekdays.sort(), ...(k ? { activity_id: k } : {}) }));
    }
    save.disabled = true;
    try {
      await api.put(`journey-plans/${plan.id}/stops`, { stops });
      toast('Journey plan saved. Visits for the next two weeks were created.'); modal.close(); onSaved?.();
    } catch (e) { banner.className = 'alert error'; banner.textContent = e.message || 'Could not save.'; } finally { save.disabled = false; }
  });

  refreshChains(); paintBrush(); paintBoard();
  return modal;
}
