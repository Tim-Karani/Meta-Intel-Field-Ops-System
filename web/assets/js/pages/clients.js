import { h } from '../core/dom.js';
import { crudPage } from '../ui/crud.js';
import { badge } from '../ui/feedback.js';
import { href, navigate } from '../core/router.js';
import { fmtNumber } from '../core/format.js';

export const clientFields = () => [
  { name: 'name', label: 'Client name', required: true, maxlength: 255 },
  { name: 'code', label: 'Code', maxlength: 40, help: 'Short unique identifier, e.g. KBL.' },
  { name: 'company_name', label: 'Registered company name' },
  { name: 'industry', label: 'Industry' },
  { name: 'email', label: 'Email', type: 'email' },
  { name: 'phone', label: 'Phone' },
  { name: 'status', label: 'Status', type: 'select', options: ['active', 'inactive'], blank: false, value: 'active' },
  { name: 'address', label: 'Address', type: 'textarea', full: true },
  { name: 'notes', label: 'Internal notes', type: 'textarea', full: true },
];

export default async function render({ container }) {
  container.append(crudPage({
    title: 'Clients', subtitle: 'Brands and companies the agency runs campaigns for.', noun: 'client', endpoint: 'clients',
    perms: { create: 'clients.create', edit: 'clients.edit', delete: 'clients.delete' }, defaultSort: 'name', searchPlaceholder: 'Search clients…',
    fields: clientFields, toPayload: (v) => ({ ...v, status: v.status || 'active' }),
    filters: [{ key: 'status', label: 'All statuses', options: ['active', 'inactive'] }],
    columns: [
      { key: 'name', label: 'Client', sort: 'name', render: (r) => h('div', null, h('a', { href: href(`/clients/${r.id}`) }, h('strong', null, r.name)), r.code && h('div', { class: 'small muted mono' }, r.code)) },
      { key: 'industry', label: 'Industry', render: (r) => r.industry || h('span', { class: 'muted' }, '—') },
      { key: 'contact', label: 'Contact', render: (r) => h('div', null, r.email || '—', r.phone && h('div', { class: 'small muted' }, r.phone)) },
      { key: 'campaigns_count', label: 'Campaigns', align: 'right', render: (r) => fmtNumber(r.campaigns_count) },
      { key: 'status', label: 'Status', sort: 'status', render: (r) => badge(r.status) },
    ],
    onRowClick: (r) => navigate(`/clients/${r.id}`),
    deleteMessage: (r) => `“${r.name}” will be removed. Clients that still have campaigns cannot be deleted — archive their campaigns first.`,
  }));
}
