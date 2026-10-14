import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api } from '../core/api.js';
import { can } from '../core/auth.js';
import { fmtDateTime, relTime, titleCase } from '../core/format.js';
import { asyncView, badge, pageHead, openModal, toast, toastError, confirmDialog, kv } from '../ui/feedback.js';
import { dataTable } from '../ui/table.js';
import { formModal } from '../ui/crud.js';
import { lookup, enumOptions } from '../core/lookups.js';
import { href } from '../core/router.js';

const CATEGORIES = ['stock_out', 'pricing', 'display', 'equipment', 'safety', 'access', 'competitor', 'product_quality', 'other'];

export default async function render({ container, query }) {
  const [campaigns, statuses, priorities] = await Promise.all([lookup('campaigns').catch(() => []), enumOptions('issue_status').catch(() => []), enumOptions('issue_priority').catch(() => [])]);
  let table;
  const reload = () => table.reload();

  const create = async () => {
    const locations = await lookup('locations').catch(() => []);
    formModal({ title: 'Report an issue', size: 'lg', submitLabel: 'Report issue', fields: [
      { name: 'title', label: 'Title', required: true, full: true, maxlength: 255 },
      { name: 'campaign_id', label: 'Campaign', type: 'select', options: campaigns }, { name: 'location_id', label: 'Location', type: 'select', options: locations },
      { name: 'category', label: 'Category', type: 'select', options: CATEGORIES }, { name: 'priority', label: 'Priority', type: 'select', options: priorities, blank: false, value: 'medium' },
      { name: 'description', label: 'What happened?', type: 'textarea', rows: 4, full: true },
    ], onSubmit: async (v) => { const r = await api.post('issues', { ...v, client_uuid: crypto.randomUUID() }); toast('Issue reported.'); return r.data; }, onSaved: reload });
  };

  async function openIssue(row) {
    const body = h('div');
    const m = openModal({ title: row.title, body, size: 'lg', drawer: true, onClose: reload });
    asyncView(body, () => api.get(`issues/${row.id}`).then((r) => r.data), (issue, refresh) => detail(issue, refresh, m));
  }

  container.append(pageHead({ title: 'Issues', subtitle: 'Problems found in the field, from report to resolution.', actions: can('issues.create') && h('button', { class: 'btn primary', type: 'button', onclick: create }, icon('plus', { size: 16 }), 'Report issue') }));
  table = dataTable({
    endpoint: 'issues', defaultSort: '-created_at', searchPlaceholder: 'Search issues…',
    filters: [{ key: 'status', label: 'All statuses', options: statuses }, { key: 'priority', label: 'All priorities', options: priorities }, { key: 'campaign_id', label: 'All campaigns', options: campaigns }],
    columns: [
      { key: 'title', label: 'Issue', sort: 'title', render: (r) => h('div', null, h('strong', null, r.title), r.category && h('div', { class: 'small muted' }, titleCase(r.category))) },
      { key: 'campaign', label: 'Campaign', render: (r) => r.campaign?.name || '—' },
      { key: 'location', label: 'Location', render: (r) => r.location?.name || '—' },
      { key: 'priority', label: 'Priority', sort: 'priority', render: (r) => badge(r.priority) },
      { key: 'status', label: 'Status', sort: 'status', render: (r) => badge(r.status) },
      { key: 'assignee', label: 'Assigned to', render: (r) => r.assignee?.name || h('span', { class: 'muted' }, 'Unassigned') },
      { key: 'created_at', label: 'Reported', sort: 'created_at', render: (r) => relTime(r.created_at) },
    ],
    onRowClick: openIssue, emptyTitle: 'No issues', emptyText: 'Nothing has been reported.',
  });
  container.append(table);
  if (query?.open) openIssue({ id: query.open, title: 'Issue' });
}

function detail(issue, refresh, modal) {

  const transitions = can('issues.resolve') ? issue.allowed_transitions || [] : [];
  const go = async (status) => {
    let resolution; let note;
    const r = await confirmDialog({ title: `Mark as ${titleCase(status)}?`, message: status === 'resolved' ? 'Describe how the issue was resolved.' : `Change the status to ${titleCase(status)}.`, confirmLabel: titleCase(status),
      reason: { label: status === 'resolved' ? 'Resolution' : 'Note (optional)', required: status === 'resolved' } });
    if (!r) return;
    if (status === 'resolved') resolution = r.reason; else note = r.reason;
    try { await api.post(`issues/${issue.id}/status`, { status, resolution, note }); toast('Issue updated.'); refresh(); } catch (e) { toastError(e); }
  };
  const assign = async () => {
    const staff = await lookup('staff').catch(() => []);
    formModal({ title: 'Assign issue', fields: [{ name: 'assigned_to', label: 'Assign to', type: 'select', required: true, options: staff }], values: { assigned_to: issue.assigned_to },
      onSubmit: async (v) => { await api.post(`issues/${issue.id}/assign`, v); toast('Issue assigned.'); }, onSaved: refresh, submitLabel: 'Assign' });
  };
  const input = h('textarea', { class: 'textarea', rows: 2, maxlength: 2000, placeholder: 'Add a comment…', 'aria-label': 'Comment' });
  const post = async (btn) => {
    const body = input.value.trim(); if (!body) return;
    btn.disabled = true;
    try { await api.post(`issues/${issue.id}/comments`, { body }); input.value = ''; refresh(); } catch (e) { toastError(e); btn.disabled = false; }
  };

  return h('div', { class: 'stack' },
    h('div', { class: 'row', style: { flexWrap: 'wrap' } }, badge(issue.status), badge(issue.priority), issue.category && h('span', { class: 'badge' }, titleCase(issue.category)),
      h('span', { class: 'spacer' }),
      can('issues.assign') && !['closed'].includes(issue.status) && h('button', { class: 'btn sm', type: 'button', onclick: assign }, icon('users', { size: 14 }), 'Assign'),
      transitions.map((s) => h('button', { class: ['btn sm', s === 'resolved' ? 'primary' : s === 'escalated' ? 'danger' : ''], type: 'button', onclick: () => go(s) }, titleCase(s)))),
    issue.description && h('p', { style: { whiteSpace: 'pre-wrap' } }, issue.description),
    kv([['Campaign', issue.campaign?.name], ['Location', issue.location?.name], ['Visit', issue.visit_id && can('visits.view') ? h('a', { href: href(`/visits/${issue.visit_id}`), onclick: () => modal.close() }, 'Open visit') : null],
      ['Reported by', issue.reporter?.name], ['Assigned to', issue.assignee?.name], ['Reported', fmtDateTime(issue.created_at)], ['Resolution', issue.resolution]]),
    h('h3', { style: { margin: '8px 0 0' } }, 'Activity'),
    (issue.comments || []).length ? h('ol', { class: 'timeline' }, issue.comments.map((c) => h('li', null,
      h('div', null, h('strong', null, c.user?.name || 'System'), h('span', { class: 'small muted' }, ` · ${fmtDateTime(c.created_at)}`)),
      h('div', { class: c.type === 'comment' ? '' : 'muted small', style: { whiteSpace: 'pre-wrap' } }, c.body)))) : h('p', { class: 'muted small' }, 'No activity yet.'),
    can('issues.view') && h('div', { class: 'stack sm' }, input, h('div', null, h('button', { class: 'btn primary sm', type: 'button', onclick: (e) => post(e.currentTarget) }, 'Post comment'))));
}
