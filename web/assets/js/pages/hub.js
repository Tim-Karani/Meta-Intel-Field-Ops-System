import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { canAny, hasFeature } from '../core/auth.js';
import { href } from '../core/router.js';
import { pageHead } from '../ui/feedback.js';
import { HUBS } from '../nav.js';

/** A hub groups related pages under one menu item, each with a plain line on what it is for. */
export default async function render({ container, params }) {
  const hub = HUBS[params.name];
  if (!hub) { container.append(pageHead({ title: 'Not found' }), h('p', { class: 'muted' }, 'That menu does not exist.')); return; }
  const sections = hub.sections.map((s) => ({ ...s, items: s.items.filter((i) => canAny(i.perm) && (!i.feature || hasFeature(i.feature))) })).filter((s) => s.items.length);
  container.append(pageHead({ title: hub.title, subtitle: hub.intro }));
  if (!sections.length) { container.append(h('p', { class: 'muted' }, 'You do not have access to anything here.')); return; }
  container.append(h('div', { class: 'stack' }, sections.map((s) => h('section', null,
    h('h3', { class: 'hub-title' }, s.title),
    h('div', { class: 'hub-grid' }, s.items.map((i) => h('a', { class: 'hub-tile', href: href(i.route) },
      h('span', { class: 'hub-ic' }, icon(i.icon, { size: 20 })),
      h('span', { class: 'hub-tx' }, h('b', null, i.label), h('span', null, i.desc)))))))));
}
