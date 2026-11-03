import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api } from '../core/api.js';
import { lookup, invalidateLookups } from '../core/lookups.js';
import { openModal, toast, toastError } from './feedback.js';

/* Two fast ways to work on many outlets at once:
   - Quick create: paste a list of outlet names, set what they share (chain, type, place...), and add them in one go.
   - Change many: pick outlets in the list, then change one thing about all of them. */

const listOf = async (path, params = {}) => (await api.get(path, { per_page: 100, ...params })).data;
const select = (label, blank, rows, { onchange } = {}) => {
  const el = h('select', { class: 'select', 'aria-label': label, onchange }, h('option', { value: '' }, blank), ...rows.map((r) => h('option', { value: r.id ?? r.value }, r.name ?? r.label)));
  return el;
};
const field = (label, control, help) => h('div', { class: 'field' }, h('label', null, label), control, help && h('div', { class: 'help' }, help));

/** Three linked selects: region > locality > area. */
function placePicker(regions) {
  const region = select('Region', regions.length ? '— Any —' : 'No regions set up yet', regions, { onchange: () => fill(locality, 'locality', region.value, '— Choose a region first —').then(() => fill(area, 'area', '', '— Choose a locality first —')) });
  const locality = select('Locality', '— Choose a region first —', [], { onchange: () => fill(area, 'area', locality.value, '— Choose a locality first —') });
  const area = select('Area', '— Choose a locality first —', []);
  async function fill(el, level, parent, blank) {
    const rows = parent ? await listOf('geo-areas', { level, parent_id: parent }).catch(() => []) : [];
    el.replaceChildren(h('option', { value: '' }, parent ? (rows.length ? '— Any —' : 'Nothing here yet') : blank), ...rows.map((r) => h('option', { value: r.id }, r.name)));
  }
  const text = (el) => (el.value ? el.selectedOptions[0].textContent : '');
  return {
    el: h('div', { class: 'form-grid' }, field('Region', region), field('Locality', locality), field('Area', area)),
    areaId: () => area.value || locality.value || region.value || null,
    names: () => ({ region: text(region), locality: text(locality), area: text(area) }),
  };
}

export async function openQuickCreate({ onDone } = {}) {
  const [channels, types, distributors, regions, campaigns] = await Promise.all([
    listOf('channels', { status: 'active' }).catch(() => []), listOf('outlet-types', { status: 'active' }).catch(() => []), listOf('distributors', { status: 'active' }).catch(() => []),
    listOf('geo-areas', { level: 'region' }).catch(() => []), lookup('campaigns').catch(() => []),
  ]);
  const names = h('textarea', { class: 'textarea', rows: 8, placeholder: 'One outlet per line, e.g.\nBranch One\nBranch Two\nBranch Three', 'aria-label': 'Outlet names' });
  const chain = select('Chain', 'Independent / none', channels);
  const type = h('select', { class: 'select', 'aria-label': 'Type' }, ...types.map((t) => h('option', { value: t.slug }, t.name)));
  const dist = select('Distributor', 'None', distributors);
  const place = placePicker(regions);
  const project = select('Project', 'Do not link to a project', campaigns.map((c) => ({ id: c.value, name: c.label })));
  const out = h('div', { class: 'stack sm' });
  const check = h('button', { class: 'btn', type: 'button' }, 'Check first');
  const add = h('button', { class: 'btn primary', type: 'button' }, 'Add outlets');

  const rows = () => {
    const lines = [...new Set(names.value.split(/\r?\n/).map((l) => l.trim()).filter(Boolean))];
    const p = place.names();
    return lines.map((name) => ({ name, type: type.value, channel: chain.value ? chain.selectedOptions[0].textContent : undefined, distributor: dist.value ? dist.selectedOptions[0].textContent : undefined,
      region: p.region || undefined, locality: p.locality || undefined, area: p.area || undefined }));
  };
  async function send(dry) {
    const list = rows();
    if (!list.length) { out.replaceChildren(h('div', { class: 'alert warn' }, icon('alert'), h('div', null, 'Type or paste at least one outlet name.'))); return; }
    if (list.length > 500) { out.replaceChildren(h('div', { class: 'alert warn' }, icon('alert'), h('div', null, 'Up to 500 outlets at a time.'))); return; }
    check.disabled = add.disabled = true;
    try {
      const res = (await api.post('locations/import', { rows: list, campaign_id: project.value || undefined, dry_run: dry })).data;
      out.replaceChildren(
        h('div', { class: dry ? 'alert info' : 'alert ok' }, icon(dry ? 'info' : 'checkCircle'), h('div', null, h('strong', null, dry ? 'Checked, nothing saved yet. ' : 'Done. '),
          `${res.created} new, ${res.linked_existing} already existed (reused), ${res.rejected} rejected.`, !dry && res.created ? ' The new outlets have no map pin yet: set pins from the list (filter "Not mapped").' : '')),
        res.errors.length > 0 && h('ul', null, res.errors.slice(0, 20).map((e) => h('li', null, `${e.name || `Row ${e.row}`}: ${e.messages.join(' ')}`))));
      if (!dry) { invalidateLookups(); onDone?.(); if (!res.rejected) { setTimeout(() => modal.close(), 900); } }
    } catch (e) { toastError(e); }
    check.disabled = add.disabled = false;
  }
  check.addEventListener('click', () => send(true));
  add.addEventListener('click', () => send(false));
  const modal = openModal({
    title: 'Quick create outlets', size: 'lg',
    body: h('div', { class: 'stack' }, h('p', { class: 'muted small' }, 'Add many outlets that share the same chain, type and place. Paste the names; fix pins and details afterwards.'),
      field('Outlet names', names), h('div', { class: 'form-grid' }, field('Chain', chain), field('Type', type), field('Distributor', dist), field('Link to project', project)), place.el, out),
    footer: (close) => [h('button', { class: 'btn', type: 'button', onclick: () => close() }, 'Close'), check, add],
  });
}

export async function openBulkChange({ ids, onDone }) {
  const [channels, types, statuses, distributors, territories, regions] = await Promise.all([
    listOf('channels', { status: 'active' }).catch(() => []), listOf('outlet-types', { status: 'active' }).catch(() => []), listOf('outlet-statuses').catch(() => []),
    listOf('distributors', { status: 'active' }).catch(() => []), lookup('territories').catch(() => []), listOf('geo-areas', { level: 'region' }).catch(() => []),
  ]);
  const place = placePicker(regions);
  const WHAT = {
    channel_id: { label: 'Chain', control: () => select('Chain', 'Independent / none (clear)', channels), value: (c) => c.value || null },
    type: { label: 'Type', control: () => h('select', { class: 'select', 'aria-label': 'Type' }, ...types.map((t) => h('option', { value: t.slug }, t.name))), value: (c) => c.value, note: 'Sub-types are cleared because they belong to one type.' },
    outlet_status_id: { label: 'Status', control: () => h('select', { class: 'select', 'aria-label': 'Status' }, ...statuses.map((s) => h('option', { value: s.id }, s.name))), value: (c) => c.value },
    distributor_id: { label: 'Distributor', control: () => select('Distributor', 'None (clear)', distributors), value: (c) => c.value || null },
    territory_id: { label: 'Sales territory', control: () => select('Territory', 'None (clear)', territories.map((t) => ({ id: t.value, name: t.label }))), value: (c) => c.value || null },
    geo_area_id: { label: 'Place (region / locality / area)', control: () => place.el, value: () => place.areaId(), custom: true },
  };
  const what = h('select', { class: 'select', 'aria-label': 'What to change' }, ...Object.entries(WHAT).map(([k, w]) => h('option', { value: k }, w.label)));
  const host = h('div', { class: 'stack sm' });
  let current;
  const paint = () => { const w = WHAT[what.value]; current = w.control(); host.replaceChildren(w.custom ? current : field(w.label, current), w.note && h('p', { class: 'muted small' }, w.note)); };
  what.addEventListener('change', paint); paint();
  const apply = h('button', { class: 'btn primary', type: 'button' }, `Change ${ids.length} outlet${ids.length === 1 ? '' : 's'}`);
  const modal = openModal({
    title: `Change ${ids.length} outlet${ids.length === 1 ? '' : 's'}`, size: 'sm',
    body: h('div', { class: 'stack' }, field('What do you want to change?', what), host),
    footer: (close) => [h('button', { class: 'btn', type: 'button', onclick: () => close() }, 'Cancel'), apply],
  });
  apply.addEventListener('click', async () => {
    const key = what.value;
    const value = WHAT[key].value(current);
    if (key === 'geo_area_id' && !value) { toast('Choose at least a region.', 'error'); return; }
    apply.disabled = true;
    try {
      const res = (await api.post('locations/batch', { ids, changes: { [key]: value } })).data;
      toast(`Updated ${res.updated} outlet(s).`); invalidateLookups(); modal.close(); onDone?.();
    } catch (e) { toastError(e); apply.disabled = false; }
  });
}
