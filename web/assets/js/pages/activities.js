import { h } from '../core/dom.js';
import { crudPage } from '../ui/crud.js';
import { badge } from '../ui/feedback.js';
import { fmtDate, fmtNumber, titleCase } from '../core/format.js';
import { lookup } from '../core/lookups.js';
import { ACTIVITY_TYPES } from '../core/constants.js';
import { href } from '../core/router.js';

export const activityFields = (campaigns, forms) => [
  { name: 'campaign_id', label: 'Campaign', type: 'select', required: true, options: campaigns },
  { name: 'name', label: 'Activity name', required: true },
  { name: 'activity_type', label: 'Type', type: 'select', options: ACTIVITY_TYPES },
  { name: 'form_id', label: 'Data-capture form', type: 'select', options: forms, help: 'Only published forms. Leave empty to use the campaign’s form.' },
  { name: 'target', label: 'Target (visits)', type: 'number', min: 0 },
  { name: 'status', label: 'Status', type: 'select', options: ['planned', 'active', 'completed', 'cancelled'], blank: false, value: 'planned' },
  { name: 'start_date', label: 'Start date', type: 'date' },
  { name: 'end_date', label: 'End date', type: 'date' },
  { name: 'description', label: 'Description', type: 'textarea', full: true },
];

export default async function render({ container }) {
  const [campaigns, forms] = await Promise.all([lookup('campaigns').catch(() => []), lookup('forms', { status: 'published' }).catch(() => [])]);
  container.append(crudPage({
    title: 'Activities', subtitle: 'What agents do in the field, grouped under campaigns.', noun: 'activity', endpoint: 'activities',
    perms: { create: 'activities.create', edit: 'activities.edit', delete: 'activities.edit' }, defaultSort: 'name', searchPlaceholder: 'Search activities…',
    fields: () => activityFields(campaigns, forms),
    filters: [{ key: 'campaign_id', label: 'All campaigns', options: campaigns }, { key: 'status', label: 'All statuses', options: ['planned', 'active', 'completed', 'cancelled'] }],
    columns: [
      { key: 'name', label: 'Activity', sort: 'name', render: (r) => h('strong', null, r.name) },
      { key: 'campaign', label: 'Campaign', render: (r) => (r.campaign ? h('a', { href: href(`/campaigns/${r.campaign.id ?? r.campaign_id}`) }, r.campaign.name) : '—') },
      { key: 'activity_type', label: 'Type', render: (r) => (r.activity_type ? titleCase(r.activity_type) : '—') },
      { key: 'target', label: 'Target', align: 'right', render: (r) => fmtNumber(r.target) },
      { key: 'dates', label: 'Dates', render: (r) => `${fmtDate(r.start_date)} → ${fmtDate(r.end_date)}` },
      { key: 'status', label: 'Status', render: (r) => badge(r.status) },
    ],
  }));
}
