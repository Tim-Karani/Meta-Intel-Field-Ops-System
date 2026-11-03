import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api } from '../core/api.js';
import { can } from '../core/auth.js';
import { fmtDateTime, titleCase } from '../core/format.js';
import { asyncView, badge, card, empty, pageHead, toast, toastError, confirmDialog } from '../ui/feedback.js';
import { simpleTable } from '../ui/table.js';
import { formModal } from '../ui/crud.js';
import { lookup } from '../core/lookups.js';
import { href } from '../core/router.js';

const WEEKDAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'].map((d, i) => ({ value: String(i + 1), label: d }));

/* Reports that email themselves. The report is built with the access of the person who set it up. */
export default async function render({ container }) {
  const opts = (await api.get('report-schedules/options')).data;
  const [campaigns, clients] = await Promise.all([lookup('campaigns').catch(() => []), lookup('clients').catch(() => [])]);
  const host = h('div');
  let reload;

  const describe = (s) => {
    const when = s.frequency === 'weekly' ? `Every ${WEEKDAYS[(s.weekday || 1) - 1]?.label}` : s.frequency === 'monthly' ? `On day ${s.month_day} of each month` : 'Every day';
    return `${when} at ${s.send_time} · covers ${titleCase(s.period).toLowerCase()}`;
  };

  const fields = [
    { name: 'name', label: 'Name', required: true, maxlength: 120, full: true, help: 'For example: Nairobi daily check-ins.' },
    { name: 'report_type', label: 'Report', type: 'select', required: true, options: opts.types, blank: '— Choose a report —' },
    { name: 'period', label: 'Covers', type: 'select', blank: false, options: opts.periods },
    { name: 'frequency', label: 'Send', type: 'select', blank: false, options: opts.frequencies },
    { name: 'weekday', label: 'On (weekly)', type: 'select', options: WEEKDAYS, blank: '—' },
    { name: 'month_day', label: 'Day of month (monthly, 1-28)', type: 'number', min: 1, max: 28 },
    { name: 'send_time', label: 'Time', type: 'time', required: true, value: '07:00', help: 'Server time (Africa/Nairobi unless changed).' },
    { name: 'campaign_id', label: 'Only this campaign', type: 'select', options: campaigns, blank: 'All campaigns' },
    { name: 'client_id', label: 'Only this client', type: 'select', options: clients, blank: 'All clients' },
    { name: 'recipients', label: 'Send to (email addresses, one per line or separated by commas)', type: 'textarea', rows: 3, required: true, full: true },
    { name: 'is_active', label: 'Active', type: 'checkbox', value: true, full: true },
  ];

  const toBody = (v) => {
    const filters = {};
    if (v.campaign_id) filters.campaign_id = v.campaign_id;
    if (v.client_id) filters.client_id = v.client_id;
    return {
      name: v.name, report_type: v.report_type, period: v.period, frequency: v.frequency, send_time: v.send_time, is_active: !!v.is_active,
      weekday: v.frequency === 'weekly' && v.weekday ? Number(v.weekday) : null, month_day: v.frequency === 'monthly' && v.month_day ? Number(v.month_day) : null,
      recipients: String(v.recipients || '').split(/[\s,;]+/).map((x) => x.trim()).filter(Boolean), filters,
    };
  };

  const open = (rec) => formModal({
    title: rec ? 'Edit scheduled report' : 'New scheduled report', size: 'lg', submitLabel: rec ? 'Save changes' : 'Create schedule', fields,
    values: rec ? { ...rec, weekday: rec.weekday ? String(rec.weekday) : '', campaign_id: rec.filters?.campaign_id || '', client_id: rec.filters?.client_id || '', recipients: (rec.recipients || []).join('\n') } : {},
    onSubmit: async (v) => { const body = toBody(v); if (rec) await api.patch(`report-schedules/${rec.id}`, body); else await api.post('report-schedules', body); toast('Schedule saved.'); },
    onSaved: () => reload(),
  });

  const sendNow = async (s, btn) => {
    btn.disabled = true;
    try { const r = await api.post(`report-schedules/${s.id}/send`); toast(r.message || 'Sent.'); reload(); } catch (e) { toastError(e); } finally { btn.disabled = false; }
  };
  const remove = async (s) => {
    if (!(await confirmDialog({ title: 'Delete this schedule?', message: `“${s.name}” will stop being sent.`, confirmLabel: 'Delete', danger: true }))) return;
    try { await api.delete(`report-schedules/${s.id}`); toast('Schedule deleted.'); reload(); } catch (e) { toastError(e); }
  };

  container.append(pageHead({ title: 'Scheduled reports', subtitle: 'Reports emailed automatically to whoever needs them, as a CSV that opens in Excel. For a one-off copy, open the report and use Export.',
    actions: [h('a', { class: 'btn', href: href('/reports') }, 'All reports'), h('button', { class: 'btn primary', type: 'button', onclick: () => open(null) }, icon('plus', { size: 16 }), 'New schedule')] }), host);

  reload = asyncView(host, () => api.get('report-schedules').then((r) => r.data), (rows) => card(null, rows.length ? simpleTable([
    { label: 'Name', render: (s) => h('div', null, h('strong', null, s.name), h('div', { class: 'small muted' }, opts.types.find((t) => t.value === s.report_type)?.label || s.report_type)) },
    { label: 'When', render: (s) => describe(s) },
    { label: 'Recipients', render: (s) => h('div', null, `${s.recipients.length} address${s.recipients.length === 1 ? '' : 'es'}`, h('div', { class: 'small muted' }, s.recipients.slice(0, 2).join(', ') + (s.recipients.length > 2 ? '…' : ''))) },
    { label: 'Status', render: (s) => h('div', null, s.is_active ? badge(s.last_status || 'active', { tone: s.last_status === 'failed' ? 'danger' : 'ok', label: s.last_status ? titleCase(s.last_status) : 'Waiting for first run' }) : badge('paused', { tone: 'warn', label: 'Paused' }),
      s.last_run_at && h('div', { class: 'small muted' }, fmtDateTime(s.last_run_at)), s.last_error && h('div', { class: 'small', style: { color: 'var(--danger, #b42318)' } }, s.last_error)) },
    { label: '', align: 'right', render: (s) => h('div', { class: 'row', style: { justifyContent: 'flex-end' } },
      (() => { const b = h('button', { class: 'btn sm', type: 'button', onclick: () => sendNow(s, b) }, icon('mail', { size: 14 }), 'Send now'); return b; })(),
      h('button', { class: 'btn sm ghost', type: 'button', title: 'Edit', 'aria-label': `Edit ${s.name}`, onclick: () => open(s) }, icon('edit', { size: 15 })),
      h('button', { class: 'btn sm ghost', type: 'button', title: 'Delete', 'aria-label': `Delete ${s.name}`, onclick: () => remove(s) }, icon('trash', { size: 15 }))) },
  ], rows) : empty('No scheduled reports yet', 'Create one to have a report emailed every day, week or month.'), { flush: true }));
}
