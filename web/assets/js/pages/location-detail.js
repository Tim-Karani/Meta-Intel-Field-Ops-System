import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api } from '../core/api.js';
import { can } from '../core/auth.js';
import { fmtDate, fmtDateTime, titleCase } from '../core/format.js';
import { asyncView, badge, kv, card, pageHead, tabs, toast } from '../ui/feedback.js';
import { dataTable } from '../ui/table.js';
import { formModal } from '../ui/crud.js';
import { mapView, osmLink } from '../ui/map.js';
import { lookup, enumOptions } from '../core/lookups.js';
import { locationFields } from './locations.js';
import { href, navigate } from '../core/router.js';

export default async function render({ container, params }) {
  const holder = h('div'); container.append(holder);
  asyncView(holder, () => api.get(`locations/${params.id}`).then((r) => r.data), (loc, reload) => {
    const edit = can('locations.manage') && h('button', { class: 'btn', type: 'button', onclick: async () => {
      const [territories, clients, types] = await Promise.all([lookup('territories').catch(() => []), lookup('clients').catch(() => []), enumOptions('location_types').catch(() => [])]);
      formModal({ title: 'Edit location', fields: locationFields(territories, clients, types), values: loc, submitLabel: 'Save changes', size: 'lg',
        onSubmit: async (v) => { const o = { ...v }; if (o.geofence_radius_m === null) delete o.geofence_radius_m; await api.patch(`locations/${loc.id}`, o); toast('Location updated.'); }, onSaved: reload });
    } }, icon('edit', { size: 16 }), 'Edit');
    const lat = Number(loc.latitude); const lng = Number(loc.longitude);
    return h('div', null,
      pageHead({ title: loc.name, subtitle: [titleCase(loc.type), loc.town, loc.county].filter(Boolean).join(' · '), actions: [badge(loc.status), edit] }),
      tabs([
        { key: 'overview', label: 'Overview', render: () => h('div', { class: 'grid cols-2' },
          card('Details', kv([['Code', loc.code && h('span', { class: 'mono' }, loc.code)], ['Client', loc.client?.name], ['Territory', loc.territory?.name], ['Address', loc.address], ['Opening hours', loc.opening_hours],
            ['Contact', [loc.contact_name, loc.contact_phone].filter(Boolean).join(' · ') || null], ['Geofence', `${loc.geofence_radius_m ?? 100} m`], ['Coordinates', h('a', { href: osmLink(lat, lng), target: '_blank', rel: 'noopener noreferrer' }, `${lat.toFixed(5)}, ${lng.toFixed(5)}`)], ['Notes', loc.notes]])),
          card('Position', mapView({ markers: [{ lat, lng, label: loc.name, radiusM: loc.geofence_radius_m }], height: 300, interactive: true }))) },
        { key: 'visits', label: 'Visit history', render: () => dataTable({
          endpoint: 'visits', query: { location_id: loc.id }, defaultSort: '-visit_date', search: false, emptyTitle: 'No visits at this location yet',
          columns: [
            { key: 'visit_date', label: 'Date', sort: 'visit_date', render: (r) => fmtDate(r.visit_date) },
            { key: 'agent', label: 'Agent', render: (r) => r.agent?.name || '—' },
            { key: 'campaign', label: 'Campaign', render: (r) => r.campaign?.name || '—' },
            { key: 'gps', label: 'GPS', render: (r) => badge(r.gps_outcome) },
            { key: 'status', label: 'Status', render: (r) => badge(r.status) },
          ],
          onRowClick: (r) => navigate(`/visits/${r.id}`) }) },
        { key: 'assignments', label: 'Assignments', render: () => dataTable({
          endpoint: 'assignments', query: { location_id: loc.id }, defaultSort: '-scheduled_date', search: false, emptyTitle: 'No assignments for this location',
          columns: [
            { key: 'scheduled_date', label: 'Date', render: (r) => fmtDate(r.scheduled_date) },
            { key: 'agent', label: 'Agent', render: (r) => r.agent?.name || '—' },
            { key: 'campaign', label: 'Campaign', render: (r) => (r.campaign ? h('a', { href: href(`/campaigns/${r.campaign.id}`) }, r.campaign.name) : '—') },
            { key: 'status', label: 'Status', render: (r) => badge(r.status) },
          ] }) },
      ]), h('p', { class: 'small muted', style: { marginTop: '12px' } }, `Created ${fmtDateTime(loc.created_at)}`));
  });
}
