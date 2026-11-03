import { h } from '../core/dom.js';
import { crudPage } from '../ui/crud.js';
import { badge, tabs, pageHead } from '../ui/feedback.js';
import { api } from '../core/api.js';
import { href } from '../core/router.js';

/* Outlet setup: the lists the office keeps tidy once, and every outlet form, import and report then uses them. */

const PERMS = { create: 'locations.manage', edit: 'locations.manage', delete: 'locations.manage' };
const STATUS = ['active', 'inactive'];
const listOf = async (path, params = {}) => (await api.get(path, { per_page: 100, ...params })).data;
const LEVELS = [{ value: 'country', label: 'Country' }, { value: 'region', label: 'Region' }, { value: 'locality', label: 'Locality' }, { value: 'area', label: 'Area' }];
const TONES = [{ value: 'ok', label: 'Green' }, { value: 'warn', label: 'Amber' }, { value: 'danger', label: 'Red' }, { value: 'info', label: 'Blue' }, { value: 'neutral', label: 'Grey' }];
const toneOf = (t) => (t === 'neutral' ? undefined : t);

export default async function render({ container }) {
  const chains = () => crudPage({
    embedded: true, title: 'Chains', noun: 'chain', endpoint: 'channels', perms: PERMS, defaultSort: 'name', searchPlaceholder: 'Search chain or group…',
    intro: 'A chain is a retail group such as a supermarket brand. The channel group is the wider family, e.g. Modern trade.',
    fields: () => [
      { name: 'name', label: 'Chain name', required: true }, { name: 'channel_group', label: 'Channel group', help: 'Optional, e.g. Modern trade, Wholesale, Independents.' },
      { name: 'code', label: 'Code', maxlength: 40 }, { name: 'contact_name', label: 'Head-office contact' }, { name: 'contact_phone', label: 'Contact phone' },
      { name: 'status', label: 'Status', type: 'select', options: STATUS, blank: false, value: 'active' }, { name: 'notes', label: 'Notes', type: 'textarea', full: true },
    ],
    filters: [{ key: 'status', label: 'All statuses', options: STATUS }],
    columns: [
      { key: 'name', label: 'Chain', sort: 'name', render: (r) => h('strong', null, r.name) }, { key: 'channel_group', label: 'Channel group', sort: 'channel_group', render: (r) => r.channel_group || '—' },
      { key: 'locations_count', label: 'Outlets', align: 'right', render: (r) => h('a', { href: href(`/locations?channel_id=${r.id}`) }, String(r.locations_count ?? 0)) }, { key: 'status', label: 'Status', render: (r) => badge(r.status) },
    ],
  });

  const typesTab = () => {
    const types = listOf('outlet-types').catch(() => []);
    const wrap = h('div', { class: 'stack' });
    wrap.append(
      crudPage({
        embedded: true, title: 'Outlet types', noun: 'outlet type', endpoint: 'outlet-types', perms: PERMS, defaultSort: 'name',
        intro: 'Types describe what kind of outlet it is, e.g. Supermarket or Kiosk. Sub-types refine them.',
        fields: () => [{ name: 'name', label: 'Type name', required: true }, { name: 'status', label: 'Status', type: 'select', options: STATUS, blank: false, value: 'active' }],
        columns: [{ key: 'name', label: 'Type', sort: 'name', render: (r) => h('strong', null, r.name) }, { key: 'subtypes_count', label: 'Sub-types', align: 'right' }, { key: 'outlets_count', label: 'Outlets', align: 'right' }, { key: 'status', label: 'Status', render: (r) => badge(r.status) }],
      }),
      crudPage({
        embedded: true, title: 'Sub-types', noun: 'sub-type', endpoint: 'outlet-subtypes', perms: PERMS, defaultSort: 'name',
        fields: () => [{ name: 'outlet_type_id', label: 'Belongs to type', type: 'select', required: true, options: [] }, { name: 'name', label: 'Sub-type name', required: true }, { name: 'status', label: 'Status', type: 'select', options: STATUS, blank: false, value: 'active' }],
        onForm: async (form, rec) => { form.setOptions('outlet_type_id', (await listOf('outlet-types').catch(() => [])).map((t) => ({ value: t.id, label: t.name }))); form.set('outlet_type_id', rec?.outlet_type_id || ''); },
        filters: [{ key: 'outlet_type_id', label: 'All types', loadOptions: async () => (await types).map((t) => ({ value: t.id, label: t.name })) }],
        columns: [{ key: 'name', label: 'Sub-type', sort: 'name', render: (r) => h('strong', null, r.name) }, { key: 'type', label: 'Type', render: (r) => r.type?.name || '—' }, { key: 'status', label: 'Status', render: (r) => badge(r.status) }],
      }));
    return wrap;
  };

  const statuses = () => crudPage({
    embedded: true, title: 'Outlet statuses', noun: 'status', endpoint: 'outlet-statuses', perms: PERMS, defaultSort: 'created_at',
    intro: 'A status says where an outlet stands. Statuses that do not count as active take the outlet out of planning and visits. One status is the default for new outlets.',
    fields: () => [
      { name: 'name', label: 'Status name', required: true, maxlength: 80 }, { name: 'tone', label: 'Colour', type: 'select', options: TONES, blank: false, value: 'neutral' },
      { name: 'counts_as_active', label: 'Counts as active (can be planned and visited)', type: 'checkbox', full: true, value: true },
      { name: 'is_default', label: 'Default for new outlets', type: 'checkbox', full: true },
    ],
    toPayload: (v) => ({ ...v, counts_as_active: !!v.counts_as_active, is_default: !!v.is_default }),
    columns: [
      { key: 'name', label: 'Status', render: (r) => badge(r.name, { tone: toneOf(r.tone) }) },
      { key: 'counts_as_active', label: 'Active?', render: (r) => (r.counts_as_active ? 'Yes' : 'No') }, { key: 'is_default', label: 'Default', render: (r) => (r.is_default ? badge('default', { tone: 'info' }) : '') },
      { key: 'outlets_count', label: 'Outlets', align: 'right' },
    ],
  });

  const distributors = () => crudPage({
    embedded: true, title: 'Distributors', noun: 'distributor', endpoint: 'distributors', perms: PERMS, defaultSort: 'name', searchPlaceholder: 'Search distributor…',
    fields: () => [
      { name: 'name', label: 'Distributor name', required: true, full: true }, { name: 'code', label: 'Code', maxlength: 40 }, { name: 'contact_name', label: 'Contact person' },
      { name: 'phone', label: 'Phone' }, { name: 'email', label: 'Email', type: 'email' }, { name: 'status', label: 'Status', type: 'select', options: STATUS, blank: false, value: 'active' }, { name: 'notes', label: 'Notes', type: 'textarea', full: true },
    ],
    filters: [{ key: 'status', label: 'All statuses', options: STATUS }],
    columns: [
      { key: 'name', label: 'Distributor', sort: 'name', render: (r) => h('strong', null, r.name) }, { key: 'contact', label: 'Contact', render: (r) => [r.contact_name, r.phone].filter(Boolean).join(' · ') || '—' },
      { key: 'outlets_count', label: 'Outlets', align: 'right', render: (r) => h('a', { href: href(`/locations?distributor_id=${r.id}`) }, String(r.outlets_count ?? 0)) }, { key: 'status', label: 'Status', render: (r) => badge(r.status) },
    ],
  });

  const geography = () => {
    const parentOptions = async (level) => {
      const i = LEVELS.findIndex((l) => l.value === level);
      return i > 0 ? (await listOf('geo-areas', { level: LEVELS[i - 1].value }).catch(() => [])).map((a) => ({ value: a.id, label: a.name })) : [];
    };
    return crudPage({
      embedded: true, title: 'Geography', noun: 'place', endpoint: 'geo-areas', perms: PERMS, defaultSort: 'name', searchPlaceholder: 'Search place…',
      intro: 'Four levels: country, then region, locality and area. Add the places once; outlets pick from them and reports roll up by them. Start with a country, then its regions.',
      fields: () => [
        { name: 'level', label: 'Level', type: 'select', options: LEVELS, blank: false, value: 'region', required: true },
        { name: 'parent_id', label: 'Belongs to', type: 'select', options: [], blank: '— (countries have none) —' },
        { name: 'name', label: 'Name', required: true }, { name: 'code', label: 'Code', maxlength: 40 },
        { name: 'status', label: 'Status', type: 'select', options: STATUS, blank: false, value: 'active' },
      ],
      onForm: (form, rec) => {
        const refill = async () => { const level = form.read().level; const opts = await parentOptions(level); form.setOptions('parent_id', opts); form.set('parent_id', rec?.parent_id || ''); form.control('parent_id').disabled = level === 'country'; };
        form.control('level').addEventListener('change', () => { rec = { ...(rec || {}), parent_id: '' }; refill(); });
        refill();
      },
      toPayload: (v) => ({ ...v, parent_id: v.level === 'country' ? null : v.parent_id }),
      filters: [{ key: 'level', label: 'All levels', options: LEVELS }, { key: 'status', label: 'All statuses', options: STATUS }],
      columns: [
        { key: 'name', label: 'Place', sort: 'name', render: (r) => h('strong', null, r.name) }, { key: 'level', label: 'Level', sort: 'level', render: (r) => LEVELS.find((l) => l.value === r.level)?.label || r.level },
        { key: 'parent', label: 'Belongs to', render: (r) => r.parent?.name || '—' }, { key: 'status', label: 'Status', render: (r) => badge(r.status) },
      ],
    });
  };

  container.append(pageHead({ title: 'Outlet setup', subtitle: 'The lists behind your outlets: chains, types, statuses, distributors and places.', actions: h('a', { class: 'btn', href: href('/locations') }, 'Back to outlets') }),
    tabs([{ key: 'chains', label: 'Chains', render: chains }, { key: 'types', label: 'Types & sub-types', render: typesTab }, { key: 'statuses', label: 'Statuses', render: statuses }, { key: 'distributors', label: 'Distributors', render: distributors }, { key: 'geo', label: 'Geography', render: geography }]));
}
