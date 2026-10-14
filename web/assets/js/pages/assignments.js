import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api } from '../core/api.js';
import { can } from '../core/auth.js';
import { fmtDate } from '../core/format.js';
import { badge, pageHead, toast, toastError, confirmDialog } from '../ui/feedback.js';
import { dataTable } from '../ui/table.js';
import { formModal } from '../ui/crud.js';
import { lookup, enumOptions } from '../core/lookups.js';
import { today } from '../core/format.js';
import { href } from '../core/router.js';

const linked = async (campaignId, rel) => (await api.get(`campaigns/${campaignId}/links/${rel}`)).data.map((r) => ({ value: r.id, label: r.name }));
const agentOptions = async () => (await api.get('assignments/agents')).data.map((r) => ({ value: r.id, label: r.name }));

export default async function render({ container }) {
  const [campaigns, statuses, supervisors] = await Promise.all([lookup('campaigns').catch(() => []), enumOptions('assignment_status').catch(() => []), lookup('supervisors').catch(() => [])]);
  let table;
  const reload = () => table.reload();
  const canCreate = can('assignments.create'); const canEdit = can('assignments.edit');

  const baseFields = (campaignOpts) => [
    { name: 'campaign_id', label: 'Campaign', type: 'select', required: true, options: campaignOpts },
    { name: 'activity_id', label: 'Activity', type: 'select', options: [] },
    { name: 'scheduled_date', label: 'Date', type: 'date', required: true, min: today(), value: today() },
    { name: 'start_time', label: 'Start time', type: 'time' }, { name: 'end_time', label: 'End time', type: 'time' },
    { name: 'supervisor_id', label: 'Supervisor', type: 'select', options: supervisors },
    { name: 'target', label: 'Target (visits)', type: 'number', min: 0 },
    { name: 'instructions', label: 'Instructions', type: 'textarea', full: true },
  ];

  /** When the campaign changes, restrict activities / locations / agents to what that campaign offers. */
  const wireCampaign = (form, { locations: locField }) => {
    const sel = form.control('campaign_id');
    const apply = async () => {
      const id = sel.value;
      if (!id) { form.setOptions('activity_id', []); locField && form.setOptions(locField, []); return; }
      try {
        const acts = (await api.get('activities', { campaign_id: id, per_page: 100 })).data.filter((a) => a.status !== 'cancelled').map((a) => ({ value: a.id, label: a.name }));
        form.setOptions('activity_id', acts);
        if (locField) form.setOptions(locField, await linked(id, 'locations'));
      } catch (e) { toastError(e); }
    };
    sel.addEventListener('change', apply);
    return apply;
  };

  const createOne = async () => {
    const agents = await agentOptions().catch(() => []);
    const fields = [...baseFields(campaigns).slice(0, 2),
      { name: 'agent_id', label: 'Field agent', type: 'select', required: true, options: agents },
      { name: 'location_id', label: 'Location', type: 'select', required: true, options: [], help: 'Locations linked to the selected campaign.' },
      ...baseFields(campaigns).slice(2)];
    const { form } = formModal({ title: 'New assignment', fields, size: 'lg', submitLabel: 'Create assignment',
      onSubmit: async (v) => { const o = { ...v }; Object.keys(o).forEach((k) => { if (o[k] === null || o[k] === '') delete o[k]; }); const r = await api.post('assignments', o); toast('Assignment created.'); return r.data; }, onSaved: reload });
    wireCampaign(form, { locations: 'location_id' });
  };

  const createBulk = async () => {
    const agents = await agentOptions().catch(() => []);
    const fields = [...baseFields(campaigns).slice(0, 2),
      { name: 'mode', label: 'Distribution', type: 'select', blank: false, options: [{ value: 'each', label: 'Every selected agent visits every selected location' }, { value: 'split', label: 'Share locations between agents (round-robin)' }], full: true },
      { name: 'agents', label: 'Field agents', type: 'multiselect', options: agents, emptyText: 'No assignable agents.' },
      { name: 'locations', label: 'Locations', type: 'multiselect', options: [], emptyText: 'Choose a campaign to list its locations.' },
      ...baseFields(campaigns).slice(2)];
    const preview = h('p', { class: 'small muted' });
    const { form } = formModal({ title: 'Bulk assign', intro: 'Creates all assignments together — if any row is invalid, none are created.', fields, size: 'lg', submitLabel: 'Create assignments', extra: preview,
      onSubmit: async (v) => {
        const rows = expand(v);
        if (!rows.length) throw Object.assign(new Error('Choose at least one agent and one location.'), { status: 0 });
        if (rows.length > 200) throw Object.assign(new Error(`That would create ${rows.length} assignments; the limit is 200 per batch.`), { status: 0 });
        const r = await api.post('assignments/bulk', { assignments: rows }); toast(r.message || 'Assignments created.'); return r.data;
      }, onSaved: reload });
    wireCampaign(form, { locations: 'locations' });
    const upd = () => { const n = expand(form.read()).length; preview.textContent = n ? `This will create ${n} assignment${n === 1 ? '' : 's'}.` : ''; };
    form.el.addEventListener('change', upd);
    function expand(v) {
      const common = Object.fromEntries(Object.entries({ campaign_id: v.campaign_id, activity_id: v.activity_id, scheduled_date: v.scheduled_date, start_time: v.start_time, end_time: v.end_time, supervisor_id: v.supervisor_id, target: v.target, instructions: v.instructions }).filter(([, x]) => x !== null && x !== ''));
      const ag = v.agents || []; const lo = v.locations || [];
      if (v.mode === 'split') return lo.map((l, i) => ({ ...common, agent_id: ag[i % (ag.length || 1)], location_id: l })).filter((r) => r.agent_id);
      return ag.flatMap((a) => lo.map((l) => ({ ...common, agent_id: a, location_id: l })));
    }
  };

  const reassign = async (r) => {
    const agents = await agentOptions().catch(() => []);
    formModal({ title: 'Reassign assignment', intro: `${r.location?.name} · ${fmtDate(r.scheduled_date)}. The new agent must be in scope and free of conflicts.`,
      fields: [{ name: 'agent_id', label: 'New agent', type: 'select', required: true, options: agents.filter((a) => a.value !== r.agent?.id) }],
      onSubmit: async (v) => { await api.post(`assignments/${r.id}/reassign`, v); toast('Assignment reassigned.'); }, onSaved: reload, submitLabel: 'Reassign' });
  };
  const editRow = (r) => formModal({ title: 'Edit assignment', fields: [
    { name: 'scheduled_date', label: 'Date', type: 'date', required: true, min: today() }, { name: 'target', label: 'Target', type: 'number', min: 0 },
    { name: 'start_time', label: 'Start time', type: 'time' }, { name: 'end_time', label: 'End time', type: 'time' },
    { name: 'supervisor_id', label: 'Supervisor', type: 'select', options: supervisors }, { name: 'instructions', label: 'Instructions', type: 'textarea', full: true }],
  values: { ...r, start_time: r.start_time?.slice(0, 5), end_time: r.end_time?.slice(0, 5), scheduled_date: r.scheduled_date?.slice(0, 10) },
  onSubmit: async (v) => { await api.patch(`assignments/${r.id}`, v); toast('Assignment updated.'); }, onSaved: reload });
  const cancel = async (r) => {
    const res = await confirmDialog({ title: 'Cancel assignment?', message: `${r.agent?.name} at ${r.location?.name} on ${fmtDate(r.scheduled_date)}.`, confirmLabel: 'Cancel assignment', danger: true, reason: { label: 'Reason', required: true } });
    if (!res) return;
    try { await api.post(`assignments/${r.id}/cancel`, { reason: res.reason }); toast('Assignment cancelled.'); reload(); } catch (e) { toastError(e); }
  };

  container.append(pageHead({ title: 'Assignments', subtitle: 'Who visits which location, and when.', actions: [
    canCreate && h('button', { class: 'btn', type: 'button', onclick: createBulk }, icon('users', { size: 16 }), 'Bulk assign'),
    canCreate && h('button', { class: 'btn primary', type: 'button', onclick: createOne }, icon('plus', { size: 16 }), 'New assignment')] }));
  const open = (r) => ['pending', 'accepted', 'overdue'].includes(r.status);
  table = dataTable({
    endpoint: 'assignments', defaultSort: '-scheduled_date', search: false,
    filters: [{ key: 'campaign_id', label: 'All campaigns', options: campaigns }, { key: 'status', label: 'All statuses', options: statuses }, { key: 'date_from', label: 'From date', type: 'date' }, { key: 'date_to', label: 'To date', type: 'date' }],
    columns: [
      { key: 'scheduled_date', label: 'Date', sort: 'scheduled_date', render: (r) => h('div', null, fmtDate(r.scheduled_date), r.start_time && h('div', { class: 'small muted' }, `${r.start_time.slice(0, 5)}${r.end_time ? `–${r.end_time.slice(0, 5)}` : ''}`)) },
      { key: 'agent', label: 'Agent', render: (r) => r.agent?.name || '—' },
      { key: 'location', label: 'Location', render: (r) => h('div', null, r.location?.id ? h('a', { href: href(`/locations/${r.location.id}`) }, r.location.name) : '—', r.location?.town && h('div', { class: 'small muted' }, r.location.town)) },
      { key: 'campaign', label: 'Campaign', render: (r) => (r.campaign ? h('a', { href: href(`/campaigns/${r.campaign.id}`) }, r.campaign.name) : '—') },
      { key: 'activity', label: 'Activity', render: (r) => r.activity?.name || '—' },
      { key: 'status', label: 'Status', sort: 'status', render: (r) => badge(r.status) },
      canEdit && { key: '_a', label: '', align: 'right', render: (r) => open(r) && h('div', { class: 'row', style: { justifyContent: 'flex-end' } },
        h('button', { class: 'btn sm ghost', type: 'button', title: 'Edit', 'aria-label': 'Edit assignment', onclick: () => editRow(r) }, icon('edit', { size: 15 })),
        h('button', { class: 'btn sm ghost', type: 'button', title: 'Reassign', 'aria-label': 'Reassign assignment', onclick: () => reassign(r) }, icon('users', { size: 15 })),
        h('button', { class: 'btn sm ghost', type: 'button', title: 'Cancel', 'aria-label': 'Cancel assignment', onclick: () => cancel(r) }, icon('x', { size: 15 }))) },
    ].filter(Boolean),
    emptyTitle: 'No assignments found', emptyText: canCreate ? 'Create an assignment to send an agent to a location.' : undefined,
  });
  container.append(table);
}
