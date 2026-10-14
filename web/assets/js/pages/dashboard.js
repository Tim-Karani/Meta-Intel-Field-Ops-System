import { h } from '../core/dom.js';
import { api } from '../core/api.js';
import { can, session, isClientUser } from '../core/auth.js';
import { fmtMoney, fmtNumber, fmtPercent, fmtDate, relTime, titleCase } from '../core/format.js';
import { asyncView, card, pageHead, stat, empty } from '../ui/feedback.js';
import { lineChart, donutChart, hBars } from '../ui/charts.js';
import { mapView } from '../ui/map.js';
import { href, navigate } from '../core/router.js';

export default async function render({ container }) {
  if (isClientUser()) { navigate('/portal', { replace: true }); return; }
  const host = h('div'); container.append(host);
  asyncView(host, () => api.get('dashboard').then((r) => r.data), (d) => (d.kind === 'agent' ? agent(d) : operations(d)));
}

const STATUS_COLOR = { draft: '#94a3b8', planned: '#3a5a96', active: '#10b981', paused: '#f59e0b', completed: '#1ba4a8', archived: '#64748b' };
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

function operations(d) {
  const c = d.cards || {};
  const status = Object.entries(d.campaigns_by_status || {}).map(([k, v]) => ({ label: titleCase(k), value: Number(v), color: STATUS_COLOR[k] }));
  const issues = ['critical', 'high', 'medium', 'low'].map((p) => ({ label: titleCase(p), value: Number((d.open_issues_by_priority || {})[p] || 0), color: PRIORITY_COLOR[p] })).filter((i) => i.value);
  const trend = d.visit_trend || [];
  const points = (d.map_points || []).filter((p) => p.lat != null).map((p) => ({ lat: Number(p.lat), lng: Number(p.lng), label: `${p.location} · ${p.agent}`, tone: TONE[p.outcome] || 'info' }));
  const cardLink = (label, value, ic, to, hint) => h('a', { class: 'stat-link', href: to ? href(to) : null }, stat({ label, value, icon: ic, hint }));
  return h('div', { class: 'stack' },
    pageHead({ title: 'Dashboard', subtitle: `Overview for ${fmtDate(new Date().toISOString().slice(0, 10))}. Figures reflect your access scope.` }),
    h('div', { class: 'grid cols-4' },
      cardLink('Active campaigns', fmtNumber(c.active_campaigns), 'flag', can('campaigns.view') && '/campaigns'),
      cardLink('Visits today', fmtNumber(c.visits_today), 'pin', can('visits.view') && '/visits?tab=all'),
      cardLink('Pending reviews', fmtNumber(c.pending_reviews), 'clipboard', can('visits.review') && '/visits'),
      cardLink('Open issues', fmtNumber(c.open_issues), 'alert', can('issues.view') && '/issues'),
      cardLink('Sales (30 days)', fmtMoney(c.sales_value_30d), 'dollar', can('sales.view') && '/sales'),
      cardLink('OSA (30 days)', c.osa_availability_30d == null ? 'No data' : fmtPercent(c.osa_availability_30d), 'box', can('osa.view') && '/osa', c.osa_availability_30d == null ? 'No OSA checks recorded' : undefined),
      cardLink('GPS flags (7 days)', fmtNumber(c.gps_flags_7d), 'shield', can('visits.view') && '/visits?tab=all', 'Visits marked suspicious')),
    h('div', { class: 'grid split' },
      card('Planned vs completed visits · last 14 days', trend.some((t) => t.planned || t.completed)
        ? lineChart({ labels: trend.map((t) => t.date.slice(5)), series: [{ name: 'Planned', values: trend.map((t) => t.planned) }, { name: 'Completed', values: trend.map((t) => t.completed) }] })
        : empty('No visits in this period', 'Charts fill in as assignments are created and visits submitted.')),
      card('Campaigns by status', status.length ? donutChart({ items: status, centerLabel: 'campaigns' }) : empty('No campaigns yet'))),
    h('div', { class: 'grid cols-2' },
      card('Open issues by priority', issues.length ? hBars({ items: issues }) : h('p', { class: 'muted' }, 'No open issues.')),
      card('Recent activity', (d.recent_activity || []).length ? h('ul', { class: 'activity-list' }, d.recent_activity.map((e) => h('li', null,
        h('div', null, h('a', { href: can('visits.view') ? href(`/visits/${e.visit_id}`) : null }, `${titleCase(e.type)}`), ` · ${e.agent} at ${e.location}`),
        h('div', { class: 'small muted' }, relTime(e.created_at), e.note ? ` — ${e.note}` : '')))) : h('p', { class: 'muted' }, 'No activity yet.'))),
    card('Geographic activity · check-ins in the last 7 days', points.length ? mapView({ markers: points, height: 360 }) : empty('No check-ins yet', 'Check-in locations from the last 7 days appear here.')));
}
