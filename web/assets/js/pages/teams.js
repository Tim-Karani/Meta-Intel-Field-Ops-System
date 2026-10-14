import { h } from '../core/dom.js';
import { crudPage } from '../ui/crud.js';
import { badge } from '../ui/feedback.js';
import { lookup } from '../core/lookups.js';

export default async function render({ container }) {
  const [supervisors, agents] = await Promise.all([lookup('supervisors').catch(() => []), lookup('agents').catch(() => [])]);
  container.append(crudPage({
    title: 'Teams', subtitle: 'Field teams, their supervisors and members.', noun: 'team', endpoint: 'teams',
    perms: { create: 'teams.manage', edit: 'teams.manage', delete: 'teams.manage' }, defaultSort: 'name', searchPlaceholder: 'Search teams…',
    fields: () => [
      { name: 'name', label: 'Team name', required: true },
      { name: 'region', label: 'Region' },
      { name: 'supervisor_id', label: 'Supervisor', type: 'select', options: supervisors },
      { name: 'status', label: 'Status', type: 'select', options: ['active', 'inactive'], blank: false, value: 'active' },
      { name: 'description', label: 'Description', type: 'textarea', full: true },
      { name: 'members', label: 'Members (field agents)', type: 'multiselect', options: agents, emptyText: 'No field agents available yet.' },
    ],
    toValues: (r) => ({ ...r, members: (r.members || []).map((m) => m.id) }),
    toPayload: (v) => ({ ...v, members: v.members || [] }),
    filters: [{ key: 'supervisor_id', label: 'All supervisors', options: supervisors }, { key: 'status', label: 'All statuses', options: ['active', 'inactive'] }],
    columns: [
      { key: 'name', label: 'Team', sort: 'name', render: (r) => h('strong', null, r.name) },
      { key: 'region', label: 'Region', sort: 'region' },
      { key: 'supervisor', label: 'Supervisor', render: (r) => r.supervisor?.name || h('span', { class: 'muted' }, 'Unassigned') },
      { key: 'members', label: 'Members', render: (r) => h('span', { title: (r.members || []).map((m) => m.name).join(', ') }, `${(r.members || []).length} agent${(r.members || []).length === 1 ? '' : 's'}`) },
      { key: 'status', label: 'Status', render: (r) => badge(r.status) },
    ],
  }));
}
