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
  const [en, clients, supervisors] = await Promise.all([enums(), lookup('clients').catch(() => []), lookup('supervisors').catch(() => [])]);
  const roles = (en.roles || []).map((r) => ({ value: r.slug, label: r.name }));
  const agentTypes = en.agent_types || [];
  let table; const reload = () => table.reload();
  const me = session.user?.id;

  const fields = (rec) => [
    { name: 'name', label: 'Full name', required: true }, { name: 'email', label: 'Email', type: 'email', required: true, autocomplete: 'off' },
    { name: 'username', label: 'Username', help: 'Optional. Letters, numbers, dashes.' }, { name: 'phone', label: 'Phone' },
    { name: 'role', label: 'Role', type: 'select', required: true, options: roles, blank: false, disabled: rec?.id === me, help: rec?.id === me ? 'You cannot change your own role.' : undefined },
    { name: 'client_id', label: 'Client', type: 'select', options: clients, help: 'Required for client users only.' },
    { name: 'agent_type', label: 'Agent type', type: 'select', options: agentTypes }, { name: 'agent_code', label: 'Agent code', maxlength: 40 },
    { name: 'supervisor_id', label: 'Supervisor', type: 'select', options: supervisors }, { name: 'region', label: 'Region' },
    ...(rec ? [] : [{ name: 'password', label: 'Initial password', type: 'password', autocomplete: 'new-password', full: true, help: 'Leave blank to generate a strong temporary password. The user must change it at first sign-in.' }]),
  ];

  /** Show only the fields that apply to the chosen role. */
  const wireRole = (form) => {
    const role = form.control('role');
    const toggle = (names, on) => names.forEach((n) => { const w = form.control(n)?.closest('.field'); if (w) w.style.display = on ? '' : 'none'; });
    const apply = () => { toggle(['client_id'], role.value === 'client_user'); toggle(['agent_type', 'agent_code', 'supervisor_id', 'region'], role.value === 'field_agent'); };
    role.addEventListener('change', apply); apply();
  };
  const scrub = (v) => { const o = { ...v }; Object.keys(o).forEach((k) => { if (o[k] === null || o[k] === '') delete o[k]; }); if (o.role !== 'client_user') delete o.client_id; if (o.role !== 'field_agent') ['agent_type', 'agent_code', 'supervisor_id', 'region'].forEach((k) => delete o[k]); return o; };

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
      { key: 'client', label: 'Client / region', render: (r) => r.client?.name || r.agent_profile?.region || '—' },
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
