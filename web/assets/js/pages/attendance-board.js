import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api } from '../core/api.js';
import { fmtTime, fmtNumber, today } from '../core/format.js';
import { asyncView, badge, card, pageHead, stat, openModal, empty } from '../ui/feedback.js';
import { simpleTable } from '../ui/table.js';
import { secureImage } from '../ui/gallery.js';

/* Team attendance: who is working, when their work REALLY started (first outlet check-in, not clock-in),
   and every outlet check-in with its distance, GPS result and proof photos. Each leader sees their own scope. */
const STATE = {
  at_outlet: { label: 'At an outlet', tone: 'info' },
  worked: { label: 'Worked today', tone: 'ok' },
  clocked_in_not_working: { label: 'Clocked in, not at an outlet', tone: 'warn' },
  not_started: { label: 'Not started', tone: 'plain' },
};
const hm = (min) => (min == null ? '—' : `${Math.floor(min / 60)}h ${String(min % 60).padStart(2, '0')}m`);
const dash = () => h('span', { class: 'muted' }, '—');

function viewPhoto(title, id) {
  openModal({ title, size: 'lg', body: h('div', { class: 'photo-full' }, secureImage({ id }, { cls: 'full', alt: title })) });
}

export default async function render({ container }) {
  let date = today();
  const host = h('div', { class: 'stack' });
  const dateInput = h('input', { class: 'input', type: 'date', value: date, max: today(), 'aria-label': 'Date', onchange: () => { if (dateInput.value) { date = dateInput.value; load(); } } });
  container.append(
    pageHead({ title: 'Team attendance', subtitle: 'Working time starts at the first outlet check-in. Clocking in on its own does not count as time worked.', actions: [h('div', { class: 'field', style: { margin: 0 } }, dateInput)] }),
    host,
  );

  function load() {
    asyncView(host, () => api.get('attendance/board', { date }).then((r) => r.data), (d) => {
      const s = d.summary;
      const people = simpleTable([
        { label: 'Name', render: (r) => h('div', null, h('strong', null, r.name), r.agent_code && h('div', { class: 'small muted' }, r.agent_code)) },
        { label: 'Region', render: (r) => r.region || dash() },
        { label: 'Team leader', render: (r) => r.team_leader || dash() },
        { label: 'Status', render: (r) => { const st = STATE[r.state] || STATE.not_started; return badge(r.state, { tone: st.tone, label: st.label }); } },
        { label: 'Clocked in', render: (r) => (r.clock_in_at ? fmtTime(r.clock_in_at) : dash()) },
        { label: 'Work started', render: (r) => (r.work_started_at ? h('strong', null, fmtTime(r.work_started_at)) : dash()) },
        { label: 'Last check-out', render: (r) => (r.work_ended_at ? fmtTime(r.work_ended_at) : dash()) },
        { label: 'Time worked', align: 'right', render: (r) => hm(r.worked_minutes) },
        { label: 'Outlets', align: 'right', render: (r) => fmtNumber(r.outlets) },
        { label: 'Now at', render: (r) => r.current_outlet || dash() },
      ], d.people, { emptyText: 'No team members in your scope.' });

      const checkins = simpleTable([
        { label: 'In', render: (r) => fmtTime(r.started_at) },
        { label: 'Out', render: (r) => (r.completed_at ? fmtTime(r.completed_at) : dash()) },
        { label: 'Agent', render: (r) => (d.people.find((p) => p.user_id === r.agent_id)?.name || '—') },
        { label: 'Outlet', render: (r) => h('div', null, r.outlet, h('div', { class: 'small muted' }, r.campaign)) },
        { label: 'Distance', align: 'right', render: (r) => (r.distance_m == null ? dash() : `${Math.round(r.distance_m)} m`) },
        { label: 'GPS', render: (r) => badge(r.gps_outcome) },
        { label: 'Check-in photo', render: (r) => (r.checkin_photo_id ? h('button', { class: 'btn sm', type: 'button', onclick: () => viewPhoto('Check-in photo', r.checkin_photo_id) }, icon('image', { size: 14 }), 'View') : badge('missing', { tone: 'danger', label: 'Missing' })) },
        { label: 'Check-out photo', render: (r) => (r.checkout_photo_id ? h('button', { class: 'btn sm', type: 'button', onclick: () => viewPhoto('Check-out photo', r.checkout_photo_id) }, icon('image', { size: 14 }), 'View') : (r.completed_at ? badge('missing', { tone: 'danger', label: 'Missing' }) : dash())) },
      ], d.checkins, { emptyText: 'No outlet check-ins on this day.' });

      return h('div', { class: 'stack' },
        h('div', { class: 'grid cols-4' },
          stat({ label: 'People', value: fmtNumber(s.people), icon: 'users' }),
          stat({ label: 'At an outlet now', value: fmtNumber(s.at_outlet), icon: 'pin' }),
          stat({ label: 'Worked', value: fmtNumber(s.worked + s.at_outlet), icon: 'checkCircle' }),
          stat({ label: 'Clocked in, no outlet yet', value: fmtNumber(s.clocked_in_not_working), icon: 'clock', hint: `${fmtNumber(s.not_started)} not started` })),
        card('People', people, { flush: true }),
        card(`Outlet check-ins · ${fmtNumber(d.checkins.length)}`, d.checkins.length ? checkins : empty('No check-ins', 'No agent has checked in at an outlet on this day.'), { flush: true }));
    });
  }
  load();
}
