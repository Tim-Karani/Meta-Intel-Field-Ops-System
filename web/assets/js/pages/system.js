import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api } from '../core/api.js';
import { fmtDateTime, fmtNumber, titleCase } from '../core/format.js';
import { asyncView, badge, card, pageHead, stat, toast, toastError } from '../ui/feedback.js';
import { simpleTable } from '../ui/table.js';

const LABEL = { database: 'Database', migrations: 'Migrations', evidence_storage: 'Evidence storage', queue: 'Queue', scheduler: 'Scheduler' };

function testEmailCard(mail) {
  const to = h('input', { class: 'input', type: 'email', placeholder: 'Your email (leave empty to use the one on your profile)', 'aria-label': 'Send the test email to' });
  const btn = h('button', { class: 'btn primary', type: 'button' }, 'Send test email');
  btn.addEventListener('click', async () => {
    btn.disabled = true;
    try {
      const r = await api.post('system/test-email', to.value.trim() ? { to: to.value.trim() } : {});
      toast(r.message || 'Done.', r.data?.sent ? 'ok' : 'warn', 8000);
    } catch (e) { toastError(e); } finally { btn.disabled = false; }
  });
  return card('Email', h('div', { class: 'stack' },
    (mail === 'log' || mail === 'array') && h('div', { class: 'alert warn' }, icon('alert'), h('div', null, h('strong', null, 'Email is not set up.'), ' Password resets and scheduled reports are only written to the server log. Set MAIL_MAILER=smtp and the MAIL_ settings in backend/.env (see docs/EMAIL_SETUP.md).')),
    h('div', { class: 'small' }, `Current mail setting: ${mail}`),
    h('div', { class: 'row' }, to, btn)));
}

export default async function render({ container }) {
  const host = h('div');
  container.append(pageHead({ title: 'System health', subtitle: 'Live checks run on every refresh.', actions: h('button', { class: 'btn', type: 'button', onclick: () => run() }, icon('refresh', { size: 16 }), 'Refresh') }), host);
  const run = asyncView(host, () => api.get('system/health').then((r) => r.data), (d) => h('div', { class: 'stack' },
    h('div', { class: d.status === 'ok' ? 'alert success' : 'alert warn' }, icon(d.status === 'ok' ? 'checkCircle' : 'alert'), h('div', null, h('strong', null, d.status === 'ok' ? 'All systems operational' : 'Some checks need attention'), h('div', { class: 'small' }, `Checked ${fmtDateTime(d.time)}`))),
    d.debug && d.environment === 'production' && h('div', { class: 'alert error' }, icon('alert'), h('div', null, h('strong', null, 'Debug mode is enabled in production.'), ' Set APP_DEBUG=false immediately.')),
    card('Checks', simpleTable([
      { label: 'Check', render: (r) => h('strong', null, LABEL[r.name] || titleCase(r.name)) }, { label: 'Status', render: (r) => badge(r.ok ? 'ok' : 'failed', { tone: r.ok ? 'ok' : 'danger' }) },
      { label: 'Detail', render: (r) => r.detail }, { label: 'Time', align: 'right', render: (r) => `${r.ms} ms` },
    ], Object.entries(d.checks).map(([name, c]) => ({ name, ...c }))), { flush: true }),
    h('div', { class: 'grid cols-4' }, Object.entries(d.counts).map(([k, v]) => stat({ label: titleCase(k), value: fmtNumber(v), icon: 'layers' }))),
    testEmailCard(d.mail),
    card('Runtime', h('dl', { class: 'kv' }, [['Environment', d.environment], ['PHP', d.php], ['Laravel', d.laravel]].flatMap(([k, v]) => [h('dt', null, k), h('dd', null, v)])))));
}
