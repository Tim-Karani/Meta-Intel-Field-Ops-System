import { h, mount } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api } from '../core/api.js';
import { session } from '../core/auth.js';
import { buildForm } from '../ui/form.js';
import { toast } from '../ui/feedback.js';

export default async function render({ container, route, query, navigate }) {
  const mode = route.mode || 'login';
  const appName = window.APP_CONFIG?.appName || 'Field Operations';
  const alertBox = h('div', { class: 'hidden', role: 'alert' });
  const showAlert = (msg, kind = 'error') => { alertBox.className = `alert ${kind}`; alertBox.replaceChildren(icon(kind === 'ok' ? 'checkCircle' : 'alert'), h('div', null, msg)); };

  const fields = {
    login: [
      { name: 'login', label: 'Email or username', type: 'text', required: true, autocomplete: 'username', full: true },
      { name: 'password', label: 'Password', type: 'password', required: true, autocomplete: 'current-password', full: true },
    ],
    forgot: [{ name: 'email', label: 'Email address', type: 'email', required: true, autocomplete: 'email', full: true }],
    reset: [
      { name: 'password', label: 'New password', type: 'password', required: true, autocomplete: 'new-password', full: true, help: 'At least 10 characters with upper and lower case letters and a number.' },
      { name: 'password_confirmation', label: 'Confirm new password', type: 'password', required: true, autocomplete: 'new-password', full: true },
    ],
  }[mode];

  const form = buildForm(fields, mode === 'reset' ? {} : {});
  const submit = h('button', { class: 'btn primary', type: 'submit', style: { width: '100%' } }, mode === 'login' ? 'Sign in' : mode === 'forgot' ? 'Send reset link' : 'Set new password');

  const onSubmit = async (e) => {
    e.preventDefault();
    form.clearErrors(); alertBox.className = 'hidden';
    const v = form.read();
    submit.disabled = true; submit.replaceChildren(h('span', { class: 'spinner' }), ' Please wait…');
    try {
      if (mode === 'login') {
        const { data } = await api.post('auth/login', { login: v.login, password: v.password, device_name: 'web' });
        session.set(data);
        if (data.user.must_change_password) { toast('Please choose a new password.', 'warn'); navigate('/profile'); }
        else navigate(data.user.scope_type === 'client' ? '/portal' : '/dashboard');
      } else if (mode === 'forgot') {
        const { message } = await api.post('auth/forgot-password', { email: v.email });
        showAlert(message, 'ok');
      } else {
        await api.post('auth/reset-password', { token: query.token, email: query.email, password: v.password, password_confirmation: v.password_confirmation });
        toast('Password updated. You can sign in now.');
        navigate('/login');
      }
    } catch (err) {
      if (!form.showErrors(err)) showAlert(err.message);
    } finally {
      submit.disabled = false; submit.textContent = mode === 'login' ? 'Sign in' : mode === 'forgot' ? 'Send reset link' : 'Set new password';
    }
  };

  const title = { login: 'Sign in', forgot: 'Reset your password', reset: 'Choose a new password' }[mode];
  const sub = { login: 'Use the account issued by your administrator.', forgot: 'We will email you a link if the address is registered.', reset: 'Pick something strong that you do not use elsewhere.' }[mode];

  mount(container, h('div', { class: 'auth-wrap' },
    h('section', { class: 'auth-hero' },
      h('div', { class: 'brand', style: { padding: 0, border: 0 } }, h('span', { class: 'brand-mark' }, icon('pin', { size: 18 })), h('span', null, appName)),
      h('div', null,
        h('h1', null, 'Run field execution with evidence, not guesswork.'),
        h('p', null, 'Plan campaigns, assign agents, verify visits with GPS and photos, and report to clients from one place.'),
        h('ul', null, ['GPS-verified check-ins', 'Photo evidence with review', 'Role-based access to every record', 'Audit trail for every change'].map((t) => h('li', null, t)))),
      h('p', { class: 'small', style: { color: 'var(--navy-300)' } }, '© ', new Date().getFullYear(), ' ', appName)),
    h('section', { class: 'auth-card' },
      h('form', { onsubmit: onSubmit, novalidate: true, 'aria-labelledby': 'auth-title' },
        h('h2', { id: 'auth-title', style: { fontSize: '24px', marginBottom: '6px' } }, title), h('p', { class: 'muted', style: { marginBottom: '20px' } }, sub),
        alertBox, h('div', { style: { height: alertBox.className === 'hidden' ? 0 : 12 } }), form.el, submit,
        h('div', { class: 'small', style: { marginTop: '16px', textAlign: 'center' } },
          mode === 'login' ? h('a', { href: '#/forgot-password' }, 'Forgot your password?') : h('a', { href: '#/login' }, 'Back to sign in'))))));

  if (mode === 'reset' && (!query.token || !query.email)) showAlert('This reset link is incomplete. Request a new one.');
}
