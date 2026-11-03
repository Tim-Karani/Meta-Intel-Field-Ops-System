import { h, clear } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api } from '../core/api.js';
import { can } from '../core/auth.js';
import { fmtDate } from '../core/format.js';
import { badge, pageHead, toast, toastError, confirmDialog, openModal } from '../ui/feedback.js';
import { dataTable } from '../ui/table.js';
import { formModal } from '../ui/crud.js';
import { lookup } from '../core/lookups.js';
import { openRoutePlanner } from '../ui/route-planner.js';

const agentOptions = async () => (await api.get('assignments/agents')).data.map((r) => ({ value: r.id, label: r.name }));

/**
 * Fixed Journey Plans. A plan is one agent's recurring outlets by weekday in one campaign. Saving it creates the agent's
 * visits for the next two weeks (and the server keeps topping them up every night). Relievers can have a plan with
 * start/end dates covering the period they stand in.
 */
export default async function render({ container }) {
  const campaigns = await lookup('campaigns').catch(() => []);
  await plansPanel({ container, campaigns });
}

/** The journey plan list. With `campaign` set it is the one campaign's plans (used inside the project workspace). */
export async function plansPanel({ container, campaigns, campaign = null }) {
  let table; const reload = () => table.reload();
  const canEdit = can('assignments.edit');

  const create = async () => {
    const agents = await agentOptions().catch(() => []);
    formModal({ title: 'New journey plan', size: 'lg', submitLabel: 'Create and choose outlets',
      intro: 'One plan per agent per campaign. Next you choose the outlets and the weekdays they are visited.',
      fields: [
        { name: 'campaign_id', label: 'Campaign', type: 'select', required: true, options: campaigns, disabled: !!campaign }, { name: 'agent_id', label: 'Field agent', type: 'select', required: true, options: agents },
        { name: 'name', label: 'Plan name', placeholder: 'e.g. Westlands round', full: true },
        { name: 'starts_on', label: 'Starts on', type: 'date', help: 'Leave empty to start now. Set for a reliever covering leave.' }, { name: 'ends_on', label: 'Ends on', type: 'date', help: 'Leave empty for an ongoing plan.' },
      ],
      values: campaign ? { campaign_id: campaign.id } : undefined,
      onSubmit: async (v) => { const o = { ...v, ...(campaign ? { campaign_id: campaign.id } : {}) }; Object.keys(o).forEach((k) => { if (o[k] === null || o[k] === '') delete o[k]; }); return (await api.post('journey-plans', o)).data; },
      onSaved: (plan) => { reload(); if (plan?.id) editStops(plan); } });
  };

  const editStops = (plan) => openRoutePlanner({ plan, onSaved: reload });

  const setStatus = async (r, status) => {
    if (status !== 'active' && !(await confirmDialog({ title: `${status === 'paused' ? 'Pause' : 'End'} this plan?`, message: 'Upcoming visits that have not been started are cancelled. Past visits stay.', confirmLabel: status === 'paused' ? 'Pause plan' : 'End plan', danger: status === 'ended' }))) return;
    try { await api.post(`journey-plans/${r.id}/status`, { status }); toast(`Plan ${status}.`); reload(); } catch (e) { toastError(e); }
  };
  const generate = async (r) => { try { const res = await api.post(`journey-plans/${r.id}/generate`); toast(res.message || 'Done.'); } catch (e) { toastError(e); } };

  const newBtn = can('assignments.create') && h('button', { class: 'btn primary', type: 'button', onclick: create }, icon('plus', { size: 16 }), 'New journey plan');
  if (campaign) container.append(h('div', { class: 'row', style: { justifyContent: 'space-between', alignItems: 'center', marginBottom: '10px' } }, h('p', { class: 'muted', style: { margin: 0 } }, 'Each agent has one plan: their outlets by weekday, and what they do on each day. Visits are created automatically.'), newBtn));
  else container.append(pageHead({ title: 'Journey plans', subtitle: 'Fixed outlets by weekday for permanent merchandisers, and cover plans for relievers. Visits are created automatically.',
    actions: newBtn }));
  table = dataTable({
    endpoint: 'journey-plans', defaultSort: '-created_at', search: false, query: campaign ? { campaign_id: campaign.id } : {},
    filters: [...(campaign ? [] : [{ key: 'campaign_id', label: 'All campaigns', options: campaigns }]), { key: 'status', label: 'All statuses', options: ['active', 'paused', 'ended'] }],
    columns: [
      { key: 'agent', label: 'Agent', render: (r) => h('div', null, h('strong', null, r.agent?.name || '—'), r.name && h('div', { class: 'small muted' }, r.name)) },
      { key: 'campaign', label: 'Campaign', render: (r) => r.campaign?.name || '—' },
      { key: 'stops', label: 'Outlets', align: 'right', render: (r) => h('span', null, r.outlets_count ?? 0, h('span', { class: 'small muted' }, ` · ${r.stops_count ?? 0} visits/wk`)) },
      { key: 'dates', label: 'Valid', render: (r) => (r.starts_on || r.ends_on ? `${r.starts_on ? fmtDate(r.starts_on) : 'now'} → ${r.ends_on ? fmtDate(r.ends_on) : 'ongoing'}` : 'Ongoing') },
      { key: 'status', label: 'Status', render: (r) => badge(r.status) },
      canEdit && { key: '_a', label: '', align: 'right', render: (r) => h('div', { class: 'row', style: { justifyContent: 'flex-end' } },
        h('button', { class: 'btn sm', type: 'button', onclick: () => editStops(r) }, icon('edit', { size: 14 }), 'Plan outlets & days'),
        r.status === 'active' && h('button', { class: 'btn sm ghost', type: 'button', title: 'Create any missing visits now', onclick: () => generate(r) }, icon('refresh', { size: 15 })),
        r.status === 'active' ? h('button', { class: 'btn sm ghost', type: 'button', onclick: () => setStatus(r, 'paused') }, 'Pause') : r.status === 'paused' && h('button', { class: 'btn sm ghost', type: 'button', onclick: () => setStatus(r, 'active') }, 'Resume'),
        r.status !== 'ended' && h('button', { class: 'btn sm ghost', type: 'button', onclick: () => setStatus(r, 'ended') }, 'End')) },
    ].filter(Boolean),
    emptyTitle: 'No journey plans yet', emptyText: 'Create a plan for each permanent merchandiser, and cover plans for relievers.',
  });
  container.append(table);
}
