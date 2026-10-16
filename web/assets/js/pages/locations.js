import { h } from '../core/dom.js';
import { crudPage } from '../ui/crud.js';
import { badge, tabs, asyncView, card, pageHead } from '../ui/feedback.js';
import { lookup, enumOptions } from '../core/lookups.js';
import { api } from '../core/api.js';
import { href, navigate } from '../core/router.js';
import { titleCase } from '../core/format.js';
import { mapView } from '../ui/map.js';

const SOURCE = { manual: 'Entered by the office', first_visit: 'Captured at first visit', field_added: 'Captured when added in the field' };
export const geoSource = (src) => SOURCE[src] || '—';
/** Mapped / awaiting-confirmation / not-mapped status of an outlet's position. */
export const positionBadge = (r) => (r.latitude == null ? badge('not mapped', { tone: 'warn' }) : r.needs_review ? badge('confirm position', { tone: 'info' }) : badge('mapped', { tone: 'ok' }));

export const locationFields = (territories, clients, types) => [
  { name: 'name', label: 'Location name', required: true },
  { name: 'code', label: 'Code', maxlength: 40 },
  { name: 'type', label: 'Type', type: 'select', options: types, blank: false, value: 'retail_store' },
  { name: 'client_id', label: 'Client (optional)', type: 'select', options: clients, help: 'Leave empty for a shared location.' },
  { name: 'territory_id', label: 'Territory', type: 'select', options: territories },
  { name: 'county', label: 'County' }, { name: 'town', label: 'Town' },
  { name: 'address', label: 'Address', full: true },
  { name: 'latitude', label: 'Latitude', type: 'decimal', min: -90, max: 90, help: 'Optional. Leave latitude and longitude empty and the position is captured when an agent first starts a visit here.' },
  { name: 'longitude', label: 'Longitude', type: 'decimal', min: -180, max: 180, help: 'Both or neither. Decimal degrees, e.g. 36.8219.' },
  { name: 'geofence_radius_m', label: 'Geofence radius (m)', type: 'number', min: 10, max: 5000, value: 100, help: 'Check-ins within this distance count as on-site.' },
  { name: 'opening_hours', label: 'Opening hours' },
  { name: 'contact_name', label: 'Contact name' }, { name: 'contact_phone', label: 'Contact phone' },
  { name: 'status', label: 'Status', type: 'select', options: ['active', 'inactive'], blank: false, value: 'active' },
  { name: 'notes', label: 'Notes', type: 'textarea', full: true },
];

export default async function render({ container }) {
  const [territories, clients, types] = await Promise.all([lookup('territories').catch(() => []), lookup('clients').catch(() => []), enumOptions('location_types').catch(() => [])]);
  const list = () => crudPage({
    embedded: true,
    title: 'Locations', subtitle: 'Outlets and sites where field work happens.', noun: 'location', endpoint: 'locations',
    perms: { create: 'locations.manage', edit: 'locations.manage', delete: 'locations.manage' }, defaultSort: 'name', searchPlaceholder: 'Search name, town, county, code…',
    fields: () => locationFields(territories, clients, types),
    toPayload: (v) => { const o = { ...v }; if (o.geofence_radius_m === null) delete o.geofence_radius_m; if (!o.type) delete o.type; return o; },
    filters: [{ key: 'geo', label: 'Any position', options: [{ id: 'unmapped', name: 'Not mapped yet' }, { id: 'review', name: 'Needs confirmation' }, { id: 'mapped', name: 'Mapped' }] }, { key: 'territory_id', label: 'All territories', options: territories }, { key: 'type', label: 'All types', options: types }, { key: 'status', label: 'All statuses', options: ['active', 'inactive'] }],
    columns: [
      { key: 'name', label: 'Location', sort: 'name', render: (r) => h('div', null, h('a', { href: href(`/locations/${r.id}`) }, h('strong', null, r.name)), r.code && h('div', { class: 'small muted mono' }, r.code)) },
      { key: 'type', label: 'Type', render: (r) => titleCase(r.type) },
      { key: 'town', label: 'Town / county', sort: 'town', render: (r) => [r.town, r.county].filter(Boolean).join(', ') || '—' },
      { key: 'territory', label: 'Territory', render: (r) => r.territory?.name || '—' },
      { key: 'position', label: 'Position', render: (r) => positionBadge(r) },
      { key: 'geofence', label: 'Geofence', align: 'right', render: (r) => `${r.geofence_radius_m ?? 100} m` },
      { key: 'status', label: 'Status', render: (r) => badge(r.status) },
    ],
    onRowClick: (r) => navigate(`/locations/${r.id}`),
  });

  const mapTab = (panel) => {
    const host = h('div');
    asyncView(host, () => api.get('locations', { per_page: 100, status: 'active' }), ({ data, meta }) => {
      const markers = data.filter((l) => l.latitude != null && l.longitude != null).map((l) => ({ lat: Number(l.latitude), lng: Number(l.longitude), label: l.name, radiusM: l.geofence_radius_m, onClick: () => navigate(`/locations/${l.id}`) }));
      return card(`Active locations (${markers.length}${meta.total > markers.length ? ` of ${meta.total}` : ''})`, markers.length ? mapView({ markers, height: 520 }) : h('p', { class: 'muted' }, 'No locations with coordinates yet.'));
    });
    return host;
  };

  container.append(pageHead({ title: 'Locations', subtitle: 'Outlets and sites where field work happens.' }), tabs([{ key: 'list', label: 'List', render: list }, { key: 'map', label: 'Map', render: mapTab }]));
}
