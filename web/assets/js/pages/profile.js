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
    u.must_change_password && h('div', { class: 'alert warn', style: { marginBottom: '16px' } }, icon('alert'), h('div', null, h('strong', null, 'Please set a new password. '), 'Your account is using a temporary password.')),
    h('div', { class: 'grid cols-2' },
      card('Account', kv([['Name', u.name], ['Email', u.email], ['Username', u.username], ['Phone', u.phone], ['Role', roleName()], ['Client', u.client?.name], ['Status', badge(u.status)], ['Last sign-in', fmtDateTime(u.last_login_at)]])),
      card('Change password', h('div', { class: 'stack' }, banner, f.el, h('div', null, btn)))));
}
