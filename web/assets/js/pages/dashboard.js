import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api } from '../core/api.js';
import { can, session, isClientUser } from '../core/auth.js';
import { fmtMoney, fmtNumber, fmtPercent, fmtDate, relTime, titleCase, today } from '../core/format.js';
import { asyncView, card, pageHead, stat, empty, badge } from '../ui/feedback.js';
import { lineChart, donutChart, hBars } from '../ui/charts.js';
import { mapView } from '../ui/map.js';
import { simpleTable } from '../ui/table.js';
import { secureImage } from '../ui/gallery.js';
import { href, navigate } from '../core/router.js';
import { lookup } from '../core/lookups.js';

/* Command Center: one client / campaign / period at a time, so different clients' work is never mixed. */

const FOCUS_KEY = 'dashboard.focus.v2';
const PRESETS = [{ value: '7', label: 'Last 7 days' }, { value: '14', label: 'Last 14 days' }, { value: '30', label: 'Last 30 days' }, { value: '90', label: 'Last 90 days' }, { value: 'month', label: 'This month' }, { value: 'custom', label: 'Custom range' }];
const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const daysAgo = (n) => { const d = new Date(); d.setDate(d.getDate() - n); return iso(d); };
const readFocus = () => { try { return JSON.parse(sessionStorage.getItem(FOCUS_KEY)); } catch { return null; } };
const saveFocus = (f) => { try { sessionStorage.setItem(FOCUS_KEY, JSON.stringify(f)); } catch { /* not remembered; fine */ } };

export default async function render({ container }) {
  if (isClientUser()) { navigate('/portal', { replace: true }); return; }
  const host = h('div'); container.append(host);

  // First visit: the first client alphabetically. "All clients" stays available as an explicit choice.
  const clients = await lookup('clients').catch(() => []);
  const stored = readFocus();
  const focus = { client_id: '', campaign_id: '', preset: '14', from: daysAgo(13), to: today(), ...(stored && typeof stored === 'object' ? stored : { client_id: clients[0]?.value || '' }) };
  if (focus.client_id && !clients.some((c) => c.value === focus.client_id)) { focus.client_id = clients[0]?.value || ''; focus.campaign_id = ''; }
  let campaigns = [];
  let reload;

  const period = () => {
    if (focus.preset === 'month') { const n = new Date(); return { date_from: iso(new Date(n.getFullYear(), n.getMonth(), 1)), date_to: today() }; }
    if (focus.preset === 'custom') return focus.from && focus.to ? { date_from: focus.from, date_to: focus.to } : {};
    return { date_from: daysAgo(Number(focus.preset) - 1), date_to: today() };
  };
  const params = () => ({ ...(focus.client_id ? { client_id: focus.client_id } : {}), ...(focus.campaign_id ? { campaign_id: focus.campaign_id } : {}), ...period() });
  const loader = async () => {
    campaigns = await lookup('campaigns', focus.client_id ? { client_id: focus.client_id } : {}).catch(() => []);
    if (focus.campaign_id && !campaigns.some((c) => c.value === focus.campaign_id)) focus.campaign_id = '';
    return api.get('dashboard', params()).then((r) => r.data);
  };
  const change = (patch) => { Object.assign(focus, patch); saveFocus(focus); reload(); };

  const select = (label, options, value, onChange, blank) => {
    const el = h('select', { class: 'select', 'aria-label': label, onchange: () => onChange(el.value) }, blank != null && h('option', { value: '' }, blank), options.map((o) => h('option', { value: o.value }, o.label)));
    el.value = value;
    return h('div', { class: 'field' }, el);
  };
  const date = (key, label) => { const el = h('input', { class: 'input', type: 'date', 'aria-label': label, title: label, value: focus[key], max: today(), onchange: () => { if (el.value) change({ [key]: el.value }); } }); return h('div', { class: 'field' }, el); };
  const bar = () => h('div', { class: 'card filter-bar' }, h('div', { class: 'toolbar' },
    h('span', { class: 'filter-label' }, icon('filter', { size: 15 }), 'Focus'),
    select('Client', clients, focus.client_id, (v) => change({ client_id: v, campaign_id: '' }), 'All clients (combined)'),
    select('Campaign', campaigns, focus.campaign_id, (v) => change({ campaign_id: v }), focus.client_id ? 'All campaigns of this client' : 'All campaigns'),
    select('Period', PRESETS, focus.preset, (v) => change({ preset: v }), null),
    focus.preset === 'custom' && date('from', 'From date'), focus.preset === 'custom' && date('to', 'To date'),
    h('span', { class: 'spacer' }),
    h('button', { class: 'btn sm ghost', type: 'button', onclick: () => change({ client_id: clients[0]?.value || '', campaign_id: '', preset: '14' }) }, 'Reset')));
  const focusLabel = () => {
    const c = clients.find((x) => x.value === focus.client_id)?.label;
    const k = campaigns.find((x) => x.value === focus.campaign_id)?.label;
    return k ? `${c ? `${c} · ` : ''}${k}` : c ? `${c}, all campaigns` : 'all clients combined';
  };
  reload = asyncView(host, loader, (d) => (d.kind === 'agent' ? agent(d) : operations(d, bar(), focusLabel())));
}

const STATUS_COLOR = { draft: '#94a3b8', planned: '#3a5a96', active: '#10b981', paused: '#f59e0b', completed: '#0ea5e9', archived: '#64748b' };
const PRIORITY_COLOR = { low: '#94a3b8', medium: '#f59e0b', high: '#ef4444', critical: '#991b1b' };
const TONE = { valid: 'ok', warning: 'warn', suspicious: 'danger', rejected: 'danger' };

function agent(d) {
  const t = d.today || {};
  return h('div', null,
    pageHead({ title: `Hello, ${session.user?.name?.split(' ')[0] || 'there'}`, subtitle: 'Your work today.', actions: can('assignments.respond') && h('a', { class: 'btn primary', href: href('/my-work') }, 'Open my work') }),
    h('div', { class: 'grid cols-4' },
      stat({ label: 'Assigned today', value: fmtNumber(t.total), icon: 'clipboard' }), stat({ label: 'Still to do', value: fmtNumber(t.todo), icon: 'list' }),
      stat({ label: 'In progress', value: fmtNumber(t.in_progress), icon: 'activity' }), stat({ label: 'Completed', value: fmtNumber(t.completed), icon: 'checkCircle' })),
    h('div', { class: 'grid cols-2', style: { marginTop: '16px' } },
      card('Waiting for review', h('div', null, h('div', { class: 'big-number' }, fmtNumber(d.pending_review)), h('p', { class: 'muted small' }, 'Submitted visits your supervisor has not reviewed yet.'))),
      card('Needs your attention', d.recently_rejected ? h('div', null, h('div', { class: 'big-number' }, fmtNumber(d.recently_rejected)), h('p', { class: 'muted small' }, 'Visits rejected in the last 7 days. Open them from My work to correct and resubmit.')) : h('p', { class: 'muted' }, 'No rejected visits. Nice work.'))));
}

/** "▲ 12.5%" chip; `invert` flips good/bad for measures where lower is better. */
function delta(k, days, { invert = false } = {}) {
  if (!k || k.delta == null) return h('span', { class: 'delta flat' }, 'no earlier data');
  const flat = k.delta === 0;
  const up = k.delta > 0;
  const good = flat ? null : invert ? !up : up;
  return h('span', { class: ['delta', flat ? 'flat' : good ? 'good' : 'bad'], title: `Compared with the previous ${days} days` },
    `${flat ? '■' : up ? '▲' : '▼'} ${fmtNumber(Math.abs(k.delta), 1)}${k.delta_unit === 'pts' ? ' pts' : '%'}`, h('span', { class: 'delta-note' }, ` vs previous ${days} days`));
}

const kpi = (label, value, ic, k, days, opts) => h('div', { class: 'card kpi' },
  h('div', { class: 'label' }, label, h('span', { class: 'icon-wrap' }, icon(ic, { size: 16 }))), h('div', { class: 'value' }, value), delta(k, days, opts));

function progressCell(v, { goodAt = 80 } = {}) {
  if (v == null) return h('span', { class: 'muted' }, '—');
  const tone = v >= goodAt ? 'ok' : v >= goodAt * 0.7 ? 'warn' : 'bad';
  return h('div', { class: 'cell-progress' }, h('div', { class: ['progress', tone] }, h('span', { style: { width: `${Math.max(2, Math.min(100, v))}%` } })), h('span', { class: 'small' }, fmtPercent(v, 0)));
}

function operations(d, bar, label) {
  const k = d.kpis || {};
  const days = d.period?.days || 14;
  const p = d.pulse;
  const att = d.attention || {};
  const status = Object.entries(d.campaigns_by_status || {}).map(([key, v]) => ({ label: titleCase(key), value: Number(v), color: STATUS_COLOR[key] }));
  const issues = ['critical', 'high', 'medium', 'low'].map((x) => ({ label: titleCase(x), value: Number((d.open_issues_by_priority || {})[x] || 0), color: PRIORITY_COLOR[x] })).filter((i) => i.value);
  const trend = d.visit_trend || [];
  const points = (d.map_points || []).filter((x) => x.lat != null).map((x) => ({ lat: Number(x.lat), lng: Number(x.lng), label: `${x.location} · ${x.agent}`, tone: TONE[x.outcome] || 'info' }));
  const cov = d.coverage;

  const pulse = p && card(`Today's pulse · ${fmtDate(today())}`, h('div', { class: 'pulse' },
    h('div', null, h('div', { class: 'pulse-top' }, h('strong', null, `${fmtNumber(p.completed)} of ${fmtNumber(p.assignments_total)}`), h('span', { class: 'muted small' }, ' assignments completed')),
      h('div', { class: 'progress tall' }, h('span', { style: { width: `${p.assignments_total ? Math.round(p.completed / p.assignments_total * 100) : 0}%` } }))),
    h('div', { class: 'pulse-grid' },
      [['In progress', p.in_progress], ['Still to do', p.to_do], ['Agents clocked in', p.agents_clocked_in], ['Late arrivals', p.late_arrivals], ['Visits submitted', p.visits_submitted], ['Issues opened', p.issues_opened]]
        .map(([l, v]) => h('div', { class: 'pulse-cell' }, h('div', { class: 'num' }, fmtNumber(v)), h('div', { class: 'small muted' }, l))))));

  const rows = [
    ['Visits waiting for review', att.pending_reviews, can('visits.review') && '/visits', 'clipboard'],
    ['Suspicious GPS check-ins', att.gps_flags, can('visits.view') && '/visits', 'shield'],
    ['Overdue assignments', att.overdue_assignments, can('assignments.view') && '/assignments', 'clock'],
    ['Critical or high issues open', att.urgent_issues, can('issues.view') && '/issues', 'alert'],
  ].filter(([, n]) => n > 0);
  const attention = card('Needs attention', rows.length
    ? h('ul', { class: 'attention' }, rows.map(([text, n, to, ic]) => h('li', null, h('a', { href: to ? href(to) : null }, h('span', { class: 'att-ic' }, icon(ic, { size: 16 })), h('span', { class: 'att-text' }, text), h('strong', { class: 'att-n' }, fmtNumber(n)), icon('chevronRight', { size: 14 })))))
    : h('p', { class: 'muted' }, 'All clear. Nothing is waiting on you in this focus.'));

  const health = card('Campaign execution health', simpleTable([
    { label: 'Campaign', render: (r) => h('div', null, h('a', { href: can('campaigns.view') ? href(`/campaigns/${r.id}`) : null }, r.name), h('div', { class: 'small muted' }, r.client || '')) },
    { label: 'Status', render: (r) => badge(r.status) },
    { label: 'Planned', align: 'right', render: (r) => fmtNumber(r.planned) },
    { label: 'Completion', render: (r) => progressCell(r.completion_rate) },
    { label: 'Visits', align: 'right', render: (r) => fmtNumber(r.visits) },
    { label: 'Approval', render: (r) => progressCell(r.approval_rate, { goodAt: 85 }) },
    { label: 'OSA', render: (r) => progressCell(r.osa, { goodAt: 85 }) },
    { label: 'Target progress', render: (r) => (r.target ? h('div', null, progressCell(r.target_progress, { goodAt: 70 }), h('div', { class: 'small muted' }, `${fmtNumber(r.lifetime_visits)} of ${fmtNumber(r.target)} visits`)) : h('span', { class: 'muted small' }, 'No target set')) },
    { label: 'Sales', align: 'right', render: (r) => fmtMoney(r.sales) },
  ], d.campaign_health || [], { emptyText: 'No live campaigns in this focus.' }), { flush: true });

  const coverage = card('Store coverage', cov && cov.total_stores ? h('div', null,
    h('div', { class: 'cov-head' }, h('div', { class: 'big-number' }, fmtPercent(cov.rate, 0)), h('div', { class: 'muted small' }, `${fmtNumber(cov.visited)} of ${fmtNumber(cov.total_stores)} stores visited in this period`)),
    h('div', { class: 'progress tall' }, h('span', { style: { width: `${cov.rate || 0}%` } })),
    cov.missed.length ? h('div', null, h('h4', { class: 'cov-sub' }, `Not visited (${fmtNumber(cov.not_visited)})`),
      h('ul', { class: 'activity-list' }, cov.missed.map((m) => h('li', null, h('div', null, h('a', { href: can('locations.view') ? href(`/locations/${m.id}`) : null }, m.name), m.town ? ` · ${m.town}` : ''),
        h('div', { class: 'small muted' }, m.last_visit ? `Last visited ${fmtDate(m.last_visit)}` : 'Never visited'))))) : h('p', { class: 'muted small', style: { marginTop: '10px' } }, 'Every store in the network was visited.'))
    : empty('No store network yet', 'Link locations to a campaign to track coverage.'));

  const photos = card('Latest photo proof', (d.photos || []).length ? h('div', { class: 'photo-strip' }, d.photos.map((x) => h('a', { class: 'photo-chip', href: href(`/visits/${x.visit_id}`), 'aria-label': `Open visit at ${x.location}` },
    secureImage(x, { alt: `Photo at ${x.location}` }), h('span', { class: 'cap' }, x.location)))) : h('p', { class: 'muted' }, 'No photos in this period.'));

  return h('div', { class: 'stack' },
    pageHead({ title: 'Command Center', subtitle: `${fmtDate(d.period?.from)} to ${fmtDate(d.period?.to)} · Showing ${label}.` }),
    bar,
    h('div', { class: 'grid split' }, pulse || h('div'), attention),
    h('div', { class: 'grid cols-3' },
      kpi('Visits executed', fmtNumber(k.visits_executed?.value), 'pin', k.visits_executed, days),
      kpi('Assignment completion', k.completion_rate?.value == null ? 'No data' : fmtPercent(k.completion_rate.value), 'clipboard', k.completion_rate, days),
      kpi('Approval rate', k.approval_rate?.value == null ? 'No data' : fmtPercent(k.approval_rate.value), 'checkCircle', k.approval_rate, days),
      kpi('Active agents', fmtNumber(k.active_agents?.value), 'users', k.active_agents, days),
      kpi('Sales value', fmtMoney(k.sales_value?.value), 'dollar', k.sales_value, days),
      kpi('On-shelf availability', k.osa_availability?.value == null ? 'No data' : fmtPercent(k.osa_availability.value), 'box', k.osa_availability, days)),
    h('div', { class: 'grid split' },
      card(`Planned vs completed visits · ${days} days`, trend.some((t) => t.planned || t.completed)
        ? lineChart({ labels: trend.map((t) => t.date.slice(5)), series: [{ name: 'Planned', values: trend.map((t) => t.planned) }, { name: 'Completed', values: trend.map((t) => t.completed) }] })
        : empty('No visits in this period', 'Charts fill in as assignments are created and visits submitted.')),
      coverage),
    health,
    h('div', { class: 'grid cols-3' },
      card('Top agents', simpleTable([{ label: 'Agent', key: 'agent' }, { label: 'Visits', align: 'right', render: (r) => fmtNumber(r.visits) }, { label: 'Approved', align: 'right', render: (r) => fmtNumber(r.approved) }, { label: 'Rejected', align: 'right', render: (r) => fmtNumber(r.rejected) }], d.top_agents || [], { emptyText: 'No agent activity.' }), { flush: true }),
      card('Open issues by priority', issues.length ? hBars({ items: issues }) : h('p', { class: 'muted' }, 'No open issues.')),
      card('Campaigns by status', status.length ? donutChart({ items: status, centerLabel: 'campaigns' }) : empty('No campaigns yet'))),
    h('div', { class: 'grid split' }, photos,
      card('Recent activity', (d.recent_activity || []).length ? h('ul', { class: 'activity-list' }, d.recent_activity.map((e) => h('li', null,
        h('div', null, h('a', { href: can('visits.view') ? href(`/visits/${e.visit_id}`) : null }, titleCase(e.type)), ` · ${e.agent} at ${e.location}`),
        h('div', { class: 'small muted' }, relTime(e.created_at), e.note ? ` — ${e.note}` : '')))) : h('p', { class: 'muted' }, 'No activity yet.'))),
    card('Geographic activity · check-ins in this period', points.length ? mapView({ markers: points, height: 360 }) : empty('No check-ins yet', 'Check-in locations for the selected period appear here.')));
}
