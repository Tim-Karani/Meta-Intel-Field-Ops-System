import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api } from '../core/api.js';
import { session } from '../core/auth.js';
import { fmtDate, fmtMoney, fmtNumber, fmtPercent, titleCase } from '../core/format.js';
import { asyncView, badge, card, kv, pageHead, stat, empty, actionButton, toastError } from '../ui/feedback.js';
import { dataTable, simpleTable } from '../ui/table.js';
import { lineChart } from '../ui/charts.js';
import { photoGrid } from '../ui/gallery.js';
import { href, navigate } from '../core/router.js';
import { selectControl } from '../ui/form.js';

const PORTAL_REPORTS = [['campaign_performance', 'Campaign performance'], ['sales', 'Sales'], ['osa', 'On-shelf availability'], ['merchandising', 'Merchandising'], ['location_performance', 'Location performance'], ['visit_completion', 'Visit completion'], ['activity_performance', 'Activity performance']];

export default async function render(ctx) {
  const { container, route, params } = ctx;
  if (route.mode === 'visit') return visitPage(container, params.id);
  if (route.mode === 'report') return reportPage(container, params.type, ctx.query);
  return home(container);
}

function home(container) {
  const client = session.user?.client?.name;
  container.append(pageHead({ title: client ? `${client} — campaign results` : 'Campaign results', subtitle: 'Approved field results only. Updated as visits are reviewed and approved.' }));
  const host = h('div'); container.append(host);
  asyncView(host, async () => {
    const [summary, campaigns] = await Promise.all([api.get('portal/summary'), api.get('portal/campaigns')]);
    return { summary: summary.data, campaigns: campaigns.data };
  }, ({ summary, campaigns }) => {
    const c = summary.cards || {};
    const trend = (summary.visit_trend || []);
    return h('div', { class: 'stack' },
      h('div', { class: 'grid cols-4' },
        stat({ label: 'Active campaigns', value: fmtNumber(c.active_campaigns), icon: 'flag' }), stat({ label: 'Sales (30 days)', value: fmtMoney(c.sales_value_30d), icon: 'dollar' }),
        stat({ label: 'OSA (30 days)', value: c.osa_availability_30d == null ? 'No data' : fmtPercent(c.osa_availability_30d), icon: 'box' }), stat({ label: 'Approved visits today', value: fmtNumber(c.visits_today), icon: 'checkCircle' })),
      h('div', { class: 'grid split' },
        card('Approved visits · last 14 days', trend.some((t) => t.completed) ? lineChart({ labels: trend.map((t) => t.date.slice(5)), series: [{ name: 'Approved visits', values: trend.map((t) => t.completed) }] }) : empty('No approved visits yet')),
        card('Reports', h('div', { class: 'stack sm' }, PORTAL_REPORTS.map(([t, l]) => h('a', { class: 'btn', href: href(`/portal/reports/${t}`), style: { justifyContent: 'flex-start' } }, icon('bars', { size: 16 }), l))))),
      card('Campaigns', simpleTable([
        { label: 'Campaign', render: (r) => h('div', null, h('strong', null, r.name), h('div', { class: 'small muted mono' }, r.code)) }, { label: 'Type', render: (r) => (r.campaign_type ? titleCase(r.campaign_type) : '—') },
        { label: 'Dates', render: (r) => `${fmtDate(r.start_date)} → ${fmtDate(r.end_date)}` }, { label: 'Approved visits', align: 'right', render: (r) => fmtNumber(r.approved_visits) }, { label: 'Status', render: (r) => badge(r.status) }], campaigns, { emptyText: 'No campaigns are available yet.' }), { flush: true }),
      card('Approved visits', dataTable({ endpoint: 'portal/visits', defaultSort: null, search: false, id: 'portal-visits',
        columns: [{ key: 'visit_date', label: 'Date', render: (r) => fmtDate(r.visit_date) }, { key: 'location', label: 'Location', render: (r) => h('a', { href: href(`/portal/visits/${r.id}`) }, h('strong', null, r.location?.name || '—')) },
          { key: 'town', label: 'Town', render: (r) => [r.location?.town, r.location?.county].filter(Boolean).join(', ') || '—' }, { key: 'campaign', label: 'Campaign', render: (r) => r.campaign?.name || '—' }, { key: 'activity', label: 'Activity', render: (r) => r.activity?.name || '—' }],
        onRowClick: (r) => navigate(`/portal/visits/${r.id}`), emptyTitle: 'No approved visits yet' }), { flush: true }));
  });
}

function visitPage(container, id) {
  const host = h('div'); container.append(host);
  asyncView(host, () => api.get(`portal/visits/${id}`).then((r) => r.data), (v) => {
    const yn = (b) => (b === null || b === undefined ? '—' : b ? 'Yes' : 'No');
    return h('div', { class: 'stack' },
      pageHead({ title: v.location?.name || 'Visit', subtitle: `${fmtDate(v.visit_date)} · ${v.campaign?.name || ''}${v.activity ? ` · ${v.activity.name}` : ''}`, actions: h('a', { class: 'btn', href: href('/portal') }, 'Back to results') }),
      h('div', { class: 'grid cols-2' }, card('Location', kv([['Name', v.location?.name], ['Address', v.location?.address], ['Town', v.location?.town], ['County', v.location?.county]])),
        card('Answers', (v.answers || []).length ? kv(v.answers.map((a) => [a.question, typeof a.value === 'object' ? JSON.stringify(a.value) : String(a.value?.value ?? a.value ?? '—')])) : h('span', { class: 'muted' }, 'No form answers.'))),
      (v.sales || []).length > 0 && card('Sales', simpleTable([{ label: 'Product', render: (i) => i.product }, { label: 'Qty', align: 'right', render: (i) => fmtNumber(i.quantity) }, { label: 'Unit price', align: 'right', render: (i) => fmtMoney(i.unit_price) }, { label: 'Total', align: 'right', render: (i) => fmtMoney(i.line_total) }], v.sales.flatMap((s) => s.items)), { flush: true }),
      (v.osa || []).length > 0 && card('On-shelf availability', simpleTable([{ label: 'Product', key: 'product' }, { label: 'Available', render: (r) => yn(r.available) }, { label: 'Out of stock', render: (r) => yn(r.out_of_stock) }, { label: 'Facings', align: 'right', render: (r) => fmtNumber(r.facings) }, { label: 'Shelf price', align: 'right', render: (r) => fmtMoney(r.price_on_shelf) }], v.osa), { flush: true }),
      (v.merchandising || []).length > 0 && card('Merchandising', simpleTable([{ label: 'POSM', render: (r) => yn(r.posm_present) }, { label: 'POSM type', key: 'posm_type' }, { label: 'Planogram', render: (r) => yn(r.planogram_compliant) }, { label: 'Price', render: (r) => yn(r.price_compliant) }, { label: 'Competitor', render: (r) => yn(r.competitor_present) }], v.merchandising), { flush: true }),
      card(`Photos (${(v.photos || []).length})`, photoGrid((v.photos || []).map((p) => ({ ...p, review_status: 'accepted' })))));
  });
}

async function reportPage(container, type, query) {
  const filters = {}; ['date_from', 'date_to', 'campaign_id'].forEach((k) => { if (query?.[k]) filters[k] = query[k]; });
  const campaigns = (await api.get('portal/campaigns').catch(() => ({ data: [] }))).data.map((c) => ({ value: c.id, label: c.name }));
  const result = h('div');
  const date = (key, label) => { const el = h('input', { class: 'input', type: 'date', 'aria-label': label, title: label, value: filters[key] || '', onchange: () => { el.value ? filters[key] = el.value : delete filters[key]; run(); } }); return h('div', { class: 'field' }, el); };
  const label = PORTAL_REPORTS.find(([t]) => t === type)?.[1] || titleCase(type);
  container.append(pageHead({ title: label, subtitle: 'Approved visits only.', actions: [h('a', { class: 'btn', href: href('/portal') }, 'Back to results'), actionButton('Export CSV', async () => { try { await api.download(`portal/reports/${type}/export`, filters, `${type}.csv`); } catch (e) { toastError(e); } }, { iconName: 'download' })] }),
    h('div', { class: 'card', style: { marginBottom: '16px' } }, h('div', { class: 'toolbar' }, date('date_from', 'From date'), date('date_to', 'To date'), selectControl({ label: 'Campaign', options: campaigns, blank: 'All campaigns', value: filters.campaign_id || '', onChange: (v) => { v ? filters.campaign_id = v : delete filters.campaign_id; run(); } }))), result);
  const fmt = { int: (v) => fmtNumber(v), decimal: (v) => fmtNumber(v, 2), money: fmtMoney, percent: (v) => fmtPercent(v), date: fmtDate, status: (v) => badge(v), text: (v) => v };
  function run() {
    asyncView(result, () => api.get(`portal/reports/${type}`, filters).then((r) => r.data), (rep) => card(`${fmtNumber(rep.rows.length)} rows`, rep.rows.length
      ? simpleTable(rep.columns.map((c) => ({ label: c.label, align: ['int', 'decimal', 'money', 'percent'].includes(c.type) ? 'right' : undefined, render: (r) => (r[c.key] == null || r[c.key] === '' ? '—' : (fmt[c.type] || fmt.text)(r[c.key])) })), rep.rows)
      : empty('No data for these filters'), { flush: true }));
  }
  run();
}
