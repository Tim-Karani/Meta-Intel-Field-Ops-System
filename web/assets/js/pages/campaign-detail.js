import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api } from '../core/api.js';
import { can } from '../core/auth.js';
import { fmtDate, fmtMoney, fmtNumber, titleCase, daysLeft } from '../core/format.js';
import { asyncView, badge, kv, card, pageHead, tabs, toast, toastError, confirmDialog } from '../ui/feedback.js';
import { dataTable, simpleTable } from '../ui/table.js';
import { formModal, crudPage } from '../ui/crud.js';
import { pickerModal } from '../ui/picker.js';
import { photoGrid } from '../ui/gallery.js';
import { href, navigate } from '../core/router.js';
import { lookup, enumOptions } from '../core/lookups.js';
import { campaignFields, loadSetup, campaignValues, campaignBody, wireTemplate } from './campaigns.js';
import { settingsSummary } from '../ui/project-setup.js';
import { activityFields } from './activities.js';
import { plansPanel } from './journey-plans.js';

const LINKS = [
  { rel: 'teams', label: 'Teams', entity: 'teams', hint: 'Teams working on this campaign.' },
  { rel: 'locations', label: 'Locations', entity: 'locations', hint: 'Outlets to visit. Locations with open assignments cannot be removed.' },
  { rel: 'products', label: 'Products', entity: 'products', hint: 'Products tracked in sales and OSA. Must belong to this client or be shared.', params: (c) => ({ client_id: c.client_id }) },
  { rel: 'forms', label: 'Forms', entity: 'forms', hint: 'Published forms agents can fill for this campaign.', params: () => ({ status: 'published' }) },
  { rel: 'users', label: 'Staff', entity: 'staff', hint: 'Managers, supervisors and agents with access to this campaign.' },
];

export default async function render({ container, params }) {
  const holder = h('div'); container.append(holder);
  let plans = null;
  asyncView(holder, async () => { const c = await api.get(`campaigns/${params.id}`).then((r) => r.data); plans = c.settings?.journey_plan ? await api.get('journey-plans', { campaign_id: c.id, per_page: 1 }).then((r) => r.meta?.total ?? 0).catch(() => null) : null; return c; }, (c, reload) => {
    const canEdit = can('campaigns.edit');
    const edit = canEdit && c.status !== 'archived' && h('button', { class: 'btn', type: 'button', onclick: async () => {
      const clients = await lookup('clients').catch(() => []);
      const { templates, setup } = await loadSetup(c);
      const { form } = formModal({ title: 'Edit campaign', fields: campaignFields(c, setup).map((f) => (f.name === 'client_id' ? { ...f, options: clients } : f)), values: campaignValues(c), size: 'lg', submitLabel: 'Save changes',
        onSubmit: async (v) => { const o = campaignBody(v); if (o.budget === null) delete o.budget; await api.patch(`campaigns/${c.id}`, o); toast('Campaign updated.'); }, onSaved: reload });
      wireTemplate(form, templates);
    } }, icon('edit', { size: 16 }), 'Edit');

    const transitions = canEdit ? (c.allowed_transitions || []).map((s) => h('button', { class: ['btn', s === 'archived' ? 'danger' : s === 'active' ? 'primary' : ''], type: 'button', onclick: () => transition(c, s, reload) }, TRANSITION_LABEL[s] || `Move to ${titleCase(s)}`)) : [];

    const canJp = !!c.settings?.journey_plan && (can('assignments.view') || can('assignments.create'));
    const sub = (items) => tabs(items.filter(Boolean));
    let ws; // the workspace tabs, so the checklist can jump between them
    const go = (key) => ws?.select(key);
    ws = tabs([
      { key: 'overview', label: 'Overview', render: () => overview(c, plans, go) },
      { key: 'setup', label: 'Setup', render: () => h('div', { class: 'stack' }, activitiesTab(c, reload), kpiTab(c)) },
      { key: 'outlets', label: 'Outlets & team', render: () => resourcesTab(c, reload, go) },
      canJp && { key: 'plans', label: 'Journey plans', render: (panel) => { const host = h('div', { class: 'stack' }); plansPanel({ container: host, campaigns: [{ value: c.id, label: c.name }], campaign: c }); return h('div', { class: 'stack' }, host, h('h3', { class: 'hub-title' }, 'Jobs given on a single date'), assignmentsTab(c)); } },
      !canJp && { key: 'plans', label: 'Assignments', render: () => assignmentsTab(c) },
      { key: 'results', label: 'Visits & results', render: () => sub([
        { key: 'visits', label: 'Visits', render: () => visitsTab(c) },
        { key: 'issues', label: 'Issues', render: () => issuesTab(c) },
        { key: 'evidence', label: 'Photos', render: () => evidenceTab(c) },
        can('reports.view') && { key: 'reports', label: 'Reports', render: () => reportsTab(c) },
      ]) },
    ].filter(Boolean));
    return h('div', null, pageHead({ title: c.name, subtitle: `${c.client?.name || ''} · ${c.code}`, actions: [badge(c.status), edit, ...transitions] }), ws);
  });
}

const TRANSITION_LABEL = { planned: 'Mark as planned', active: 'Activate', paused: 'Pause', completed: 'Complete', archived: 'Archive', draft: 'Back to draft' };

async function transition(c, status, reload) {
  const needsReason = ['paused', 'archived', 'draft'].includes(status);
  const res = await confirmDialog({
    title: `${TRANSITION_LABEL[status] || titleCase(status)}?`, danger: status === 'archived', confirmLabel: TRANSITION_LABEL[status] || 'Confirm',
    message: status === 'active' ? 'Agents will be able to start visits for this campaign.' : status === 'archived' ? 'Archived campaigns are read-only.' : `Change “${c.name}” from ${c.status} to ${status}.`,
    reason: { label: needsReason ? 'Reason' : 'Note (optional)', required: false },
  });
  if (!res) return;
  try { await api.post(`campaigns/${c.id}/transition`, { status, reason: res.reason || null }); toast(`Campaign is now ${status}.`); reload(); } catch (e) { toastError(e); }
}

function overview(c, plans, go) {
  const days = c.start_date && c.end_date ? Math.max(0, Math.round((new Date(c.end_date) - new Date(c.start_date)) / 86400000) + 1) : null;
  return h('div', { class: 'stack' },
    nextSteps(c, plans, go),
    h('div', { class: 'grid cols-4' },
      miniStat('Activities', c.activities?.length ?? 0), miniStat('Locations', c.locations?.length ?? 0), miniStat('Teams', c.teams?.length ?? 0), miniStat('Staff', c.users?.length ?? 0)),
    h('div', { class: 'grid cols-2' },
      card('Details', kv([['Client', c.client?.id ? h('a', { href: href(`/clients/${c.client.id}`) }, c.client.name) : c.client?.name], ['Code', h('span', { class: 'mono' }, c.code)], ['Category', c.campaign_type && titleCase(c.campaign_type)],
        ['Status', badge(c.status)], ['Dates', h('span', null, `${fmtDate(c.start_date)} → ${fmtDate(c.end_date)}${days ? ` (${days} days)` : ''}`, daysLeft(c) && h('span', { class: ['small', daysLeft(c).tone], style: { marginLeft: '8px' } }, daysLeft(c).text))], ['Budget', c.budget ? fmtMoney(c.budget) : null]])),
      card('Project setup', h('div', { class: 'stack sm' },
        h('div', null, h('strong', null, c.template?.name || 'Custom'), h('span', { class: 'muted small' }, ' · how outlets are added and what agents do')),
        settingsSummary(c.settings),
        c.settings?.journey_plan && can('assignments.view') && h('button', { class: 'btn sm', type: 'button', onclick: () => go('plans') }, 'Manage journey plans'))),
      card('Brief', h('div', { class: 'stack sm' },
        h('div', null, h('strong', null, 'Description'), h('p', { style: { whiteSpace: 'pre-wrap' } }, c.description || h('span', { class: 'muted' }, 'Not provided.'))),
        h('div', null, h('strong', null, 'Objectives'), h('p', { style: { whiteSpace: 'pre-wrap' } }, c.objectives || h('span', { class: 'muted' }, 'Not provided.')))))));
}
/** A plain checklist of what is left before the project can run, each with a button to the place to do it. */
function nextSteps(c, plans, go) {
  if (c.status === 'archived' || c.status === 'completed') return null;
  const jp = !!c.settings?.journey_plan;
  const steps = [
    { done: (c.activities?.length ?? 0) > 0, title: 'Add the activities', hint: 'What agents do on this project, for example Merchandising or Activation.', btn: 'Add activities', to: 'setup' },
    { done: (c.locations?.length ?? 0) > 0, title: 'Add the outlets', hint: 'Choose which outlets belong to this project.', btn: 'Add outlets', to: 'outlets' },
    { done: (c.users?.length ?? 0) > 0, title: 'Add the people', hint: 'Managers, team leaders and field agents who work on it.', btn: 'Add people', to: 'outlets' },
    jp && { done: (plans ?? 0) > 0, title: 'Plan each agent\'s week', hint: 'Choose each agent\'s outlets and the days they visit them.', btn: 'Plan journeys', to: 'plans' },
    { done: c.status === 'active', title: 'Activate the project', hint: 'Agents can only start visits once it is active (use the Activate button at the top).', btn: null },
  ].filter(Boolean);
  const left = steps.filter((x) => !x.done).length;
  if (!left) return null;
  return card(`Next steps · ${left} left`, h('div', { class: 'next-steps' }, steps.map((x, i) => h('div', { class: ['next-step', x.done && 'done'] },
    h('span', { class: 'tick' }, x.done ? '✓' : String(i + 1)), h('span', { class: 'tx' }, h('b', null, x.title), h('span', null, x.hint)),
    !x.done && x.btn && h('button', { class: 'btn sm', type: 'button', onclick: () => go(x.to) }, x.btn)))));
}
const miniStat = (label, value) => h('div', { class: 'card stat' }, h('div', { class: 'label' }, label), h('div', { class: 'value' }, fmtNumber(value)));

function activitiesTab(c, reload) {
  const canEdit = can('activities.edit') && c.status !== 'archived';
  const open = async (rec) => {
    const forms = await lookup('forms', { status: 'published' }).catch(() => []);
    const base = activityFields([{ value: c.id, label: c.name }], forms);
    formModal({ title: rec ? 'Edit activity' : 'New activity', fields: base.map((f) => (f.name === 'campaign_id' ? { ...f, disabled: true } : f)), values: rec ? { ...rec } : { campaign_id: c.id },
      onSubmit: async (v) => { const o = { ...v, campaign_id: c.id }; if (rec) await api.patch(`activities/${rec.id}`, o); else await api.post('activities', o); toast('Activity saved.'); }, onSaved: reload });
  };
  return card('Activities', simpleTable([
    { label: 'Activity', render: (r) => h('strong', null, r.name) }, { label: 'Type', render: (r) => (r.activity_type ? titleCase(r.activity_type) : '—') },
    { label: 'Target', align: 'right', render: (r) => fmtNumber(r.target) }, { label: 'Dates', render: (r) => `${fmtDate(r.start_date)} → ${fmtDate(r.end_date)}` }, { label: 'Status', render: (r) => badge(r.status) },
    canEdit && { label: '', align: 'right', render: (r) => h('button', { class: 'btn sm ghost', type: 'button', 'aria-label': `Edit ${r.name}`, onclick: () => open(r) }, icon('edit', { size: 15 })) },
  ].filter(Boolean), c.activities || [], { emptyText: 'No activities yet.' }), {
    flush: true, actions: can('activities.create') && c.status !== 'archived' && h('button', { class: 'btn sm primary', type: 'button', onclick: () => open(null) }, icon('plus', { size: 14 }), 'Add activity'),
  });
}

function resourcesTab(c, reload, go) {
  const canAssign = can('campaigns.assign') && c.status !== 'archived';
  return h('div', { class: 'stack' }, h('p', { class: 'muted' }, 'Who works on this project and where. To create new outlets, use ', h('a', { href: href('/locations') }, 'Outlets'), ' or ', h('a', { href: href('/onboarding') }, 'Start a project'), '; here you choose which ones belong to this project.'), h('div', { class: 'grid cols-2' }, LINKS.map((l) => {
    const items = c[l.rel] || [];
    const label = (i) => i.name + (l.rel === 'forms' && i.version ? ` (v${i.version})` : '') + (l.rel === 'locations' && i.town ? ` — ${i.town}` : '') + (l.rel === 'products' && i.sku ? ` · ${i.sku}` : '');
    return card(`${l.label} (${items.length})`, items.length
      ? h('div', { class: 'pill-row' }, items.slice(0, 60).map((i) => h('span', { class: 'badge' }, label(i))), items.length > 60 && h('span', { class: 'muted small' }, `+${items.length - 60} more`))
      : h('span', { class: 'muted' }, `No ${l.label.toLowerCase()} linked yet.`), {
      actions: canAssign && h('button', { class: 'btn sm', type: 'button', onclick: () => pickerModal({
        title: `Manage ${l.label.toLowerCase()}`, entity: l.entity, params: l.params?.(c) || {}, intro: l.hint, selected: items.map((i) => ({ value: i.id, label: i.name })),
        onSave: async (sel) => { await api.put(`campaigns/${c.id}/links/${l.rel}`, { ids: sel.map((s) => s.value) }); toast(`${l.label} updated.`); reload(); },
      }) }, icon('edit', { size: 14 }), 'Manage'),
    });
  })));
}

function kpiTab(c) {
  const canEdit = can('campaigns.edit') && c.status !== 'archived';
  const wrap = h('div');
  enumOptions('kpi_metrics').then((metrics) => wrap.append(crudPage({
    embedded: true, title: 'KPIs', noun: 'KPI', endpoint: 'kpis', query: { campaign_id: c.id }, defaultSort: 'name',
    perms: canEdit ? { create: 'campaigns.edit', edit: 'campaigns.edit', delete: 'campaigns.edit' } : {},
    fields: () => [{ name: 'name', label: 'KPI name', required: true }, { name: 'metric', label: 'Metric', type: 'select', required: true, options: metrics }, { name: 'target', label: 'Target', type: 'decimal', required: true, min: 0 }, { name: 'unit', label: 'Unit', maxlength: 20, help: 'e.g. visits, KES, %' }],
    toPayload: (v) => ({ ...v, campaign_id: c.id }), search: false,
    columns: [{ key: 'name', label: 'KPI', render: (r) => h('strong', null, r.name) }, { key: 'metric', label: 'Metric', render: (r) => titleCase(r.metric) }, { key: 'target', label: 'Target', align: 'right', render: (r) => `${fmtNumber(r.target, 2)} ${r.unit || ''}` }],
    emptyText: 'Define the targets this campaign is measured against. Actual results appear in the campaign performance report.',
  })).catch(() => {}));
  return wrap;
}

const assignmentCols = [
  { key: 'scheduled_date', label: 'Date', sort: 'scheduled_date', render: (r) => fmtDate(r.scheduled_date) },
  { key: 'agent', label: 'Agent', render: (r) => r.agent?.name || '—' },
  { key: 'location', label: 'Location', render: (r) => r.location?.name || '—' },
  { key: 'status', label: 'Status', render: (r) => badge(r.status) },
];
const assignmentsTab = (c) => dataTable({ endpoint: 'assignments', query: { campaign_id: c.id }, defaultSort: '-scheduled_date', search: false, columns: assignmentCols, emptyTitle: 'No assignments yet', emptyText: 'Create assignments from the Assignments page.' });
const visitsTab = (c) => dataTable({
  endpoint: 'visits', query: { campaign_id: c.id }, defaultSort: '-visit_date', search: false, emptyTitle: 'No visits yet',
  columns: [
    { key: 'visit_date', label: 'Date', sort: 'visit_date', render: (r) => fmtDate(r.visit_date) }, { key: 'agent', label: 'Agent', render: (r) => r.agent?.name || '—' },
    { key: 'location', label: 'Location', render: (r) => r.location?.name || '—' }, { key: 'gps', label: 'GPS', render: (r) => badge(r.gps_outcome) }, { key: 'status', label: 'Status', render: (r) => badge(r.status) },
  ], onRowClick: (r) => navigate(`/visits/${r.id}`),
});
const issuesTab = (c) => dataTable({
  endpoint: 'issues', query: { campaign_id: c.id }, defaultSort: '-created_at', search: false, emptyTitle: 'No issues reported',
  columns: [
    { key: 'title', label: 'Issue', render: (r) => h('strong', null, r.title) }, { key: 'priority', label: 'Priority', render: (r) => badge(r.priority) }, { key: 'status', label: 'Status', render: (r) => badge(r.status) },
    { key: 'created_at', label: 'Reported', render: (r) => fmtDate(r.created_at) },
  ], onRowClick: () => navigate('/issues'),
});

function evidenceTab(c) {
  const host = h('div');
  asyncView(host, () => api.get('photos', { campaign_id: c.id, per_page: 48 }), ({ data, meta }, reload) => h('div', { class: 'stack sm' },
    photoGrid(data, { onChange: reload }), meta.total > data.length && h('p', { class: 'muted small' }, `Showing the latest ${data.length} of ${meta.total}. Use the Evidence page for filters.`)));
  return host;
}

function reportsTab(c) {
  return card('Campaign reports', h('div', { class: 'stack sm' }, h('p', { class: 'muted' }, 'Open a report pre-filtered to this campaign.'),
    h('div', { class: 'pill-row' }, ['campaign_performance', 'visit_completion', ...(c.settings?.tasks?.sales ? ['sales'] : []), 'osa', 'merchandising', 'attendance', 'issue_management', 'evidence'].map((t) =>
      h('a', { class: 'btn sm', href: href(`/reports/${t}?campaign_id=${c.id}`) }, titleCase(t))))));
}
