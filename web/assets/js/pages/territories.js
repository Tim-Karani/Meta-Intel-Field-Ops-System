import { h } from '../core/dom.js';
import { crudPage } from '../ui/crud.js';
import { badge, tabs, pageHead } from '../ui/feedback.js';
import { lookup } from '../core/lookups.js';
import { api } from '../core/api.js';
import { fmtNumber } from '../core/format.js';
import { pickerModal } from '../ui/picker.js';
import { toast } from '../ui/feedback.js';
import { can } from '../core/auth.js';

export default async function render({ container }) {
  const territories = await lookup('territories').catch(() => []);
  const territoryTab = () => crudPage({
    embedded: true,
    title: 'Territories', subtitle: 'Geographic areas used to group locations and routes.', noun: 'territory', endpoint: 'territories',
    perms: { create: 'territories.manage', edit: 'territories.manage', delete: 'territories.manage' }, defaultSort: 'name', searchPlaceholder: 'Search territories…',
    fields: () => [
      { name: 'name', label: 'Name', required: true }, { name: 'code', label: 'Code', maxlength: 40 }, { name: 'region', label: 'Region' },
      { name: 'status', label: 'Status', type: 'select', options: ['active', 'inactive'], blank: false, value: 'active' },
      { name: 'description', label: 'Description', type: 'textarea', full: true },
    ],
    columns: [
      { key: 'name', label: 'Territory', sort: 'name', render: (r) => h('strong', null, r.name) },
      { key: 'code', label: 'Code', render: (r) => (r.code ? h('span', { class: 'mono' }, r.code) : '—') },
      { key: 'region', label: 'Region', sort: 'region' },
      { key: 'locations_count', label: 'Locations', align: 'right', render: (r) => fmtNumber(r.locations_count) },
      { key: 'routes_count', label: 'Routes', align: 'right', render: (r) => fmtNumber(r.routes_count) },
      { key: 'status', label: 'Status', render: (r) => badge(r.status) },
    ],
  });

  const routeTab = () => crudPage({
    embedded: true,
    title: 'Routes', subtitle: 'Ordered sets of locations visited in sequence.', noun: 'route', endpoint: 'routes',
    perms: { create: 'territories.manage', edit: 'territories.manage', delete: 'territories.manage' }, defaultSort: 'name', searchPlaceholder: 'Search routes…',
    fields: () => [
      { name: 'name', label: 'Route name', required: true }, { name: 'code', label: 'Code', maxlength: 40 },
      { name: 'territory_id', label: 'Territory', type: 'select', options: territories },
      { name: 'status', label: 'Status', type: 'select', options: ['active', 'inactive'], blank: false, value: 'active' },
      { name: 'description', label: 'Description', type: 'textarea', full: true },
    ],
    toPayload: (v) => { const o = { ...v }; return o; },
    filters: [{ key: 'territory_id', label: 'All territories', options: territories }, { key: 'status', label: 'All statuses', options: ['active', 'inactive'] }],
    rowActions: (r, reload) => can('territories.manage') && h('button', { class: 'btn sm', type: 'button', onclick: () => pickerModal({
      title: `Stops on “${r.name}”`, entity: 'locations', intro: 'Choose the locations on this route. Stops are visited in the order they were saved.',
      selected: (r.locations || []).map((l) => ({ value: l.id, label: l.name })),
      onSave: async (sel) => { await api.patch(`routes/${r.id}`, { locations: sel.map((s) => s.value) }); toast('Route stops saved.'); reload(); },
    }) }, 'Stops'),
    columns: [
      { key: 'name', label: 'Route', sort: 'name', render: (r) => h('strong', null, r.name) },
      { key: 'code', label: 'Code', render: (r) => (r.code ? h('span', { class: 'mono' }, r.code) : '—') },
      { key: 'territory', label: 'Territory', render: (r) => r.territory?.name || '—' },
      { key: 'stops', label: 'Stops', align: 'right', render: (r) => fmtNumber((r.locations || []).length) },
      { key: 'status', label: 'Status', render: (r) => badge(r.status) },
    ],
  });

  container.append(pageHead({ title: 'Territories & routes', subtitle: 'Organise locations geographically and into visit routes.' }),
    tabs([{ key: 'territories', label: 'Territories', render: territoryTab }, { key: 'routes', label: 'Routes', render: routeTab }]));
}
