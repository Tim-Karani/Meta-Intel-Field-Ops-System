import { h } from '../core/dom.js';
import { dataTable } from '../ui/table.js';
import { openOutletForm } from '../ui/outlet-form.js';
import { openQuickCreate, openBulkChange } from '../ui/outlet-bulk.js';
import { badge, tabs, asyncView, card, pageHead, empty, confirmDialog, toast, toastError } from '../ui/feedback.js';
import { lookup, invalidateLookups } from '../core/lookups.js';
import { icon } from '../core/icons.js';
import { api } from '../core/api.js';
import { href, navigate } from '../core/router.js';
import { titleCase } from '../core/format.js';
import { mapView } from '../ui/map.js';
import { openLocationImport } from '../ui/location-import.js';
import { can } from '../core/auth.js';

const SOURCE = { manual: 'Entered by the office', first_visit: 'Captured at first visit', field_added: 'Captured when added in the field' };
export const geoSource = (src) => SOURCE[src] || '—';
/** Mapped / awaiting-confirmation / not-mapped status of an outlet's position. */
export const positionBadge = (r) => (r.latitude == null ? badge('not mapped', { tone: 'warn' }) : r.needs_review ? badge('confirm position', { tone: 'info' }) : badge('mapped', { tone: 'ok' }));

export default async function render({ container, query: urlQuery }) {
  const opt = (rows) => rows.map((r) => ({ value: r.id, label: r.name }));
  const [territories, channels, typeRows, statusRows, distributors] = await Promise.all([
    lookup('territories').catch(() => []), lookup('channels').catch(() => []),
    api.get('outlet-types', { per_page: 100 }).then((r) => r.data).catch(() => []), api.get('outlet-statuses', { per_page: 100 }).then((r) => r.data).catch(() => []), lookup('distributors').catch(() => []),
  ]);
  const types = typeRows.map((t) => ({ value: t.slug, label: t.name }));
  const typeName = Object.fromEntries(typeRows.map((t) => [t.slug, t.name]));
  const PRESET = ['channel_id', 'type', 'outlet_status_id', 'distributor_id', 'region'];
  const preset = Object.fromEntries(PRESET.filter((k) => urlQuery?.[k]).map((k) => [k, urlQuery[k]]));
  const presetName = preset.channel_id ? channels.find((c) => c.value === preset.channel_id)?.label : preset.type ? typeName[preset.type] : preset.outlet_status_id ? statusRows.find((x) => x.id === preset.outlet_status_id)?.name
    : preset.distributor_id ? distributors.find((d) => d.value === preset.distributor_id)?.label : preset.region;
  const canManage = can('locations.manage');

  /* ---- outlets table with selection ---- */
  let table;
  const bulk = h('div', { class: 'bulk-bar hidden', role: 'region', 'aria-label': 'Selected outlets' });
  const refresh = () => table?.reload();
  const onSelect = (ids) => {
    bulk.classList.toggle('hidden', !ids.length);
    bulk.replaceChildren(h('strong', null, `${ids.length} selected`),
      h('button', { class: 'btn sm primary', type: 'button', onclick: () => openBulkChange({ ids, onDone: () => table.clearSelection() }) }, 'Change…'),
      h('button', { class: 'btn sm ghost', type: 'button', onclick: () => table.clearSelection() }, 'Clear selection'));
  };
  const remove = async (r) => {
    const ok = await confirmDialog({ title: 'Delete outlet?', message: `“${r.name}” will be removed. Outlets with assignments cannot be deleted; mark them inactive instead.`, confirmLabel: 'Delete', danger: true });
    if (!ok) return;
    try { await api.delete(`locations/${r.id}`); toast('Outlet deleted.'); invalidateLookups(); refresh(); } catch (e) { toastError(e); }
  };
  const where = (r) => [r.town, r.locality, r.region].filter(Boolean).filter((x, i, a) => a.indexOf(x) === i).join(' · ') || r.county || '—';

  const list = () => {
    table = dataTable({
      endpoint: 'locations', defaultSort: 'name', searchPlaceholder: 'Search name, code, town, phone…', query: preset, selectable: canManage, onSelectionChange: onSelect,
      filters: [
        { key: 'geo', label: 'Any position', options: [{ id: 'unmapped', name: 'Not mapped yet' }, { id: 'review', name: 'Needs confirmation' }, { id: 'mapped', name: 'Mapped' }] },
        { key: 'channel_id', label: 'All chains', options: channels }, { key: 'type', label: 'All types', options: types }, { key: 'outlet_status_id', label: 'All statuses', options: opt(statusRows) },
        { key: 'distributor_id', label: 'All distributors', options: distributors }, { key: 'territory_id', label: 'All territories', options: territories },
      ],
      columns: [
        { key: 'name', label: 'Outlet', sort: 'name', render: (r) => h('div', null, h('a', { href: href(`/locations/${r.id}`) }, h('strong', null, r.name)), h('div', { class: 'small muted' }, [r.code, r.shop_no && `Shop ${r.shop_no}`, r.market_name].filter(Boolean).join(' · '))) },
        { key: 'channel', label: 'Chain', render: (r) => r.channel?.name || h('span', { class: 'muted' }, 'Independent') },
        { key: 'type', label: 'Type', render: (r) => h('div', null, typeName[r.type] || titleCase(r.type), r.outlet_subtype && h('div', { class: 'small muted' }, r.outlet_subtype.name)) },
        { key: 'where', label: 'Where', sort: 'town', render: (r) => where(r) },
        { key: 'distributor', label: 'Distributor', render: (r) => r.distributor?.name || '—' },
        { key: 'position', label: 'Pin', render: (r) => positionBadge(r) },
        { key: 'status', label: 'Status', render: (r) => badge(r.outlet_status?.name || r.status, { tone: r.outlet_status?.tone === 'neutral' ? undefined : r.outlet_status?.tone }) },
        { key: '_a', label: '', align: 'right', class: 'nowrap', render: (r) => canManage && h('div', { class: 'row', style: { justifyContent: 'flex-end' } },
          h('button', { class: 'btn sm ghost', type: 'button', title: 'Edit', 'aria-label': `Edit ${r.name}`, onclick: () => openOutletForm({ location: r, onSaved: refresh }) }, icon('edit', { size: 15 })),
          h('button', { class: 'btn sm ghost', type: 'button', title: 'Delete', 'aria-label': `Delete ${r.name}`, onclick: () => remove(r) }, icon('trash', { size: 15 }))) },
      ],
      onRowClick: (r) => navigate(`/locations/${r.id}`),
    });
    return h('div', null, bulk, table);
  };

  /* ---- overview: outlets grouped by chain, type, status, region or distributor ---- */
  const overview = () => {
    const host = h('div', { class: 'stack' });
    let by = 'channel';
    const picker = h('select', { class: 'select', 'aria-label': 'Group outlets by', onchange: () => { by = picker.value; paint(); } },
      ...[['channel', 'Chain'], ['type', 'Type'], ['status', 'Status'], ['region', 'Region'], ['distributor', 'Distributor']].map(([v, l]) => h('option', { value: v }, `Group by ${l.toLowerCase()}`)));
    const body = h('div');
    const filterKey = { channel: 'channel_id', type: 'type', status: 'outlet_status_id', distributor: 'distributor_id', region: 'region' };
    const paint = () => asyncView(body, () => api.get('locations/summary', { group_by: by }).then((r) => r.data), (rows) => {
      if (!rows.length) return empty('No outlets yet', 'Add outlets to see them grouped here.');
      return h('div', { class: 'grid cols-3' }, rows.map((r) => h('button', { class: 'card chain-card', type: 'button', onclick: () => navigate(`/locations?${filterKey[by]}=${encodeURIComponent(r.key ?? '')}`) },
        h('div', { class: 'card-body stack sm' },
          h('div', { class: 'row', style: { justifyContent: 'space-between' } }, h('strong', null, r.name), h('span', { class: 'muted' }, `${r.total} outlet${r.total === 1 ? '' : 's'}`)),
          r.extra && by === 'channel' && h('div', { class: 'small muted' }, r.extra),
          h('div', { class: 'meter', 'aria-label': 'Share with a map pin' }, h('span', { style: { width: `${r.total ? Math.round((100 * r.mapped) / r.total) : 0}%` } })),
          h('div', { class: 'small muted' }, `${r.mapped} pinned · ${r.total - r.mapped} not pinned${r.needs_review ? ` · ${r.needs_review} to confirm` : ''}`)))));
    });
    host.append(h('div', { class: 'row' }, h('div', { class: 'field' }, picker)), body); paint();
    return host;
  };

  const mapTab = () => {
    const host = h('div');
    asyncView(host, () => api.get('locations', { per_page: 100, status: 'active' }), ({ data, meta }) => {
      const markers = data.filter((l) => l.latitude != null && l.longitude != null).map((l) => ({ lat: Number(l.latitude), lng: Number(l.longitude), label: l.name, radiusM: l.geofence_radius_m, onClick: () => navigate(`/locations/${l.id}`) }));
      return card(`Active outlets (${markers.length}${meta.total > markers.length ? ` of ${meta.total}` : ''})`, markers.length ? mapView({ markers, height: 520 }) : h('p', { class: 'muted' }, 'No outlets with coordinates yet.'));
    });
    return host;
  };

  const actions = [
    h('a', { class: 'btn', href: href('/outlet-setup') }, icon('settings', { size: 16 }), 'Outlet setup'),
    canManage && h('button', { class: 'btn', type: 'button', onclick: () => openLocationImport({ onDone: () => navigate('/locations') }) }, 'Import (CSV)'),
    canManage && h('button', { class: 'btn', type: 'button', onclick: () => openQuickCreate({ onDone: refresh }) }, 'Quick create'),
    canManage && h('button', { class: 'btn primary', type: 'button', onclick: () => openOutletForm({ onSaved: refresh }) }, icon('plus', { size: 16 }), 'New outlet'),
  ];
  container.append(...[pageHead({ title: 'Outlets', subtitle: 'Every shop and site your teams visit, grouped by chain, type and place.', actions }),
    presetName ? h('div', { class: 'alert info' }, h('div', null, `Showing outlets for ${presetName}. `, h('a', { href: href('/locations') }, 'Show all outlets'))) : null,
    tabs([{ key: 'list', label: 'Outlets', render: list }, { key: 'overview', label: 'Overview', render: overview }, { key: 'map', label: 'Map', render: mapTab }])].filter(Boolean));
}
