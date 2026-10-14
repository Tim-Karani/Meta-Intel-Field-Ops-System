import { h } from '../core/dom.js';
import { api } from '../core/api.js';
import { fmtMoney, fmtPercent, titleCase } from '../core/format.js';
import { asyncView, card, pageHead, empty } from '../ui/feedback.js';
import { lineChart, hBars, donutChart } from '../ui/charts.js';
import { selectControl } from '../ui/form.js';
import { lookup } from '../core/lookups.js';

const isoDaysAgo = (n) => { const d = new Date(); d.setDate(d.getDate() - n); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };

export default async function render({ container }) {
  const campaigns = await lookup('campaigns').catch(() => []);
  const filters = { date_from: isoDaysAgo(29), date_to: isoDaysAgo(0) };
  const grid = h('div', { class: 'stack' });
  const date = (key, label) => { const el = h('input', { class: 'input', type: 'date', 'aria-label': label, title: label, value: filters[key], onchange: () => { if (el.value) { filters[key] = el.value; paint(); } } }); return h('div', { class: 'field' }, el); };
  container.append(pageHead({ title: 'Analytics', subtitle: 'Trends and rankings computed from approved and submitted field data.' }),
    h('div', { class: 'card', style: { marginBottom: '16px' } }, h('div', { class: 'toolbar' }, date('date_from', 'From date'), date('date_to', 'To date'),
      selectControl({ label: 'Campaign', options: campaigns, blank: 'All campaigns', onChange: (v) => { v ? filters.campaign_id = v : delete filters.campaign_id; paint(); } }))), grid);

  const report = (type, extra = {}) => () => api.get(`reports/${type}`, { ...filters, ...extra }).then((r) => r.data);
  const panel = (title, loader, render, cls = '') => { const body = h('div'); asyncView(body, loader, (rep) => (rep.rows?.length ? render(rep) : empty('No data for this period', 'Try a wider date range.'))); return h('section', { class: ['card', cls] }, h('div', { class: 'card-head' }, h('h3', null, title)), h('div', { class: 'card-body' }, body)); };
  const top = (rows, key, n = 8) => [...rows].sort((a, b) => (b[key] || 0) - (a[key] || 0)).slice(0, n);

  function paint() {
    grid.replaceChildren(
      panel('Visits per day', report('visit_completion'), (rep) => lineChart({ labels: rep.rows.map((r) => r.date.slice(5)), series: [{ name: 'All visits', values: rep.rows.map((r) => r.total) }, { name: 'Approved', values: rep.rows.map((r) => r.approved) }] })),
      h('div', { class: 'grid cols-2' },
        panel('Sales value by campaign', report('sales', { group_by: 'campaign' }), (rep) => hBars({ items: top(rep.rows, 'value').map((r) => ({ label: r.dimension, value: r.value })), format: fmtMoney })),
        panel('Sales value by territory', report('sales', { group_by: 'territory' }), (rep) => hBars({ items: top(rep.rows, 'value').map((r) => ({ label: r.dimension, value: r.value })), format: fmtMoney }))),
      h('div', { class: 'grid cols-2' },
        panel('On-shelf availability by product (lowest first)', report('osa'), (rep) => hBars({ items: [...rep.rows].sort((a, b) => (a.availability || 0) - (b.availability || 0)).slice(0, 8).map((r) => ({ label: r.product, value: r.availability || 0 })), format: fmtPercent, max: 100 })),
        panel('Agent completion rate', report('agent_performance'), (rep) => hBars({ items: top(rep.rows, 'completion_rate').map((r) => ({ label: r.agent, value: r.completion_rate || 0 })), format: fmtPercent, max: 100 }))),
      h('div', { class: 'grid cols-2' },
        panel('Issues by status', report('issue_management'), (rep) => { const by = {}; rep.rows.forEach((r) => { by[r.status] = (by[r.status] || 0) + (r.total || 0); }); return donutChart({ items: Object.entries(by).map(([k, v]) => ({ label: titleCase(k), value: v })), centerLabel: 'issues' }); }),
        panel('Territory approval rate', report('territory_performance'), (rep) => hBars({ items: top(rep.rows, 'approval_rate').map((r) => ({ label: r.territory, value: r.approval_rate || 0 })), format: fmtPercent, max: 100 }))));
  }
  paint();
}
