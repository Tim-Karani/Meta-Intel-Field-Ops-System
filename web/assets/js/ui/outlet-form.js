import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api } from '../core/api.js';
import { lookup, invalidateLookups } from '../core/lookups.js';
import { buildForm } from './form.js';
import { openModal, toast, toastError } from './feedback.js';
import { geoPane } from './geo-picker.js';

/* The outlet panel: one side panel for creating and editing an outlet. The map stays at the top the whole time, so the
   person sees WHERE the outlet is while describing WHAT it is. Search the shop, confirm the pin, then fill in the rest. */

const listOf = async (path, params = {}) => (await api.get(path, { per_page: 100, ...params })).data;
const opt = (rows, label = (r) => r.name) => rows.map((r) => ({ value: r.id, label: label(r) }));
const merge = (...o) => Object.assign({}, ...o);

/** Region > locality > area chain of an outlet's place, oldest first. */
async function placeChain(areaId) {
  const chain = [];
  let id = areaId;
  for (let i = 0; id && i < 4; i++) {
    const a = (await api.get(`geo-areas/${id}`)).data;
    chain.unshift(a);
    id = a.parent_id;
  }
  return chain;
}

/** A one-field dialog to add a chain, distributor or sub-type without leaving the outlet panel. Calls onDone(newRecord). */
function quickAdd({ title, endpoint, label, extra = {}, onDone }) {
  const name = h('input', { class: 'input', type: 'text', maxlength: 255, 'aria-label': label, placeholder: label });
  const err = h('div', { class: 'err', role: 'alert' });
  const ok = h('button', { class: 'btn primary', type: 'button' }, 'Add');
  const m = openModal({ title, size: 'sm', body: h('div', { class: 'stack sm' }, name, err), footer: (close) => [h('button', { class: 'btn', type: 'button', onclick: () => close() }, 'Cancel'), ok] });
  const go = async () => {
    if (!name.value.trim()) { err.textContent = 'Enter a name.'; return; }
    ok.disabled = true; err.textContent = '';
    try { const res = await api.post(endpoint, { name: name.value.trim(), ...extra }); invalidateLookups(); m.close(); onDone(res.data); toast('Added.'); }
    catch (e) { err.textContent = e.errors?.name?.[0] || e.message || 'Could not add.'; ok.disabled = false; }
  };
  ok.addEventListener('click', go);
  name.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); go(); } });
  setTimeout(() => name.focus(), 50);
}
const plus = (text, onclick) => h('button', { class: 'btn sm ghost', type: 'button', style: { marginTop: '4px' }, onclick }, icon('plus', { size: 13 }), text);

/**
 * Create or edit an outlet. With `draft: true` nothing is saved: the filled-in details are handed to `onDraft(row)` as a row
 * the outlet import understands (names, not ids), so a list of outlets can be prepared before a project exists.
 */
export async function openOutletForm({ location = null, defaults = {}, onSaved, draft = false, onDraft } = {}) {
  const editing = !!location && !draft;
  const [channels, types, statuses, distributors, territories, regions] = await Promise.all([
    lookup('channels').catch(() => []), listOf('outlet-types', { status: 'active' }).catch(() => []), listOf('outlet-statuses').catch(() => []),
    lookup('distributors').catch(() => []), lookup('territories').catch(() => []), listOf('geo-areas', { level: 'region' }).catch(() => []),
  ]);
  const typeBySlug = Object.fromEntries(types.map((t) => [t.slug, t]));
  const countries = new Set(regions.map((r) => r.parent?.name).filter(Boolean));
  const v = merge(defaults, location || {});
  const chain = v.geo_area_id ? await placeChain(v.geo_area_id).catch(() => []) : [];
  const at = (level) => chain.find((a) => a.level === level)?.id || '';

  /* ---- map ---- */
  const pane = geoPane({ lat: v.latitude != null ? Number(v.latitude) : undefined, lng: v.longitude != null ? Number(v.longitude) : undefined, searchText: [v.name, v.town].filter(Boolean).join(' '), height: 300,
    onChange: (pos, place) => { if (place && !about.read().name && place.name) about.set('name', place.name); if (place && !where.read().address && place.address) where.set('address', place.address); } });

  /* ---- sections ---- */
  const slugs = types.map((t) => ({ value: t.slug, label: t.name }));
  const about = buildForm([
    { name: 'name', label: 'Outlet name', required: true, maxlength: 255, full: true },
    { name: 'channel_id', label: 'Chain', type: 'select', options: channels, blank: 'Independent / none', help: 'Reports group outlets by chain.',
      after: ({ set }) => plus('New chain', () => quickAdd({ title: 'New chain', endpoint: 'channels', label: 'Chain name', onDone: async (c) => { about.setOptions('channel_id', opt(await listOf('channels'))); set('channel_id', c.id); } })) },
    { name: 'type', label: 'Type', type: 'select', required: true, options: slugs, blank: false, value: v.type || slugs[0]?.value },
    { name: 'outlet_subtype_id', label: 'Sub-type', type: 'select', options: [], blank: 'None',
      after: () => plus('New sub-type', () => { const t = typeBySlug[about.read().type]; if (!t) return; quickAdd({ title: `New ${t.name} sub-type`, endpoint: 'outlet-subtypes', label: 'Sub-type name', extra: { outlet_type_id: t.id }, onDone: (r) => loadSubtypes(r.id) }); }) },
    { name: 'setting', label: 'Setting', type: 'select', options: [{ value: 'urban', label: 'Urban' }, { value: 'peri_urban', label: 'Peri-urban' }, { value: 'rural', label: 'Rural' }], blank: 'Not set' },
    { name: 'code', label: 'Outlet code', maxlength: 40, help: 'Your own reference. Must be unique.' }, { name: 'shop_no', label: 'Shop no.', maxlength: 60 },
    { name: 'market_name', label: 'Mall / market name', full: true },
  ], v);
  const loadSubtypes = async (keep = '') => {
    const t = typeBySlug[about.read().type];
    const rows = t ? await listOf('outlet-subtypes', { outlet_type_id: t.id, status: 'active' }).catch(() => []) : [];
    about.setOptions('outlet_subtype_id', opt(rows));
    about.set('outlet_subtype_id', rows.some((r) => r.id === keep) ? keep : '');
  };
  about.control('type').addEventListener('change', () => loadSubtypes());
  loadSubtypes(v.outlet_subtype_id || '');
  about.control('name').addEventListener('blur', () => pane.setSearchText([about.read().name, where.read().town].filter(Boolean).join(' ')));

  const where = buildForm([
    { name: 'g_region', label: 'Region', type: 'select', options: opt(regions, (r) => (countries.size > 1 && r.parent?.name ? `${r.name} (${r.parent.name})` : r.name)), blank: regions.length ? '— Choose —' : 'No regions set up yet', value: at('region') },
    { name: 'g_locality', label: 'Locality', type: 'select', options: [], blank: '— Choose a region first —', value: at('locality') },
    { name: 'g_area', label: 'Area / neighbourhood', type: 'select', options: [], blank: '— Choose a locality first —', value: at('area') },
    { name: 'territory_id', label: 'Sales territory', type: 'select', options: territories, blank: 'None' },
    { name: 'county', label: 'County' },
    { name: 'address', label: 'Street / landmark', full: true, placeholder: 'e.g. Door 3, behind the main entrance' },
  ], v);
  const cascade = async (parentName, childName, level, blankText, keep = '') => {
    const parent = where.read()[parentName];
    const rows = parent ? await listOf('geo-areas', { level, parent_id: parent }).catch(() => []) : [];
    where.setOptions(childName, opt(rows));
    where.set(childName, rows.some((r) => r.id === keep) ? keep : '');
    where.control(childName).options[0].textContent = parent ? (rows.length ? '— Choose —' : `Nothing under this ${parentName === 'g_region' ? 'region' : 'locality'} yet`) : blankText;
  };
  where.control('g_region').addEventListener('change', async () => { await cascade('g_region', 'g_locality', 'locality', '— Choose a region first —'); await cascade('g_locality', 'g_area', 'area', '— Choose a locality first —'); });
  where.control('g_locality').addEventListener('change', () => cascade('g_locality', 'g_area', 'area', '— Choose a locality first —'));
  (async () => { await cascade('g_region', 'g_locality', 'locality', '— Choose a region first —', at('locality')); await cascade('g_locality', 'g_area', 'area', '— Choose a locality first —', at('area')); })();

  const defaultStatus = statuses.find((s) => s.is_default)?.id;
  const trade = buildForm([
    { name: 'distributor_id', label: 'Distributor', type: 'select', options: distributors, blank: 'None',
      after: ({ set }) => plus('New distributor', () => quickAdd({ title: 'New distributor', endpoint: 'distributors', label: 'Distributor name', onDone: async (d) => { trade.setOptions('distributor_id', opt(await listOf('distributors', { status: 'active' }))); set('distributor_id', d.id); } })) },
    { name: 'outlet_status_id', label: 'Status', type: 'select', options: opt(statuses), blank: false, value: v.outlet_status_id || defaultStatus, help: 'Statuses that do not count as active take the outlet out of planning.' },
    { name: 'phone', label: 'Shop phone' }, { name: 'email', label: 'Shop email', type: 'email' },
    { name: 'contact_name', label: 'Contact person' }, { name: 'contact_phone', label: 'Contact phone' },
    { name: 'geofence_radius_m', label: 'Check-in radius (m)', type: 'number', min: 10, max: 5000, value: v.geofence_radius_m ?? 100, help: 'Agents must be within this distance of the pin to check in.' },
    { name: 'opening_hours', label: 'Opening hours' },
    { name: 'notes', label: 'Notes', type: 'textarea', full: true },
  ], v);

  const section = (n, title, hint, body) => h('section', { class: 'op-section' }, h('div', { class: 'op-head' }, h('span', { class: 'op-num' }, String(n)), h('div', null, h('strong', null, title), hint && h('div', { class: 'small muted' }, hint))), body);
  const banner = h('div', { class: 'hidden', role: 'alert' });
  const again = h('input', { type: 'checkbox', id: 'op-again' });
  const saveBtn = h('button', { class: 'btn primary', type: 'button' }, editing || (draft && location) ? 'Save changes' : draft ? 'Add to list' : 'Save outlet');

  const body = h('div', { class: 'stack op-panel' }, banner,
    section(1, 'Find it on the map', 'Search the shop by name, click the map, or paste coordinates.', pane.el),
    section(2, 'About the outlet', null, about.el),
    section(3, 'Where it is', 'Pick from the geography list so reports roll up correctly.', where.el),
    section(4, 'Contact and trade', null, trade.el));

  const forms = [about, where, trade];
  const modal = openModal({
    title: editing ? `Edit ${location.name}` : location ? `Edit ${location.name}` : 'New outlet', drawer: true, size: 'wide', body,
    footer: (close) => [!location && h('label', { class: 'check', for: 'op-again', style: { marginRight: 'auto' } }, again, 'Add another after saving'), h('button', { class: 'btn', type: 'button', onclick: () => close() }, 'Cancel'), saveBtn],
  });

  function payload() {
    const out = merge(...forms.map((f) => f.read()));
    const area = out.g_area || out.g_locality || out.g_region || null;
    delete out.g_region; delete out.g_locality; delete out.g_area;
    out.geo_area_id = area;
    const p = pane.getPos();
    if (p) { out.latitude = Number(p.lat.toFixed(7)); out.longitude = Number(p.lng.toFixed(7)); }
    if (out.geofence_radius_m == null) delete out.geofence_radius_m;
    if (!editing) for (const k of Object.keys(out)) if (out[k] === null) delete out[k];
    return out;
  }

  /** The filled-in panel as an outlet-import row (names, not ids) plus the raw values so it can be reopened. */
  const text = (form, name) => { const el = form.control(name); return el && el.value ? (name === 'g_region' ? el.selectedOptions[0].textContent.replace(/\s*\([^)]*\)$/, '') : el.selectedOptions[0].textContent) : null; };
  function draftRow() {
    const p = payload(); const a = about.read(); const w = where.read(); const t = trade.read();
    const row = {
      name: a.name, code: a.code, type: a.type, channel: text(about, 'channel_id'), subtype: text(about, 'outlet_subtype_id'), setting: a.setting, shop_no: a.shop_no, market_name: a.market_name,
      region: text(where, 'g_region'), locality: text(where, 'g_locality'), area: text(where, 'g_area'), territory: text(where, 'territory_id'), county: w.county, address: w.address,
      distributor: text(trade, 'distributor_id'), status: text(trade, 'outlet_status_id'), phone: t.phone, email: t.email, contact_name: t.contact_name, contact_phone: t.contact_phone,
      geofence_radius_m: t.geofence_radius_m, opening_hours: t.opening_hours, notes: t.notes, latitude: p.latitude, longitude: p.longitude,
    };
    Object.keys(row).forEach((k) => { if (row[k] === null || row[k] === undefined || row[k] === '') delete row[k]; });
    return { ...row, _form: { ...p } };
  }

  saveBtn.addEventListener('click', async () => {
    forms.forEach((f) => f.clearErrors()); banner.className = 'hidden';
    if (draft) {
      if (!about.read().name) { about.showErrors({ errors: { name: ['Enter the outlet name.'] } }); return; }
      onDraft?.(draftRow());
      if (!location && again.checked) { modal.close(); openOutletForm({ draft: true, onDraft, defaults: { channel_id: payload().channel_id, type: payload().type, distributor_id: payload().distributor_id } }); return; }
      modal.close(); return;
    }
    saveBtn.disabled = true; const label = saveBtn.textContent; saveBtn.replaceChildren(h('span', { class: 'spinner' }), ' Saving…');
    try {
      const res = editing ? await api.patch(`locations/${location.id}`, payload()) : await api.post('locations', payload());
      invalidateLookups();
      toast(editing ? 'Outlet updated.' : 'Outlet added.');
      onSaved?.(res.data);
      if (!editing && again.checked) { modal.close(); openOutletForm({ defaults: { channel_id: payload().channel_id, type: payload().type, distributor_id: payload().distributor_id }, onSaved }); return; }
      modal.close();
    } catch (err) {
      const shown = forms.map((f) => f.showErrors(err)).some(Boolean);
      if (!shown || err.status !== 422) { banner.className = 'alert error'; banner.replaceChildren(icon('alert'), h('div', null, err.message || 'Could not save.')); body.scrollIntoView?.({ block: 'start' }); }
      if (err.errors?.latitude || err.errors?.longitude) toastError(err);
    } finally { saveBtn.disabled = false; saveBtn.textContent = label; }
  });
  return modal;
}
