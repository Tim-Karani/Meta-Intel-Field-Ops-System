import { h, clear, debounce } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api, latest } from '../core/api.js';
import { empty, errorBox, loading } from './feedback.js';
import { normalise } from './form.js';

/**
 * Server-driven data table.
 *  columns: [{ key, label, sortable, render(row), class, align }]
 *  filters: [{ key, label, options|loadOptions, type: 'select'|'date' }]
 * State (page, sort, search, filters) lives in the component; each change re-queries the API.
 */
export function dataTable({ endpoint, columns, filters = [], search = true, searchPlaceholder = 'Search…', defaultSort, query = {}, perPage = 20, onRowClick, rowClass, emptyTitle = 'Nothing here yet', emptyText, emptyAction, toolbarExtra, mapRows, unwrap, id }) {
  const state = { page: 1, sort: defaultSort || null, search: '', filters: {} };
  const root = h('div', { class: 'card' });
  const toolbar = h('div', { class: 'toolbar' });
  const body = h('div', { class: 'table-wrap' });
  const pager = h('div', { class: 'pager' });
  root.append(toolbar, body, pager);

  if (search) {
    const input = h('input', { class: 'input', type: 'search', placeholder: searchPlaceholder, 'aria-label': 'Search', style: { minWidth: '220px' }, oninput: debounce(() => { state.search = input.value.trim(); state.page = 1; load(); }, 350) });
    toolbar.append(h('div', { class: 'field' }, input));
  }
  for (const f of filters) {
    const sel = h('select', { class: 'select', 'aria-label': f.label, onchange: () => { state.filters[f.key] = sel.value; state.page = 1; load(); } }, h('option', { value: '' }, f.label));
    const fill = (opts) => { opts = normalise(opts); sel.append(...opts.map((o) => h('option', { value: o.value }, o.label))); };
    if (f.type === 'date') {
      const d = h('input', { class: 'input', type: 'date', 'aria-label': f.label, title: f.label, onchange: () => { state.filters[f.key] = d.value; state.page = 1; load(); } });
      toolbar.append(h('div', { class: 'field' }, d));
      continue;
    }
    if (f.options) fill(f.options);
    else if (f.loadOptions) f.loadOptions().then(fill).catch(() => {});
    toolbar.append(h('div', { class: 'field' }, sel));
  }
  if (toolbarExtra) toolbar.append(h('span', { class: 'spacer' }), toolbarExtra);

  async function load() {
    const signal = latest(`table:${id || endpoint}`);
    clear(body).append(loading());
    try {
      const params = { ...query, page: state.page, per_page: perPage, sort: state.sort, search: state.search || undefined, ...Object.fromEntries(Object.entries(state.filters).filter(([, v]) => v)) };
      const res = await api.get(endpoint, params, { signal });
      let rows = unwrap ? unwrap(res.data) : res.data;
      if (mapRows) rows = mapRows(rows);
      paint(rows, res.meta);
    } catch (err) {
      if (err.message === 'Request cancelled.') return;
      clear(body).append(errorBox(err, load)); clear(pager);
    }
  }

  function paint(rows, meta) {
    clear(body); clear(pager);
    if (!rows.length) {
      const filtered = state.search || Object.values(state.filters).some(Boolean);
      body.append(empty(filtered ? 'No matching results' : emptyTitle, filtered ? 'Try different search terms or clear the filters.' : emptyText, filtered ? null : emptyAction));
      return;
    }
    const head = h('tr', null, columns.map((c) => {
      const active = state.sort && state.sort.replace('-', '') === c.sort;
      const dir = active ? (state.sort.startsWith('-') ? 'descending' : 'ascending') : 'none';
      return h('th', { scope: 'col', 'aria-sort': c.sort ? dir : null, class: c.align === 'right' ? 'right' : null },
        c.sort ? h('button', { type: 'button', onclick: () => { state.sort = active && dir === 'ascending' ? `-${c.sort}` : c.sort; load(); } }, c.label, active && icon(dir === 'ascending' ? 'arrowUp' : 'arrowDown', { size: 12 })) : c.label);
    }));
    const tbody = h('tbody', null, rows.map((r) => {
      const tr = h('tr', { class: [onRowClick && 'clickable', rowClass?.(r)], tabindex: onRowClick ? 0 : null,
        onclick: onRowClick ? (e) => { if (!e.target.closest('a,button,input,select,label')) onRowClick(r); } : null,
        onkeydown: onRowClick ? (e) => { if (e.key === 'Enter' && e.target === tr) onRowClick(r); } : null },
      columns.map((c) => h('td', { class: [c.class, c.align === 'right' && 'right'] }, c.render ? c.render(r) : (r[c.key] ?? h('span', { class: 'muted' }, '—')))));
      return tr;
    }));
    body.append(h('table', { class: 'data' }, h('thead', null, head), tbody));

    if (meta?.total != null) {
      const from = (meta.page - 1) * meta.per_page + 1; const to = Math.min(meta.total, meta.page * meta.per_page);
      pager.append(h('span', null, `${from}–${to} of ${meta.total.toLocaleString()}`), h('span', { class: 'spacer' }),
        h('button', { class: 'btn sm', type: 'button', disabled: meta.page <= 1, onclick: () => { state.page--; load(); } }, icon('chevronLeft', { size: 14 }), 'Prev'),
        h('span', null, `Page ${meta.page} of ${meta.last_page}`),
        h('button', { class: 'btn sm', type: 'button', disabled: meta.page >= meta.last_page, onclick: () => { state.page++; load(); } }, 'Next', icon('chevronRight', { size: 14 })));
    }
  }

  load();
  return Object.assign(root, { reload: load, state });
}

/** Static table from rows already in memory. */
export function simpleTable(columns, rows, { emptyText = 'No data.', onRowClick } = {}) {
  if (!rows?.length) return h('div', { class: 'muted', style: { padding: '16px' } }, emptyText);
  return h('div', { class: 'table-wrap' }, h('table', { class: 'data' },
    h('thead', null, h('tr', null, columns.map((c) => h('th', { scope: 'col', class: c.align === 'right' ? 'right' : null }, c.label)))),
    h('tbody', null, rows.map((r) => h('tr', { class: onRowClick && 'clickable', onclick: onRowClick ? () => onRowClick(r) : null },
      columns.map((c) => h('td', { class: [c.class, c.align === 'right' && 'right'] }, c.render ? c.render(r) : (r[c.key] ?? h('span', { class: 'muted' }, '—')))))))));
}
