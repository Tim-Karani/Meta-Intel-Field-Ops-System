import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api } from '../core/api.js';
import { can } from '../core/auth.js';
import { fmtDate, fmtTime } from '../core/format.js';
import { asyncView, badge, card, pageHead, toast, toastError } from '../ui/feedback.js';
import { dataTable } from '../ui/table.js';
import { lookup } from '../core/lookups.js';

/** Best-effort position for attendance. Attendance is not blocked when location is unavailable. */
const position = () => new Promise((resolve) => {
  if (!navigator.geolocation) return resolve({});
  navigator.geolocation.getCurrentPosition((p) => resolve({ latitude: p.coords.latitude, longitude: p.coords.longitude, accuracy_m: p.coords.accuracy }), () => resolve({}), { enableHighAccuracy: true, timeout: 8000, maximumAge: 30000 });
});

export default async function render({ container }) {
  let table;
  container.append(pageHead({ title: 'Attendance', subtitle: 'Daily clock-in and clock-out records.' }));
  if (can('attendance.create')) {
    const host = h('div', { style: { marginBottom: '16px' } });
    container.append(host);
    asyncView(host, () => api.get('attendance/today').then((r) => r.data), (row, reload) => {
      const out = !!row?.clock_out_at;
      return card('Your day', h('div', { class: 'row' },
        h('div', { style: { flex: 1 } }, !row ? h('span', null, 'You have not clocked in today.') : h('span', null, `Clocked in at ${fmtTime(row.clock_in_at)} `, badge(row.status), out && ` · out at ${fmtTime(row.clock_out_at)}`)),
        !row && h('button', { class: 'btn primary', type: 'button', onclick: async (e) => { e.currentTarget.disabled = true; try { await api.post('attendance/clock-in', { client_uuid: crypto.randomUUID(), ...(await position()) }); toast('Clocked in.'); reload(); table?.reload(); } catch (err) { toastError(err); e.currentTarget.disabled = false; } } }, icon('clock', { size: 16 }), 'Clock in'),
        row && !out && h('button', { class: 'btn', type: 'button', onclick: async (e) => { e.currentTarget.disabled = true; try { await api.post('attendance/clock-out', await position()); toast('Clocked out.'); reload(); table?.reload(); } catch (err) { toastError(err); e.currentTarget.disabled = false; } } }, icon('logout', { size: 16 }), 'Clock out')));
    });
  }
  const agents = can('users.view') || can('assignments.create') ? await lookup('agents').catch(() => []) : [];
  table = dataTable({
    endpoint: 'attendance', defaultSort: '-attendance_date', search: false,
    filters: [agents.length && { key: 'user_id', label: 'All agents', options: agents }, { key: 'status', label: 'All statuses', options: ['present', 'late', 'absent'] }, { key: 'date_from', label: 'From date', type: 'date' }, { key: 'date_to', label: 'To date', type: 'date' }].filter(Boolean),
    columns: [
      { key: 'attendance_date', label: 'Date', sort: 'attendance_date', render: (r) => fmtDate(r.attendance_date) },
      { key: 'user', label: 'Agent', render: (r) => r.user?.name || '—' },
      { key: 'clock_in_at', label: 'In', sort: 'clock_in_at', render: (r) => fmtTime(r.clock_in_at) },
      { key: 'clock_out_at', label: 'Out', render: (r) => (r.clock_out_at ? fmtTime(r.clock_out_at) : h('span', { class: 'muted' }, '—')) },
      { key: 'hours', label: 'Hours', align: 'right', render: (r) => (r.clock_in_at && r.clock_out_at ? ((new Date(r.clock_out_at) - new Date(r.clock_in_at)) / 3600000).toFixed(1) : '—') },
      { key: 'status', label: 'Status', sort: 'status', render: (r) => badge(r.status) },
    ],
    emptyTitle: 'No attendance records',
  });
  container.append(table);
}
