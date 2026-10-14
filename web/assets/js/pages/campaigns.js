import { h } from '../core/dom.js';
import { crudPage } from '../ui/crud.js';
import { badge } from '../ui/feedback.js';
import { href, navigate } from '../core/router.js';
import { fmtDate, fmtMoney, fmtNumber } from '../core/format.js';
import { lookup, enumOptions } from '../core/lookups.js';
import { CAMPAIGN_TYPES } from '../core/constants.js';

export const campaignFields = (rec) => [
  { name: 'client_id', label: 'Client', type: 'select', required: true, options: [], disabled: false, _load: () => lookup('clients') },
  { name: 'code', label: 'Campaign code', required: true, maxlength: 40, help: 'Letters, numbers, dots, dashes or underscores.' },
  { name: 'name', label: 'Campaign name', required: true, full: true },
  { name: 'campaign_type', label: 'Type', type: 'select', options: CAMPAIGN_TYPES },
  { name: 'budget', label: 'Budget (KES)', type: 'decimal', min: 0 },
  { name: 'start_date', label: 'Start date', type: 'date' },
  { name: 'end_date', label: 'End date', type: 'date' },
  { name: 'description', label: 'Description', type: 'textarea', full: true },
  { name: 'objectives', label: 'Objectives', type: 'textarea', full: true },
];

/** Selects whose options come from the API: fill them after the form is built. */
export async function withLoadedOptions(fields) {
  return Promise.all(fields.map(async (f) => (f._load ? { ...f, options: await f._load() } : f)));
}

export default async function render({ container }) {
  const clients = await lookup('clients').catch(() => []);
  const statuses = await enumOptions('campaign_status').catch(() => []);
  container.append(crudPage({
    title: 'Campaigns', subtitle: 'Plan, activate and track field campaigns.', noun: 'campaign', endpoint: 'campaigns',
    perms: { create: 'campaigns.create', edit: 'campaigns.edit', delete: 'campaigns.edit' }, defaultSort: '-created_at', searchPlaceholder: 'Search by name or code…',
    fields: (rec) => campaignFields(rec).map((f) => (f.name === 'client_id' ? { ...f, options: clients } : f)),
    toPayload: (v) => { const o = { ...v }; if (o.budget === null) delete o.budget; return o; },
    filters: [{ key: 'status', label: 'All statuses', options: statuses }, { key: 'client_id', label: 'All clients', options: clients }],
    columns: [
      { key: 'name', label: 'Campaign', sort: 'name', render: (r) => h('div', null, h('a', { href: href(`/campaigns/${r.id}`) }, h('strong', null, r.name)), h('div', { class: 'small muted' }, h('span', { class: 'mono' }, r.code), ' · ', r.client?.name)) },
      { key: 'status', label: 'Status', sort: 'status', render: (r) => badge(r.status) },
      { key: 'dates', label: 'Dates', sort: 'start_date', render: (r) => `${fmtDate(r.start_date)} → ${fmtDate(r.end_date)}` },
      { key: 'budget', label: 'Budget', align: 'right', render: (r) => (r.budget ? fmtMoney(r.budget) : '—') },
      { key: 'scale', label: 'Scale', render: (r) => h('span', { class: 'small muted' }, `${fmtNumber(r.activities_count)} activities · ${fmtNumber(r.locations_count)} locations · ${fmtNumber(r.users_count)} staff`) },
    ],
    onRowClick: (r) => navigate(`/campaigns/${r.id}`),
    canEditRow: (r) => r.status !== 'archived',
    deleteMessage: (r) => `“${r.name}” will be deleted. Campaigns with assignments cannot be deleted — archive them instead.`,
  }));
}
