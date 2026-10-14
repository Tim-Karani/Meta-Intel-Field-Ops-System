import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api } from '../core/api.js';
import { can } from '../core/auth.js';
import { fmtDate, fmtMoney, fmtNumber, fmtPercent, titleCase } from '../core/format.js';
import { asyncView, badge, card, pageHead, stat, toastError, empty, actionButton } from '../ui/feedback.js';
import { simpleTable } from '../ui/table.js';
import { selectControl } from '../ui/form.js';
import { lookup } from '../core/lookups.js';
import { href } from '../core/router.js';

const BLURB = {
  campaign_performance: 'Visits, approvals and sales per campaign.', attendance: 'Clock-in rates and punctuality.', agent_performance: 'Output and quality per field agent.',
  supervisor_performance: 'Team results per supervisor.', visit_completion: 'Planned vs completed visits.', sales: 'Sales value and units.', osa: 'On-shelf availability.',
  merchandising: 'POSM, planogram and price compliance.', location_performance: 'Results per outlet.', issue_management: 'Open, resolved and overdue issues.',
  evidence: 'Photo volume and review quality.', activity_performance: 'Results per activity.', territory_performance: 'Results per territory.',
};

export default async function render({ container, params, query }) {
  if (!params.type) return index(container);
  return runReport(container, params.type, query);
}

async function index(container) {
  container.append(pageHead({ title: 'Reports', subtitle: 'Choose a report. Results respect your access scope; exports are logged.' }));
  const host = h('div'); container.append(host);
  asyncView(host, () => api.get('reports').then((r) => r.data), (types) => h('div', { class: 'grid cols-3' }, types.map((t) =>
    h('a', { class: 'card report-tile', href: href(`/reports/${t.type}`) }, h('div', { class: 'card-body' }, h('div', { class: 'row' }, icon('bars', { size: 20 }), h('strong', null, t.label)), h('p', { class: 'muted small', style: { margin: '8px 0 0' } }, BLURB[t.type] || ''))))));
}

const FILTER_DEFS = [
  { key: 'client_id', label: 'All clients', entity: 'clients' }, { key: 'campaign_id', label: 'All campaigns', entity: 'campaigns' }, { key: 'team_id', label: 'All teams', entity: 'teams' },
  { key: 'territory_id', label: 'All territories', entity: 'territories' }, { key: 'agent_id', label: 'All agents', entity: 'agents' }, { key: 'supervisor_id', label: 'All supervisors', entity: 'supervisors' },
];

async function runReport(container, type, query) {
  const filters = {};
  ['date_from', 'date_to', 'group_by', ...FILTER_DEFS.map((f) => f.key)].forEach((k) => { if (query?.[k]) filters[k] = query[k]; });
  const result = h('div');
  const canExport = can('reports.export');
  const head = pageHead({ title: titleCase(type), subtitle: BLURB[type], actions: [h('a', { class: 'btn', href: href('/reports') }, 'All reports'), canExport && actionButton('Export CSV', async () => { try { await api.download(`reports/${type}/export`, filters, `${type}.csv`); } catch (e) { toastError(e); } }, { iconName: 'download' })] });
  const bar = h('div', { class: 'toolbar' });
  const date = (key, label) => { const el = h('input', { class: 'input', type: 'date', 'aria-label': label, title: label, value: filters[key] || '', onchange: () => { el.value ? filters[key] = el.value : delete filters[key]; run(); } }); return h('div', { class: 'field' }, el); };
  bar.append(date('date_from', 'From date'), date('date_to', 'To date'));
  const defs = await Promise.all(FILTER_DEFS.map(async (f) => { try { return { ...f, options: await lookup(f.entity) }; } catch { return null; } }));
  defs.filter(Boolean).forEach((f) => bar.append(selectControl({ label: f.label, options: f.options, blank: f.label, value: filters[f.key] || '', onChange: (v) => { v ? filters[f.key] = v : delete filters[f.key]; run(); } })));
  if (type === 'sales') bar.append(selectControl({ label: 'Group by', options: ['product', 'agent', 'location', 'campaign', 'date', 'territory'], blank: 'Group by product', value: filters.group_by || '', onChange: (v) => { v ? filters.group_by = v : delete filters.group_by; run(); } }));
  container.append(head, h('div', { class: 'card', style: { marginBottom: '16px' } }, bar), result);

  function run() {
    const qs = new URLSearchParams(filters).toString();
    history.replaceState(null, '', `#/reports/${type}${qs ? `?${qs}` : ''}`);
    asyncView(result, () => api.get(`reports/${type}`, filters).then((r) => r.data), (rep) => {
      const fmt = { int: (v) => fmtNumber(v), decimal: (v) => fmtNumber(v, 2), money: (v) => fmtMoney(v), percent: (v) => fmtPercent(v), date: (v) => fmtDate(v), status: (v) => badge(v), text: (v) => v };
      const cols = rep.columns.map((c) => ({ label: c.label, align: ['int', 'decimal', 'money', 'percent'].includes(c.type) ? 'right' : undefined, render: (r) => { const v = r[c.key]; return v === null || v === undefined || v === '' ? h('span', { class: 'muted' }, '—') : (fmt[c.type] || fmt.text)(v); } }));
      const summary = Object.entries(rep.summary || {}).filter(([k]) => k !== 'note');
      return h('div', { class: 'stack' },
        rep.approved_only && h('div', { class: 'alert info' }, icon('info'), h('div', null, 'Only approved visits are included in this report.')),
        rep.summary?.note && h('div', { class: 'alert info' }, icon('info'), h('div', null, rep.summary.note)),
        summary.length > 0 && h('div', { class: 'grid cols-4' }, summary.map(([k, v]) => stat({ label: titleCase(k), value: typeof v === 'number' ? (/value|revenue|sales/.test(k) && !/count/.test(k) ? fmtMoney(v) : /rate|percent|pct/.test(k) ? fmtPercent(v) : fmtNumber(v, Number.isInteger(v) ? 0 : 2)) : String(v) }))),
        card(`${rep.title} · ${fmtNumber(rep.rows.length)} row${rep.rows.length === 1 ? '' : 's'}`, rep.rows.length ? simpleTable(cols, rep.rows) : empty('No data for these filters', 'Adjust the date range or filters.'), { flush: true }));
    });
  }
  run();
}
