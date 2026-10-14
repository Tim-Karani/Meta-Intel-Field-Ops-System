import { h, clear, debounce } from './core/dom.js';
import { icon } from './core/icons.js';
import { session, canAny, can, roleName, scopeType } from './core/auth.js';
import { api } from './core/api.js';
import { initials } from './core/format.js';
import { NAV } from './nav.js';
import { href, navigate } from './core/router.js';
import { toast } from './ui/feedback.js';

/* Authenticated application frame: sidebar, top bar, breadcrumbs, notifications bell, global search. */
export function createShell({ onLogout }) {
  const appName = window.APP_CONFIG?.appName || 'Field Operations';
  const user = session.user;
  const nav = h('nav', { class: 'nav', 'aria-label': 'Main' });
  const crumbs = h('div', { class: 'crumbs' });
  const main = h('main', { id: 'main', class: 'content', tabindex: '-1' });
  const bell = h('a', { class: 'icon-btn', href: href('/notifications'), 'aria-label': 'Notifications' }, icon('bell'));
  const shell = h('div', { class: 'shell' });
  let reviewBadge = null;

  const visible = NAV.map((g) => ({ ...g, items: g.items.filter((i) => canAny(i.perm) && !(i.hideFor === 'client' && scopeType() === 'client')) })).filter((g) => g.items.length);
  const links = new Map();
  for (const g of visible) {
    nav.append(h('div', { class: 'nav-group' }, g.group && h('h4', null, g.group), g.items.map((i) => {
      const a = h('a', { href: href(i.route), onclick: () => shell.classList.remove('nav-open') }, icon(i.icon), h('span', null, i.label));
      if (i.badge === 'review') { reviewBadge = h('span', { class: 'badge-count hidden' }, '0'); a.append(reviewBadge); }
      links.set(i.route, a);
      return a;
    })));
  }

  const userMenu = h('div', { class: 'menu hidden', role: 'menu' },
    h('a', { href: href('/profile'), role: 'menuitem' }, icon('user'), 'My profile'),
    h('hr'),
    h('button', { type: 'button', role: 'menuitem', onclick: onLogout }, icon('logout'), 'Sign out'));
  const chip = h('button', { class: 'user-chip', type: 'button', 'aria-haspopup': 'menu', 'aria-expanded': 'false', onclick: (e) => { e.stopPropagation(); toggle(); } },
    h('span', { class: 'avatar', 'aria-hidden': 'true' }, initials(user?.name)),
    h('span', { class: 'who' }, h('b', null, user?.name), h('span', null, roleName())), icon('chevronDown', { size: 14 }));
  const toggle = (force) => {
    const show = force ?? userMenu.classList.contains('hidden');
    userMenu.classList.toggle('hidden', !show); chip.setAttribute('aria-expanded', String(show));
  };
  document.addEventListener('click', () => toggle(false));

  const search = globalSearch();

  shell.append(
    h('aside', { class: 'sidebar' },
      h('div', { class: 'brand' }, h('span', { class: 'brand-mark' }, icon('pin', { size: 18 })), h('span', null, appName, h('small', null, 'Field operations'))),
      nav),
    h('div', { class: 'scrim', onclick: () => shell.classList.remove('nav-open') }),
    h('div', { class: 'main-col' },
      h('header', { class: 'topbar' },
        h('button', { class: 'icon-btn menu-btn', type: 'button', 'aria-label': 'Open menu', onclick: () => shell.classList.add('nav-open') }, icon('menu')),
        crumbs, h('span', { class: 'spacer' }), search, bell,
        h('div', { style: { position: 'relative' } }, chip, userMenu)),
      main));

  // ---- notifications poll (pauses while the tab is hidden) ----
  let timer = null;
  async function poll() {
    if (document.hidden || !can('notifications.view')) return;
    try {
      const { data } = await api.get('notifications/unread-count');
      bell.querySelector('.dot')?.remove();
      if (data.unread > 0) bell.append(h('span', { class: 'dot' }, data.unread > 99 ? '99+' : data.unread));
    } catch { /* transient */ }
    if (reviewBadge && can('visits.review')) {
      try {
        const { meta } = await api.get('visits', { needs_review: 1, per_page: 1 });
        reviewBadge.textContent = meta.total; reviewBadge.classList.toggle('hidden', !meta.total);
      } catch { /* ignore */ }
    }
  }
  poll(); timer = setInterval(poll, 60000);
  document.addEventListener('notifications:changed', poll);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });

  return {
    el: shell, main,
    setActive(path) {
      let best = null;
      links.forEach((a, route) => { if (path === route || path.startsWith(`${route}/`)) { if (!best || route.length > best[0].length) best = [route, a]; } });
      links.forEach((a) => a.removeAttribute('aria-current'));
      best?.[1].setAttribute('aria-current', 'page');
    },
    setCrumbs(trail, title) {
      clear(crumbs);
      trail.forEach(([label, to]) => crumbs.append(h('a', { href: href(to) }, label), h('span', { class: 'sep', 'aria-hidden': 'true' }, '/')));
      crumbs.append(h('strong', null, title));
    },
    dispose() { clearInterval(timer); },
    refreshBadges: poll,
  };
}

function globalSearch() {
  const sources = [
    ['clients', 'Clients', 'clients.view', (r) => `/clients/${r.id}`, 'building'],
    ['campaigns', 'Campaigns', 'campaigns.view', (r) => `/campaigns/${r.id}`, 'flag'],
    ['locations', 'Locations', 'locations.view', (r) => `/locations/${r.id}`, 'map'],
  ].filter((s) => can(s[2]));
  if (!sources.length) return h('span');
  const results = h('div', { class: 'menu hidden', style: { width: '320px', left: 0, right: 'auto' }, role: 'listbox' });
  const input = h('input', { type: 'search', placeholder: 'Search clients, campaigns, locations…', 'aria-label': 'Global search', autocomplete: 'off',
    oninput: debounce(async () => {
      const q = input.value.trim();
      if (q.length < 2) { results.classList.add('hidden'); return; }
      const lists = await Promise.allSettled(sources.map(([entity]) => api.get(`lookups/${entity}`, { search: q })));
      clear(results);
      lists.forEach((res, i) => {
        if (res.status !== 'fulfilled' || !res.value.data.length) return;
        const [, label, , to, ic] = sources[i];
        results.append(h('div', { class: 'small muted', style: { padding: '6px 10px' } }, label));
        res.value.data.slice(0, 5).forEach((r) => results.append(h('a', { href: href(to(r)), role: 'option', onclick: () => { results.classList.add('hidden'); input.value = ''; } }, icon(ic, { size: 15 }), r.name)));
      });
      if (!results.children.length) results.append(h('div', { class: 'muted', style: { padding: '10px' } }, 'No matches'));
      results.classList.remove('hidden');
    }, 300),
    onkeydown: (e) => { if (e.key === 'Escape') { results.classList.add('hidden'); input.blur(); } },
  });
  document.addEventListener('click', (e) => { if (!e.target.closest('.search-box')) results.classList.add('hidden'); });
  return h('div', { class: 'search-box', style: { position: 'relative' } }, icon('search', { size: 16 }), input, results);
}
