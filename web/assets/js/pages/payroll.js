import { h } from '../core/dom.js';
import { api } from '../core/api.js';
import { can } from '../core/auth.js';
import { fmtDate, fmtMoney, fmtNumber } from '../core/format.js';
import { asyncView, badge, card, confirmDialog, empty, pageHead, stat, toast, toastError, actionButton } from '../ui/feedback.js';
import { formModal } from '../ui/crud.js';
import { simpleTable } from '../ui/table.js';

/* Payroll: attendance turned into pay. Time worked starts at the first outlet check-in (clocking in alone is not paid time).
   Draft -> Approved -> Paid. Only a draft can be recalculated or adjusted. */

const TONE = { draft: 'warn', approved: 'info', paid: 'ok' };
const RATE = { daily: 'per day', hourly: 'per hour', monthly: 'per month' };
const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

export default async function render({ container }) {
  const manage = can('payroll.manage');
  let openId = null;
  const listHost = h('div'); const detailHost = h('div', { class: 'stack' });
  const create = () => {
    const now = new Date(); const prevEnd = new Date(now.getFullYear(), now.getMonth(), 0); const prevStart = new Date(now.getFullYear(), now.getMonth() - 1, 1);
    formModal({
      title: 'New payroll run', submitLabel: 'Calculate', intro: 'Pays everyone with outlet attendance in these dates, using their pay type and rate (set on the user).',
      fields: [{ name: 'name', label: 'Name', required: true, full: true, placeholder: 'e.g. October 2026' }, { name: 'period_start', label: 'From', type: 'date', required: true }, { name: 'period_end', label: 'To', type: 'date', required: true }, { name: 'notes', label: 'Notes', type: 'textarea', full: true }],
      values: { period_start: iso(prevStart), period_end: iso(prevEnd), name: prevStart.toLocaleString('en', { month: 'long', year: 'numeric' }) },
      onSubmit: (v) => api.post('payroll-runs', v), onSaved: (res) => { openId = res.data.id; loadList(); loadDetail(); },
    });
  };
  container.append(pageHead({ title: 'Payroll', subtitle: 'From attendance to pay: days and hours worked, rates, adjustments, approval and an Excel-ready export.', actions: manage ? actionButton('New payroll run', async () => create(), { cls: 'btn primary', iconName: 'plus' }) : null }), listHost, detailHost);

  const loadList = asyncView(listHost, () => api.get('payroll-runs').then((r) => r.data), (rows) => (rows.length ? card('Payroll runs', simpleTable([
    { label: 'Run', render: (r) => h('strong', null, r.name) },
    { label: 'Period', render: (r) => `${fmtDate(r.period_start)} to ${fmtDate(r.period_end)}` },
    { label: 'People', align: 'right', render: (r) => fmtNumber(r.totals.people) },
    { label: 'Net pay', align: 'right', render: (r) => h('strong', null, fmtMoney(r.totals.net)) },
    { label: 'Status', render: (r) => badge(r.status, { tone: TONE[r.status] }) },
  ], rows, { onRowClick: (r) => { openId = r.id; loadDetail(); } }), { flush: true }) : empty('No payroll runs yet', manage ? 'Create the first run for a month.' : 'Nothing has been prepared yet.')));

  function loadDetail() {
    if (!openId) return;
    asyncView(detailHost, () => api.get(`payroll-runs/${openId}`).then((r) => r.data), (run, reload) => {
      const draft = run.status === 'draft';
      const act = (label, fn, cls = 'btn') => actionButton(label, async () => { try { await fn(); reload(); loadList(); } catch (e) { toastError(e); } }, { cls });
      const adjust = (l) => formModal({
        title: `Adjust pay: ${l.name}`, intro: 'Use a negative number for a deduction, positive for a bonus or allowance.',
        fields: [{ name: 'adjustment', label: 'Adjustment', type: 'decimal', required: true, value: l.adjustment }, { name: 'adjustment_note', label: 'Reason', maxlength: 255, value: l.adjustment_note || '' }],
        onSubmit: (v) => api.patch(`payroll-runs/${run.id}/lines/${l.id}`, { adjustment: v.adjustment ?? 0, adjustment_note: v.adjustment_note }), onSaved: () => { reload(); loadList(); },
      });
      const t = run.totals;
      return h('div', { class: 'stack' },
        h('div', { class: 'card' }, h('div', { class: 'card-head' }, h('h3', null, `${run.name} · ${fmtDate(run.period_start)} to ${fmtDate(run.period_end)}`), h('div', { class: 'row' }, badge(run.status, { tone: TONE[run.status] }),
          can('payroll.view') && actionButton('Export CSV', async () => { try { await api.download(`payroll-runs/${run.id}/export`, {}, `payroll-${run.period_start}.csv`); } catch (e) { toastError(e); } }, { iconName: 'download' }),
          manage && draft && act('Recalculate', () => api.post(`payroll-runs/${run.id}/recalculate`).then(() => toast('Recalculated from attendance.'))),
          manage && draft && act('Approve', async () => { if (await confirmDialog({ title: 'Approve this payroll?', message: 'After approval the figures are locked.', confirmLabel: 'Approve' })) { await api.post(`payroll-runs/${run.id}/approve`); toast('Approved.'); } }, 'btn primary'),
          manage && run.status === 'approved' && act('Mark as paid', async () => { if (await confirmDialog({ title: 'Mark as paid?', message: 'Do this once the money has been sent.', confirmLabel: 'Mark paid' })) { await api.post(`payroll-runs/${run.id}/paid`); toast('Marked as paid.'); } }, 'btn primary'),
          manage && draft && act('Delete draft', async () => { if (await confirmDialog({ title: 'Delete this draft?', message: 'The run and its figures are removed.', confirmLabel: 'Delete', danger: true })) { await api.delete(`payroll-runs/${run.id}`); openId = null; detailHost.replaceChildren(); } }, 'btn danger'))),
          h('div', { class: 'card-body' }, h('div', { class: 'grid cols-4' }, stat({ label: 'People', value: fmtNumber(t.people), icon: 'users' }), stat({ label: 'Days worked', value: fmtNumber(t.days), icon: 'calendar' }), stat({ label: 'Gross', value: fmtMoney(t.gross), icon: 'dollar' }), stat({ label: 'Net pay', value: fmtMoney(t.net), icon: 'checkCircle' })))),
        run.lines.length ? simpleTable([
          { label: 'Name', render: (l) => h('strong', null, l.name) },
          { label: 'Days', align: 'right', render: (l) => fmtNumber(l.days_worked) },
          { label: 'Hours', align: 'right', render: (l) => fmtNumber(l.hours_worked, 1) },
          { label: 'Late', align: 'right', render: (l) => fmtNumber(l.late_days) },
          { label: 'Outlets', align: 'right', render: (l) => fmtNumber(l.outlets_visited) },
          { label: 'Rate', render: (l) => h('span', { class: 'small' }, `${fmtMoney(l.pay_rate)} ${RATE[l.pay_type]}`, l.pay_rate === 0 && h('span', { class: 'warn-text' }, ' (no rate set)')) },
          { label: 'Gross', align: 'right', render: (l) => fmtMoney(l.gross) },
          { label: 'Adjustment', align: 'right', render: (l) => h('div', null, l.adjustment ? fmtMoney(l.adjustment) : '—', l.adjustment_note && h('div', { class: 'small muted' }, l.adjustment_note)) },
          { label: 'Net', align: 'right', render: (l) => h('strong', null, fmtMoney(l.net)) },
          { label: '', render: (l) => (manage && draft ? h('button', { class: 'btn sm', type: 'button', onclick: () => adjust(l) }, 'Adjust') : null) },
        ], run.lines) : empty('No attendance in this period', 'Nobody has an outlet check-in between these dates.'));
    });
  }
}
