import { h } from '../core/dom.js';
import { api } from '../core/api.js';
import { enumOptions } from '../core/lookups.js';

/* "How does this project run?" — one shared piece of UI used when creating/editing a campaign and when editing a template.
   The administrator picks a project type (template) and the few switches below fill themselves in; every switch can still be
   changed for this one project. The server stores the result on the campaign and the phone app follows it. */

export const TASKS = [
  { value: 'form', label: 'Visit form' }, { value: 'sales', label: 'Sales (sales reps, foot soldiers)' }, { value: 'osa', label: 'On-shelf availability' },
  { value: 'merchandising', label: 'Merchandising' }, { value: 'photos', label: 'Photos & evidence' },
];
export const SOURCES = [
  { value: 'journey_plan', label: 'Fixed journey plan' }, { value: 'pre_mapped', label: 'Set up and mapped on the web' },
  { value: 'agent_adds', label: 'Added by agents in the field' }, { value: 'mixed', label: 'A mix of office and field' },
];

export const CAPTURE = [
  { value: 'office_only', label: 'Office sets every position (agents cannot create one)' },
  { value: 'first_visit', label: 'First visit maps an outlet that has no position' },
];

export const loadTemplates = async () => (await api.get('project-templates', { per_page: 100, sort: 'created_at' })).data;

/** Form fields. Pass withTemplate:false when editing a template itself. Each field sits on a tab. */
export async function setupFields(templates, { withTemplate = true, required = false } = {}) {
  const types = await enumOptions('location_types').catch(() => []);
  const d = fromSettings();
  const RUN = 'How it runs'; const GOALS = 'Targets';
  const pct = (name, label, help) => ({ name, label, type: 'decimal', min: 0, max: 100, tab: GOALS, help });
  return [
    ...(withTemplate ? [{ name: 'template_id', label: 'Project type', type: 'select', required, options: templates.map((t) => ({ value: t.id, label: t.name })), blank: '— Choose a project type —', full: true, tab: RUN,
      help: 'Picks sensible defaults for everything on this tab and the Targets tab. You can still change any of them for this project.' }] : []),
    { name: 's_outlet_source', label: 'Where do outlets come from?', type: 'select', blank: false, options: SOURCES, tab: RUN },
    { name: 's_outlet_type', label: 'Default outlet type for outlets agents add', type: 'select', blank: false, options: types, tab: RUN, help: 'The list comes from Outlets > Outlet setup.' },
    { name: 's_capture_geo', label: 'Where do outlet positions come from?', type: 'select', blank: false, options: CAPTURE, full: true, tab: RUN,
      help: 'Setting positions on the web stops agents from entering a wrong or made-up location.' },
    { name: 's_journey_plan', label: 'Permanent agents follow a Fixed Journey Plan (visits are created for them automatically)', type: 'checkbox', full: true, tab: RUN },
    { name: 's_tasks', label: 'What does the agent do at an outlet?', type: 'multiselect', options: TASKS, tab: RUN },
    { name: 's_min_photos', label: 'Photos needed per visit', type: 'number', min: 0, max: 50, tab: RUN },
    { name: 's_visit_photos', label: 'Agent must take a check-in photo and a check-out photo at every outlet', type: 'checkbox', full: true, tab: RUN },
    { name: 's_visit_label', label: 'The app calls a visit', maxlength: 40, help: 'e.g. Visit, Activation, Roadshow stop.', tab: RUN },
    pct('s_t_osa', 'On-shelf availability target (%)', 'Reports show each result against this. Leave empty for no target.'),
    pct('s_t_sos', 'Share of shelf target (%)', 'Used as the expected share when a product has no target of its own.'),
    pct('s_t_coverage', 'Outlet coverage target (%)', 'Share of the project\'s outlets that should be visited in a month.'),
    { name: 's_t_freq', label: 'Visits per outlet each month', type: 'decimal', min: 0, max: 100, tab: GOALS, help: 'For example 4 means about one visit a week to every outlet.' },
  ].map((f) => (f.name in d ? { ...f, value: d[f.name] } : f));
}

export const fromSettings = (s = {}) => ({
  s_outlet_source: s.outlet_source ?? 'mixed', s_journey_plan: !!s.journey_plan,
  s_capture_geo: s.capture_geo ?? 'office_only',
  s_t_osa: s.targets?.osa_pct ?? '', s_t_sos: s.targets?.sos_pct ?? '', s_t_coverage: s.targets?.coverage_pct ?? '', s_t_freq: s.targets?.visits_per_outlet ?? '',
  s_tasks: Object.entries(s.tasks ?? { form: true, sales: false, osa: true, merchandising: true, photos: true }).filter(([, on]) => on).map(([k]) => k),
  s_visit_photos: s.visit_photos ?? true, s_min_photos: s.min_photos ?? 0, s_visit_label: s.visit_label ?? 'Visit', s_outlet_type: s.outlet_type ?? 'retail_store',
});

export const toSettings = (v) => ({
  outlet_source: v.s_outlet_source, capture_geo: v.s_capture_geo, review: 'exceptions', journey_plan: !!v.s_journey_plan,
  targets: { osa_pct: v.s_t_osa ?? null, sos_pct: v.s_t_sos ?? null, coverage_pct: v.s_t_coverage ?? null, visits_per_outlet: v.s_t_freq ?? null },
  tasks: Object.fromEntries(TASKS.map((t) => [t.value, (v.s_tasks || []).includes(t.value)])),
  visit_photos: !!v.s_visit_photos, min_photos: v.s_min_photos ?? 0, visit_label: v.s_visit_label || 'Visit', outlet_type: v.s_outlet_type,
});

/** Splits flat form values back into the API body: ordinary fields + template_id + settings. */
export function toBody(v) {
  const body = {};
  Object.entries(v).forEach(([k, val]) => { if (!k.startsWith('s_') && k !== 'template_id') body[k] = val; });
  if (v.template_id) body.template_id = v.template_id;
  body.settings = toSettings(v);
  return body;
}

/** When a project type is chosen, fill the switches from it (and show what it is for). */
export function wireTemplate(form, templates) {
  const sel = form.control('template_id');
  if (!sel) return;
  const note = h('div', { class: 'help', style: { fontWeight: 600 } });
  sel.closest('.field')?.append(note);
  const apply = () => {
    const t = templates.find((x) => x.id === sel.value);
    note.textContent = t?.description || '';
    if (!t) return;
    Object.entries(fromSettings(t.settings)).forEach(([k, v]) => form.set(k, v));
  };
  sel.addEventListener('change', apply);
}

/** Short chips describing a setup (campaign overview, template list). */
export function settingsSummary(s = {}) {
  const on = TASKS.filter((t) => s.tasks?.[t.value]).map((t) => t.label);
  return h('div', { class: 'row', style: { flexWrap: 'wrap', gap: '6px' } },
    h('span', { class: 'badge neutral' }, SOURCES.find((x) => x.value === s.outlet_source)?.label || 'Mixed'),
    s.journey_plan && h('span', { class: 'badge info' }, 'Fixed journey plans'),
    h('span', { class: 'badge neutral' }, s.capture_geo === 'first_visit' ? 'Agents may map outlets' : 'Office sets positions'),
    ...on.map((t) => h('span', { class: 'badge' }, t)),
    h('span', { class: 'badge neutral' }, `${s.min_photos ?? 0} photo${s.min_photos === 1 ? '' : 's'} min`),
    ...targetChips(s.targets));
}

const TARGET_LABELS = [['osa_pct', 'OSA', '%'], ['sos_pct', 'Shelf share', '%'], ['coverage_pct', 'Coverage', '%'], ['visits_per_outlet', 'Visits per outlet', '/month']];
/** Small chips for the goals that are set ("OSA target 90%"). Nothing when none is set. */
export const targetChips = (t = {}) => TARGET_LABELS.filter(([k]) => t?.[k] != null).map(([k, label, unit]) => h('span', { class: 'badge info' }, `${label} target ${t[k]}${unit}`));
