import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api } from '../core/api.js';
import { lookup } from '../core/lookups.js';
import { fmtNumber, fmtPercent, titleCase } from '../core/format.js';
import { asyncView, card, empty, pageHead } from '../ui/feedback.js';
import { simpleTable } from '../ui/table.js';
import { barChart, donutChart } from '../ui/charts.js';
import { selectControl } from '../ui/form.js';

/* Executive summary: the one page a manager or client sponsor opens first.
   Coverage, who is active, how the journey plan is going, and what is on the shelf. Everything respects the person's access. */

const JP_COLORS = { complete: '#16a34a', in_progress: '#7dd3fc', not_completed: '#f59e0b', not_started: '#c8a96a', other: '#94a3b8' };
const pctText = (v) => (v == null ? 'No data' : fmtPercent(v));
const monthNow = () => new Date().toISOString().slice(0, 7);

export default async function render({ container }) {
  const [clients, campaigns] = await Promise.all([lookup('clients').catch(() => []), lookup('campaigns').catch(() => [])]);
  const f = { client_id: '', campaign_id: '', month: monthNow(), period: 'month', channel: '' };
  const host = h('div', { class: 'stack' });
  let reload = () => {};
  const change = (patch) => { Object.assign(f, patch); reload(); };

  const monthInput = h('input', { class: 'input', type: 'month', value: f.month, max: monthNow(), 'aria-label': 'Month', onchange: () => { if (monthInput.value) change({ month: monthInput.value }); } });
  const channelHost = h('span');
  const bar = h('div', { class: 'card' }, h('div', { class: 'toolbar' },
    selectControl({ label: 'Client', options: clients, blank: 'All clients', onChange: (v) => change({ client_id: v, campaign_id: '' }) }),
    selectControl({ label: 'Campaign', options: campaigns, blank: 'All campaigns', onChange: (v) => change({ campaign_id: v }) }),
    h('div', { class: 'field' }, monthInput),
    selectControl({ label: 'Period', options: [{ value: 'week', label: 'This week' }], blank: 'Whole month', onChange: (v) => change({ period: v || 'month' }) }),
    channelHost));

  container.append(pageHead({ title: 'Executive summary', subtitle: 'Coverage, activity and shelf performance across your projects.' }), bar, host);

  reload = asyncView(host, () => api.get('executive/summary', Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '' && v !== false))).then((r) => r.data), (d) => {
    channelHost.replaceChildren(selectControl({ label: 'Outlet channel', options: d.shelf.channels.map((c) => ({ value: c, label: titleCase(c) })), value: f.channel, blank: 'All channels', onChange: (v) => change({ channel: v }) }));
    const tg = d.targets || {};
    const tiles = d.coverage.map((c, i) => h('div', { class: 'cov-tile' },
      h('div', { class: 'small muted' }, `${c.label} coverage`), h('div', { class: ['big-number', i === 0 && 'primary'] }, c.pct == null ? '—' : `${c.pct}%`),
      h('div', { class: 'small muted' }, c.network ? `${fmtNumber(c.visited)}/${fmtNumber(c.network)} outlets visited` : 'No outlets linked yet'),
      i === 0 && tg.coverage_pct != null && goal(c.pct, tg.coverage_pct, `Target ${tg.coverage_pct}%`),
      i === 0 && tg.visits_per_outlet != null && goal(c.per_outlet, tg.visits_per_outlet, `${c.per_outlet ?? 0} visits per outlet · target ${tg.visits_per_outlet}`)));

    const teams = d.people.teams.length ? simpleTable([
      { label: 'Team', key: 'team' },
      { label: 'Active today', align: 'right', render: (t) => h('span', { class: t.pct >= 80 ? 'ok-text' : t.pct >= 50 ? 'warn-text' : 'bad-text' }, `${t.active}/${t.total}`, h('span', { class: 'small muted' }, ` (${t.pct ?? 0}%)`)) },
    ], d.people.teams) : h('p', { class: 'muted' }, 'No teams yet.');
    const people = h('div', { class: 'stack' },
      card('Team counts', teams),
      card(`Users active today`, h('div', null, h('div', { class: 'big-number' }, `${d.people.active_today}/${d.people.total}`), h('div', { class: 'small muted' }, d.people.pct == null ? 'No agents' : `${d.people.pct}% active. Active means clocked in or heard from the phone today.`))));

    const jp = d.journey_plan;
    const jpBlock = jp.total ? h('div', { class: 'grid split' },
      simpleTable([
        { label: 'Status', render: (r) => h('span', { style: { color: JP_COLORS[r.key] } }, h('strong', null, r.label)) },
        { label: 'Total', align: 'right', render: (r) => h('span', null, fmtNumber(r.total), h('span', { class: 'small muted' }, ` (${r.pct}%)`)) },
      ], jp.items, {}),
      donutChart({ items: jp.items.map((i) => ({ label: i.label, value: i.total, color: JP_COLORS[i.key] })), centerLabel: 'visits planned' }))
      : empty('No planned visits this month', 'Create assignments or journey plans to see progress here.');

    const sh = d.shelf;
    const osa = sh.osa_brands.length ? barChart({ labels: sh.osa_brands.map((b) => b.name), series: [{ name: 'Available on shelf (%)', values: sh.osa_brands.map((b) => b.pct ?? 0), color: '#3a5a96' }], format: (v) => `${Math.round(v)}%` })
      : empty('No on-shelf data yet', 'It appears once agents submit visits with shelf checks.');
    const sos = sh.sos_categories.length ? barChart({ labels: sh.sos_categories.map((c) => c.name), series: [{ name: 'Actual', values: sh.sos_categories.map((c) => c.actual), color: '#5b9bd5' }, { name: 'Expected', values: sh.sos_categories.map((c) => c.expected), color: '#d98b8b' }], format: (v) => `${Math.round(v)}%` })
      : empty('No share-of-shelf data yet', 'It needs facings per product and the total facings counted on the shelf in merchandising.');

    return h('div', { class: 'stack' },
      h('div', { class: 'grid split' }, card(`Outlet coverage`, h('div', { class: 'cov-row' }, tiles)), people),
      card('Journey plan summary', jpBlock),
      card(`On-shelf availability${sh.osa_pct == null ? '' : ` · ${fmtPercent(sh.osa_pct)}`}`, osa, { actions: tg.osa_pct != null ? goal(sh.osa_pct, tg.osa_pct, `Target ${tg.osa_pct}%`) : undefined }),
      card(`Share of shelf${sh.sos_pct == null ? '' : ` · ${fmtPercent(sh.sos_pct)}`}`, sos, { actions: tg.sos_pct != null ? goal(sh.sos_pct, tg.sos_pct, `Target ${tg.sos_pct}%`) : undefined }),
      h('p', { class: 'small muted' }, icon('info', { size: 14 }), ` Shelf figures use approved and submitted visits from ${d.period.from} to ${d.period.to}.`));
  });
}

/** "Target 90%" with a green or red tick depending on whether the result reaches it. */
function goal(value, target, text) {
  const ok = value != null && Number(value) >= Number(target);
  return h('div', { class: ['small', value == null ? 'muted' : ok ? 'ok-text' : 'bad-text'] }, value == null ? text : `${ok ? 'On target' : 'Below target'} · ${text}`);
}
