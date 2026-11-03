import { h, onDetach } from '../core/dom.js';
import { api } from '../core/api.js';
import { href } from '../core/router.js';
import { fmtDateTime, fmtNumber, fmtTime, relTime, today, titleCase } from '../core/format.js';
import { badge, empty, pageHead } from '../ui/feedback.js';
import { secureImage } from '../ui/gallery.js';
import { mapView, osmLink } from '../ui/map.js';
import { selectControl } from '../ui/form.js';

/* Field agents, live: who is where, who is working, and one person's whole day. Left: people. Middle: map. Right: the selected person. */

const STATUS = { in_app: { label: 'In app', tone: 'ok', map: 'valid' }, offline: { label: 'Offline', tone: 'warn', map: 'warning' }, not_started: { label: 'Not started', tone: 'plain', map: 'default' } };
const STATE = { in_progress: 'In progress', completed: 'Completed', planned: 'Planned' };
const ACTION = { checkin: 'Checked in', checkout: 'Checked out', submitted: 'Submitted a visit', started: 'Started a visit', created: 'Visit created', approved: 'Visit approved', rejected: 'Visit rejected', fake_gps_blocked: 'Fake GPS blocked', checkin_blocked_distance: 'Check-in blocked (too far)' };
const dur = (m) => (m == null ? '—' : `${Math.floor(m / 60)}:${String(m % 60).padStart(2, '0')}:00`);
const dash = () => h('span', { class: 'muted' }, '—');

export default async function render({ container }) {
  const q = { search: '', seen_after: '', status: '' };
  let selected = null; let date = today(); let rows = [];
  const listHost = h('div', { class: 'agent-list' });
  const mapHost = h('div');
  const detailHost = h('div', { class: 'agent-detail' });
  const summaryHost = h('div', { class: 'row wrap' });

  const search = h('input', { class: 'input', type: 'search', placeholder: 'Search name, phone, email', 'aria-label': 'Search agents' });
  let t; search.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => { q.search = search.value.trim(); loadList(); }, 300); });
  const seen = h('input', { class: 'input', type: 'date', max: today(), 'aria-label': 'Seen after', onchange: () => { q.seen_after = seen.value; loadList(); } });
  container.append(
    pageHead({ title: 'Field agents', subtitle: 'Live status, location and the day of each agent. Refreshes every minute.' }),
    h('div', { class: 'card' }, h('div', { class: 'toolbar' }, h('div', { class: 'field grow' }, search), h('label', { class: 'small muted' }, 'Seen after ', seen),
      selectControl({ label: 'Status', options: [{ value: 'in_app', label: 'In app' }, { value: 'offline', label: 'Offline' }, { value: 'not_started', label: 'Not started' }], blank: 'Any status', onChange: (v) => { q.status = v; loadList(); } }), summaryHost)),
    h('div', { class: 'agents-grid' }, h('div', { class: 'card agents-col' }, listHost), h('div', { class: 'card agents-col' }, mapHost), h('div', { class: 'card agents-col' }, detailHost)));

  async function loadList(keep = false) {
    try {
      const res = (await api.get('agents/live', q)).data;
      rows = res.data;
      const s = res.summary;
      summaryHost.replaceChildren(badge('in_app', { tone: 'ok', label: `${s.in_app} in app` }), badge('offline', { tone: 'warn', label: `${s.offline} offline` }), badge('not_started', { tone: 'plain', label: `${s.not_started} not started` }));
      paintList(); paintMap();
      if (!keep && rows.length && !selected) select(rows[0].id);
    } catch (e) { listHost.replaceChildren(h('p', { class: 'err' }, e.message || 'Could not load agents.')); }
  }

  function paintList() {
    listHost.replaceChildren(...(rows.length ? rows.map((r) => {
      const st = STATUS[r.status];
      return h('button', { class: ['agent-row', r.id === selected && 'on'], type: 'button', onclick: () => select(r.id), 'aria-pressed': r.id === selected ? 'true' : 'false' },
        h('div', { class: 'agent-top' }, h('strong', null, r.name), badge(r.status, { tone: st.tone, label: st.label })),
        h('div', { class: 'small muted' }, [r.role === 'team_leader' ? 'Team leader' : null, r.team_leader && `Team: ${r.team_leader}`, r.battery_pct != null && `Battery ${r.battery_pct}%`].filter(Boolean).join(' · ') || ' '),
        h('div', { class: 'small' }, r.last_action ? `${ACTION[r.last_action.type] || titleCase(r.last_action.type)} · ${r.last_action.location}` : 'No activity yet'),
        h('div', { class: 'small muted' }, r.last_seen_at ? `Last seen ${relTime(r.last_seen_at)}` : 'Not seen yet', ` · ${r.visits_today}/${r.assigned_today} visits today`, r.score != null && ` · Score ${Math.round(r.score)}`));
    }) : [empty('No agents match', 'Change the filters, or add field agents under Users.')]));
  }

  let map = null;
  function paintMap() {
    const markers = rows.filter((r) => r.position).map((r) => ({ lat: r.position.lat, lng: r.position.lng, label: `${r.name} · ${STATUS[r.status].label}`, tone: r.id === selected ? 'selected' : STATUS[r.status].map, onClick: () => select(r.id) }));
    if (!map) { map = mapView({ markers, height: 560 }); mapHost.replaceChildren(map); } else map.setMarkers(markers);
  }

  async function select(id) {
    selected = id; paintList(); paintMap();
    detailHost.replaceChildren(h('div', { class: 'spinner' }));
    try { paintDetail((await api.get(`agents/${id}/overview`, { date })).data); }
    catch (e) { detailHost.replaceChildren(h('p', { class: 'err' }, e.message || 'Could not load this agent.')); }
  }

  function paintDetail(d) {
    const dateInput = h('input', { class: 'input', type: 'date', value: date, max: today(), 'aria-label': 'Day', onchange: () => { if (dateInput.value) { date = dateInput.value; select(selected); } } });
    const st = STATUS[d.status] || STATUS.not_started;
    const kv = (k, v) => h('tr', null, h('th', { scope: 'row' }, k), h('td', null, v ?? dash()));
    const groups = ['in_progress', 'completed', 'planned'].map((k) => [k, d.timeline.filter((x) => x.state === k)]).filter(([, l]) => l.length);
    const sc = d.score;
    detailHost.replaceChildren(
      h('div', { class: 'agent-head' }, h('div', null, h('h2', null, d.name), h('div', { class: 'row' }, badge(d.status, { tone: st.tone, label: st.label }), d.agent_code && h('span', { class: 'small muted mono' }, d.agent_code))),
        d.selfie_photo_id ? h('div', { class: 'selfie' }, secureImage({ id: d.selfie_photo_id }, { alt: `Latest check-in photo of ${d.name}` })) : null),
      h('table', { class: 'kv' }, h('tbody', null,
        kv('Last action', d.last_action ? h('span', null, ACTION[d.last_action.type] || titleCase(d.last_action.type), ` · ${d.last_action.location} · `, relTime(d.last_action.at)) : null),
        kv('Last seen', d.last_seen_at ? fmtDateTime(d.last_seen_at) : null),
        kv('Team leader', d.team_leader), kv('Region', d.region), kv('Mobile no.', d.phone ? h('a', { href: `tel:${d.phone}` }, d.phone) : null),
        kv('Battery', d.battery_pct != null ? `${d.battery_pct}%` : null), kv('Device', d.device_model ? `${d.device_model}${d.os_version ? ` · ${d.os_version}` : ''}` : null),
        kv('Coordinates', d.position ? h('span', null, `${d.position.lat.toFixed(6)}, ${d.position.lng.toFixed(6)} `, h('a', { href: osmLink(d.position.lat, d.position.lng), target: '_blank', rel: 'noopener noreferrer' }, 'map'), h('span', { class: 'small muted' }, ` (${d.position.source}, ${relTime(d.position.at)})`)) : null),
        kv('Distance travelled', `${d.distance_km} km`),
        d.attendance && kv('Worked', `${d.attendance.worked_minutes != null ? `${Math.floor(d.attendance.worked_minutes / 60)}h ${d.attendance.worked_minutes % 60}m` : '—'} · ${d.attendance.outlets_visited ?? 0} outlets${d.attendance.work_started_at ? ` · from ${fmtTime(d.attendance.work_started_at)}` : ''}`))),
      sc && sc.score != null ? h('div', { class: 'score-box' }, h('div', { class: 'big-number' }, Math.round(sc.score)), h('div', null, h('strong', null, sc.grade), h('div', { class: 'small muted' }, `Last 30 days. Completion ${sc.components.completion ?? '—'}% · Attendance ${sc.components.attendance ?? '—'}% · Approval ${sc.components.approval ?? '—'}% · Proof photos ${sc.components.proof ?? '—'}%`))) : null,
      h('div', { class: 'row', style: { justifyContent: 'space-between', margin: '12px 0 4px' } }, h('h3', null, 'Day plan'), dateInput),
      groups.length ? groups.map(([k, list]) => h('div', { class: ['tl-group', k] }, h('div', { class: 'tl-title' }, `${STATE[k]} (${list.length})`), ...list.map((x) => h('div', { class: 'tl-item' },
        h('div', { class: 'tl-main' }, x.visit_id ? h('a', { href: href(`/visits/${x.visit_id}`) }, x.location) : h('span', null, x.location), h('div', { class: 'small muted' }, [x.town, x.campaign].filter(Boolean).join(' · '))),
        h('div', { class: 'tl-meta small' },
          badge(String(x.completion_pct), { tone: x.completion_pct >= 100 ? 'ok' : x.completion_pct > 0 ? 'warn' : 'plain', label: `${x.completion_pct}%` }),
          h('span', null, x.planned_start ? `${x.planned_start}${x.planned_end ? `-${x.planned_end}` : ''}` : 'Any time'),
          h('span', null, x.checkin_at ? `${fmtTime(x.checkin_at)}${x.checkout_at ? ` - ${fmtTime(x.checkout_at)}` : ''}` : ''),
          x.duration_min != null && h('span', { class: 'muted' }, dur(x.duration_min)),
          x.distance_m != null && h('span', { class: 'muted' }, `${fmtNumber(x.distance_m)} m from outlet`),
          x.gps_outcome && badge(x.gps_outcome))))))
        : empty('Nothing planned for this day', 'Assignments and journey-plan visits for the day show here.'));
  }

  loadList();
  const timer = setInterval(() => loadList(true), 60000);
  onDetach(container, () => clearInterval(timer));
}
