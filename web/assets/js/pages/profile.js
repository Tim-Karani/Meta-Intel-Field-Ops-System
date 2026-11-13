import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api } from '../core/api.js';
import { session, roleName } from '../core/auth.js';
import { fmtDateTime } from '../core/format.js';
import { card, kv, pageHead, badge, toast, errorBox } from '../ui/feedback.js';
import { buildForm } from '../ui/form.js';

export default async function render({ container }) {
  const u = session.user;
  const f = buildForm([
    { name: 'current_password', label: 'Current password', type: 'password', required: true, autocomplete: 'current-password', full: true },
    { name: 'password', label: 'New password', type: 'password', required: true, autocomplete: 'new-password', help: 'At least 10 characters, mixing letters, numbers and symbols.' },
    { name: 'password_confirmation', label: 'Confirm new password', type: 'password', required: true, autocomplete: 'new-password' },
  ]);
  const banner = h('div');
  const pf = buildForm([
    { name: 'name', label: 'Name', required: true, autocomplete: 'name' },
    { name: 'email', label: 'Email', type: 'email', required: true, autocomplete: 'email' },
    { name: 'username', label: 'Username', autocomplete: 'username' },
    { name: 'phone', label: 'Phone', autocomplete: 'tel' },
    { name: 'current_password', label: 'Current password', type: 'password', autocomplete: 'current-password', full: true, help: 'Only needed if you change your email.' },
  ], { name: u.name, email: u.email, username: u.username || '', phone: u.phone || '' });
  const pBanner = h('div');
  const pBtn = h('button', { class: 'btn primary', type: 'button', onclick: async () => {
    pf.clearErrors(); pBanner.replaceChildren(); pBtn.disabled = true;
    try {
      const { data } = await api.patch('auth/profile', pf.read());
      session.setUser(data); pf.set('current_password', '');
      toast('Profile saved.');
      container.querySelector('.page-head p')?.replaceChildren(data.email);
    } catch (e) { if (!pf.showErrors(e)) pBanner.append(errorBox(e)); } finally { pBtn.disabled = false; }
  } }, icon('check', { size: 16 }), 'Save profile');
  const btn = h('button', { class: 'btn primary', type: 'button', onclick: async () => {
    f.clearErrors(); banner.replaceChildren(); btn.disabled = true;
    try {
      await api.post('auth/change-password', f.read());
      toast('Password changed. Other devices have been signed out.');
      ['current_password', 'password', 'password_confirmation'].forEach((n) => f.set(n, ''));
      const { data } = await api.get('auth/me'); session.setUser(data);
    } catch (e) { if (!f.showErrors(e)) banner.append(errorBox(e)); } finally { btn.disabled = false; }
  } }, icon('lock', { size: 16 }), 'Change password');
  container.append(pageHead({ title: 'My profile', subtitle: u.email }),
    ...(u.must_change_password ? [h('div', { class: 'alert warn', style: { marginBottom: '16px' } }, icon('alert'), h('div', null, h('strong', null, 'Please set a new password. '), 'Your account is using a temporary password.'))] : []),
    h('div', { class: 'grid cols-2' },
      card('Account', h('div', { class: 'stack' }, pBanner, pf.el, h('div', null, pBtn),
        kv([['Role', roleName()], u.client?.name && ['Client', u.client.name], ['Status', badge(u.status)], ['Last sign-in', fmtDateTime(u.last_login_at)]]))),
      card('Change password', h('div', { class: 'stack' }, banner, f.el, h('div', null, btn)))));
}
