import { h } from '../core/dom.js';
import { api } from '../core/api.js';
import { asyncView, card, pageHead, tabs } from '../ui/feedback.js';
import { titleCase } from '../core/format.js';

const SCOPE = { all: 'Everything', campaigns: 'Assigned campaigns', team: 'Own teams', own: 'Own work only', client: 'One client (approved data)' };

export default async function render({ container }) {
  container.append(pageHead({ title: 'Roles & permissions', subtitle: 'What each role can do. Access is enforced by the API; this matrix is read-only and reflects the live configuration.' }));
  const host = h('div'); container.append(host);
  asyncView(host, () => api.get('roles').then((r) => r.data), ({ roles, permissions }) => {
    const byModule = permissions.reduce((m, p) => { (m[p.module] ||= []).push(p.slug); return m; }, {});
    const matrix = h('div', { class: 'table-wrap' }, h('table', { class: 'data matrix' },
      h('thead', null, h('tr', null, h('th', { scope: 'col' }, 'Permission'), roles.map((r) => h('th', { scope: 'col', class: 'center', title: r.name }, r.name)))),
      h('tbody', null, Object.entries(byModule).flatMap(([module, slugs]) => [
        h('tr', { class: 'group-row' }, h('th', { colspan: roles.length + 1, scope: 'colgroup' }, titleCase(module))),
        ...slugs.map((slug) => h('tr', null, h('td', null, h('span', { class: 'mono' }, slug)), roles.map((r) => h('td', { class: 'center' }, r.permissions.includes(slug) ? h('span', { class: 'yes', 'aria-label': 'Allowed', title: 'Allowed' }, '●') : h('span', { class: 'no', 'aria-label': 'Not allowed' }, '–'))))),
      ]))));
    const summary = h('div', { class: 'grid cols-3' }, roles.map((r) => card(r.name, h('div', { class: 'stack sm' }, h('div', { class: 'small muted' }, `Data scope: ${SCOPE[r.scope_type] || r.scope_type}`), h('div', null, `${r.permissions.length} permissions`)))));
    return tabs([{ key: 'matrix', label: 'Permission matrix', render: () => card(null, matrix, { flush: true }) }, { key: 'roles', label: 'Role summary', render: () => summary }]);
  });
}
