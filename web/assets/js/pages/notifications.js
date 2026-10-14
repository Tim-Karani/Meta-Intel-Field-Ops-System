import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api } from '../core/api.js';
import { relTime } from '../core/format.js';
import { pageHead, asyncView, empty, toast, toastError } from '../ui/feedback.js';
import { navigate } from '../core/router.js';
import { can } from '../core/auth.js';

/** Where a notification should take the user, based on the ids the server attached. */
function target(n) {
  const d = n.data || {};
  if (d.visit_id && can('visits.view')) return `/visits/${d.visit_id}`;
  if (d.issue_id && can('issues.view')) return `/issues?open=${d.issue_id}`;
  if (d.assignment_id) return can('assignments.respond') ? '/my-work' : '/assignments';
  return null;
}

export default async function render({ container }) {
  let unreadOnly = false; let page = 1;
  const host = h('div');
  const toggle = h('label', { class: 'check' }, h('input', { type: 'checkbox', onchange: (e) => { unreadOnly = e.target.checked; page = 1; load(); } }), 'Unread only');
  const readAll = h('button', { class: 'btn', type: 'button', onclick: async () => { try { await api.post('notifications/read-all'); toast('All notifications marked as read.'); document.dispatchEvent(new CustomEvent('notifications:changed')); load(); } catch (e) { toastError(e); } } }, icon('check', { size: 16 }), 'Mark all as read');
  container.append(pageHead({ title: 'Notifications', actions: [toggle, readAll] }), host);
  function load() {
    asyncView(host, () => api.get('notifications', { page, per_page: 20, unread: unreadOnly ? 1 : undefined }), ({ data, meta }) => {
      if (!data.length) return empty(unreadOnly ? 'You are all caught up' : 'No notifications yet', 'Alerts about assignments, reviews and issues appear here.');
      return h('div', { class: 'card' }, h('ul', { class: 'notif-list' }, data.map((n) => {
        const to = target(n);
        const open = async () => { try { if (!n.read_at) { await api.post(`notifications/${n.id}/read`); document.dispatchEvent(new CustomEvent('notifications:changed')); } } catch { /* non-fatal */ } if (to) navigate(to); else load(); };
        return h('li', { class: n.read_at ? '' : 'unread' }, h('button', { type: 'button', class: 'notif', onclick: open },
          h('span', { class: 'dot', 'aria-hidden': 'true' }), h('span', { class: 'txt' }, h('strong', null, n.title), n.body && h('span', { class: 'muted' }, ` — ${n.body}`), h('span', { class: 'small muted', style: { display: 'block' } }, relTime(n.created_at), n.read_at ? '' : ' · unread')),
          to && icon('chevronRight', { size: 16 })));
      })), meta.last_page > 1 && h('div', { class: 'pager' }, h('span', null, `${meta.total} notifications`), h('span', { class: 'spacer' }),
        h('button', { class: 'btn sm', type: 'button', disabled: meta.page <= 1, onclick: () => { page--; load(); } }, 'Prev'), h('span', null, `Page ${meta.page} of ${meta.last_page}`),
        h('button', { class: 'btn sm', type: 'button', disabled: meta.page >= meta.last_page, onclick: () => { page++; load(); } }, 'Next')));
    });
  }
  load();
}
