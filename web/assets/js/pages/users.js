import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api } from '../core/api.js';
import { can, session } from '../core/auth.js';
import { relTime, fmtDateTime } from '../core/format.js';
import { badge, pageHead, toast, toastError, confirmDialog, openModal } from '../ui/feedback.js';
import { dataTable } from '../ui/table.js';
import { formModal } from '../ui/crud.js';
import { lookup, enums } from '../core/lookups.js';

export default async function render({ container }) {
  const [en, clients, supervisors, teamLeaders] = await Promise.all([enums(), lookup('clients').catch(() => []), lookup('supervisors').catch(() => []), lookup('team_leaders').catch(() => [])]);
  const roles = (en.roles || []).map((r) => ({ value: r.slug, label: r.name }));
  const agentTypes = en.agent_types || [];
  const employment = (en.employment_types || ['permanent', 'reliever']).map((v) => ({ value: v, label: v === 'permanent' ? 'Permanent (fixed journey plan)' : 'Reliever (covers other people\'s outlets)' }));
  let table; const reload = () => table.reload();
  const me = session.user?.id;

  const fields = (rec) => [
    { name: 'name', label: 'Full name', required: true }, { name: 'email', label: 'Email', type: 'email', required: true, autocomplete: 'off' },
    { name: 'username', label: 'Username', help: 'Optional. Letters, numbers, dashes.' }, { name: 'phone', label: 'Phone' },
    { name: 'role', label: 'Role', type: 'select', required: true, options: roles, blank: false, disabled: rec?.id === me, help: rec?.id === me ? 'You cannot change your own role.' : undefined },
    { name: 'client_id', label: 'Client', type: 'select', options: clients, help: 'Required for client users only.' },
    { name: 'agent_type', label: 'Agent type', type: 'select', options: agentTypes }, { name: 'employment_type', label: 'Employment', type: 'select', blank: false, options: employment, value: 'permanent', help: 'Permanent merchandisers follow a Fixed Journey Plan. Relievers cover outlets for others.' }, { name: 'can_add_outlets', label: 'May add outlets in the field', type: 'checkbox', full: true, help: 'Switch on for people you trust to add an outlet from the app while standing at it. Off means they can only visit outlets you give them. Applies to every project they work on.' }, { name: 'agent_code', label: 'Agent code', maxlength: 40 },
    { name: 'team_leader_id', label: 'Team Leader', type: 'select', options: teamLeaders, help: 'The Team Leader who runs this agent\'s region or smaller team. Their Supervisor and region are filled in for you.' },
    { name: 'supervisor_id', label: 'Supervisor', type: 'select', options: supervisors, help: 'Looks after every region, including the Team Leaders.' }, { name: 'region', label: 'Region', help: 'e.g. Nairobi, Mombasa.' },
    { name: 'pay_type', label: 'Pay type', type: 'select', blank: false, value: 'daily', options: [{ value: 'daily', label: 'Per day worked' }, { value: 'hourly', label: 'Per hour worked' }, { value: 'monthly', label: 'Monthly salary' }], help: 'Used by Payroll. Time worked starts at the first outlet check-in.' },
    { name: 'pay_rate', label: 'Pay rate (KES)', type: 'decimal', min: 0, value: 0 },
    ...(rec ? [] : [{ name: 'password', label: 'Initial password', type: 'password', autocomplete: 'new-password', full: true, help: 'Leave blank to generate a strong temporary password. The user must change it at first sign-in.' }]),
  ];

  /** Show only the fields that apply to the chosen role. */
  const wireRole = (form) => {
    const role = form.control('role');
    const toggle = (names, on) => names.forEach((n) => { const w = form.control(n)?.closest('.field'); if (w) w.style.display = on ? '' : 'none'; });
    const apply = () => { toggle(['client_id'], role.value === 'client_user'); toggle(['agent_type', 'employment_type', 'agent_code', 'team_leader_id'], role.value === 'field_agent'); toggle(['supervisor_id', 'region', 'pay_type', 'can_add_outlets', 'pay_rate', 'can_add_outlets'], role.value === 'field_agent' || role.value === 'team_leader'); };
    role.addEventListener('change', apply); apply();
  };
  const scrub = (v) => { const o = { ...v }; Object.keys(o).forEach((k) => { if (o[k] === null || o[k] === '') delete o[k]; }); if (o.role !== 'client_user') delete o.client_id; if (o.role !== 'field_agent') ['agent_type', 'employment_type', 'agent_code', 'team_leader_id'].forEach((k) => delete o[k]); if (o.role !== 'field_agent' && o.role !== 'team_leader') ['supervisor_id', 'region', 'pay_type', 'pay_rate'].forEach((k) => delete o[k]); return o; };

  const tempPassword = (pw, email) => openModal({ title: 'User created', size: 'sm', body: h('div', { class: 'stack sm' },
    h('p', null, `Share this temporary password with ${email} through a secure channel. It is shown only once; they must change it at first sign-in.`),
    h('code', { class: 'mono', style: { fontSize: '1.1rem', padding: '10px', background: 'var(--neutral-bg)', borderRadius: '6px', display: 'block', userSelect: 'all' } }, pw)),
  footer: (close) => [h('button', { class: 'btn', type: 'button', onclick: () => navigator.clipboard?.writeText(pw).then(() => toast('Copied.')) }, 'Copy'), h('button', { class: 'btn primary', type: 'button', onclick: () => close() }, 'Done')] });

  const create = () => { const { form } = formModal({ title: 'New user', size: 'lg', fields: fields(null), submitLabel: 'Create user',
    onSubmit: async (v) => (await api.post('users', scrub(v))).data, onSaved: (u) => { reload(); if (u?.temporary_password) tempPassword(u.temporary_password, u.email); else toast('User created.'); } }); wireRole(form); };
  const edit = (u) => { const { form } = formModal({ title: 'Edit user', size: 'lg', fields: fields(u), submitLabel: 'Save changes',
    values: { ...u, role: u.roles?.[0]?.slug, ...(u.agent_profile || {}) },
    onSubmit: async (v) => { const o = scrub(v); if (u.id === me) delete o.role; await api.patch(`users/${u.id}`, o); toast('User updated.'); }, onSaved: reload }); wireRole(form); };

  const toggle = async (u) => {
    const disabling = u.status !== 'disabled';
    if (!(await confirmDialog({ title: `${disabling ? 'Disable' : 'Enable'} ${u.name}?`, message: disabling ? 'They are signed out immediately and cannot sign in. Their history is kept.' : 'They will be able to sign in again.', confirmLabel: disabling ? 'Disable' : 'Enable', danger: disabling }))) return;
    try { await api.post(`users/${u.id}/${disabling ? 'disable' : 'enable'}`); toast(disabling ? 'User disabled.' : 'User enabled.'); reload(); } catch (e) { toastError(e); }
  };
  /** One switch per person: may they add an outlet from the app? Saves straight away. */
  const outletSwitch = (u) => {
    const on = !!u.agent_profile.can_add_outlets;
    const box = h('input', { type: 'checkbox', checked: on, disabled: !can('users.edit'), 'aria-label': `${u.name} may add outlets in the field` });
    box.addEventListener('change', async () => {
      box.disabled = true;
      try { await api.patch(`users/${u.id}`, { can_add_outlets: box.checked }); u.agent_profile.can_add_outlets = box.checked; toast(box.checked ? `${u.name} can now add outlets.` : `${u.name} can no longer add outlets.`); }
      catch (e) { box.checked = !box.checked; toastError(e); }
      box.disabled = !can('users.edit');
    });
    return h('label', { class: 'check' }, box, h('span', { class: 'small muted' }, 'May add'));
  };
  const resetLink = async (u) => {
    if (!(await confirmDialog({ title: 'Send password reset?', message: `A reset link is emailed to ${u.email}.`, confirmLabel: 'Send link' }))) return;
    try { await api.post(`users/${u.id}/send-reset-link`); toast('Reset link sent.'); } catch (e) { toastError(e); }
  };

  container.append(pageHead({ title: 'Users', subtitle: 'Staff, field agents and client users.', actions: can('users.create') && h('button', { class: 'btn primary', type: 'button', onclick: create }, icon('plus', { size: 16 }), 'New user') }));
  table = dataTable({
    endpoint: 'users', defaultSort: 'name', searchPlaceholder: 'Search name, email, phone…',
    filters: [{ key: 'role', label: 'All roles', options: roles }, { key: 'status', label: 'All statuses', options: ['active', 'pending', 'suspended', 'disabled', 'locked'] }],
    columns: [
      { key: 'name', label: 'User', sort: 'name', render: (r) => h('div', null, h('strong', null, r.name), h('div', { class: 'small muted' }, r.email)) },
      { key: 'role', label: 'Role', render: (r) => (r.roles || []).map((x) => x.name).join(', ') || '—' },
      { key: 'client', label: 'Client / region', render: (r) => r.client?.name || (r.agent_profile ? [r.agent_profile.employment_type === 'reliever' ? 'Reliever' : 'Permanent', r.agent_profile.region].filter(Boolean).join(' · ') : '—') },
      { key: 'adds', label: 'Adds outlets', render: (r) => (r.agent_profile ? outletSwitch(r) : h('span', { class: 'muted' }, '—')) },
      { key: 'status', label: 'Status', sort: 'status', render: (r) => badge(r.status) },
      { key: 'last_login_at', label: 'Last sign-in', sort: 'last_login_at', render: (r) => (r.last_login_at ? h('span', { title: fmtDateTime(r.last_login_at) }, relTime(r.last_login_at)) : h('span', { class: 'muted' }, 'Never')) },
      { key: '_a', label: '', align: 'right', render: (r) => h('div', { class: 'row', style: { justifyContent: 'flex-end' } },
        can('users.edit') && h('button', { class: 'btn sm ghost', type: 'button', title: 'Edit', 'aria-label': `Edit ${r.name}`, onclick: () => edit(r) }, icon('edit', { size: 15 })),
        can('users.edit') && h('button', { class: 'btn sm ghost', type: 'button', title: 'Send reset link', 'aria-label': `Send reset link to ${r.name}`, onclick: () => resetLink(r) }, icon('mail', { size: 15 })),
        can('users.disable') && r.id !== me && h('button', { class: 'btn sm ghost', type: 'button', title: r.status === 'disabled' ? 'Enable' : 'Disable', 'aria-label': `${r.status === 'disabled' ? 'Enable' : 'Disable'} ${r.name}`, onclick: () => toggle(r) }, icon(r.status === 'disabled' ? 'check' : 'lock', { size: 15 }))) },
    ],
  });
  container.append(table);
}
