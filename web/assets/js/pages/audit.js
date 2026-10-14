import { h } from '../core/dom.js';
import { can } from '../core/auth.js';
import { fmtDateTime } from '../core/format.js';
import { badge, pageHead, tabs, openModal } from '../ui/feedback.js';
import { dataTable } from '../ui/table.js';

const MODULES = ['auth', 'users', 'clients', 'campaigns', 'activities', 'teams', 'locations', 'products', 'forms', 'assignments', 'attendance', 'visits', 'evidence', 'issues', 'reports', 'system'];

function openLog(r) {
  const block = (label, v) => v && Object.keys(v).length ? h('div', null, h('h4', { style: { margin: '10px 0 4px' } }, label), h('pre', { class: 'code-block' }, JSON.stringify(v, null, 2))) : null;
  openModal({ title: r.action, size: 'lg', body: h('div', null,
    h('dl', { class: 'kv' }, h('dt', null, 'When'), h('dd', null, fmtDateTime(r.created_at)), h('dt', null, 'Actor'), h('dd', null, r.actor?.name || 'System'), h('dt', null, 'Module'), h('dd', null, r.module),
      h('dt', null, 'Subject'), h('dd', null, [r.subject_type?.split('\\').pop(), r.subject_id].filter(Boolean).join(' · ') || '—'), h('dt', null, 'IP'), h('dd', { class: 'mono' }, r.ip || '—'), h('dt', null, 'Request'), h('dd', { class: 'mono' }, r.request_id || '—')),
    block('Before', r.before), block('After', r.after), block('Details', r.meta)),
  footer: (close) => h('button', { class: 'btn', type: 'button', onclick: () => close() }, 'Close') });
}

export default async function render({ container }) {
  const logs = () => dataTable({
    endpoint: 'audit-logs', id: 'audit', defaultSort: null, searchPlaceholder: 'Search action, module or record id…',
    filters: [{ key: 'module', label: 'All modules', options: MODULES }, { key: 'date_from', label: 'From date', type: 'date' }, { key: 'date_to', label: 'To date', type: 'date' }],
    columns: [
      { key: 'created_at', label: 'When', render: (r) => fmtDateTime(r.created_at) }, { key: 'actor', label: 'Actor', render: (r) => r.actor?.name || h('span', { class: 'muted' }, 'System') },
      { key: 'action', label: 'Action', render: (r) => h('span', { class: 'mono' }, r.action) }, { key: 'module', label: 'Module', render: (r) => badge(r.module, { tone: '' }) },
      { key: 'subject', label: 'Record', render: (r) => (r.subject_id ? h('span', { class: 'small mono muted' }, `${(r.subject_type || '').split('\\').pop()} ${String(r.subject_id).slice(0, 8)}`) : '—') },
    ], onRowClick: openLog, emptyTitle: 'No audit entries match',
  });
  const security = () => dataTable({
    endpoint: 'security-events', id: 'security', defaultSort: null, search: false,
    filters: [{ key: 'severity', label: 'Any severity', options: ['info', 'warning', 'critical'] }],
    columns: [
      { key: 'created_at', label: 'When', render: (r) => fmtDateTime(r.created_at) }, { key: 'type', label: 'Event', render: (r) => h('span', { class: 'mono' }, r.type) },
      { key: 'severity', label: 'Severity', render: (r) => badge(r.severity, { tone: r.severity === 'critical' ? 'danger' : r.severity === 'warning' ? 'warn' : 'info' }) },
      { key: 'ip', label: 'IP', render: (r) => h('span', { class: 'mono small' }, r.ip || '—') }, { key: 'user_id', label: 'User', render: (r) => (r.user_id ? h('span', { class: 'mono small muted' }, r.user_id.slice(0, 8)) : '—') },
    ], emptyTitle: 'No security events',
  });
  container.append(pageHead({ title: 'Audit logs', subtitle: 'Append-only record of who did what. Entries cannot be edited or removed.' }),
    tabs([{ key: 'audit', label: 'Audit trail', render: logs }, can('system.manage') && { key: 'security', label: 'Security events', render: security }].filter(Boolean)));
}
