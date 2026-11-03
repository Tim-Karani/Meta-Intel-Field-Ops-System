import { h } from '../core/dom.js';
import { lookup } from '../core/lookups.js';
import { focus, focusOnRoute } from '../core/scope.js';
import { scopeType } from '../core/auth.js';
import { resolve } from '../core/router.js';

/* Top-bar client / project picker. Shown only on pages it applies to, and only to people who work across projects. */
export function scopeBar() {
  const clientSel = h('select', { class: 'select', 'aria-label': 'Client' });
  const projectSel = h('select', { class: 'select', 'aria-label': 'Project' });
  const bar = h('div', { class: 'scope-bar hidden', role: 'group', 'aria-label': 'Client and project focus' }, clientSel, projectSel);
  let ready = false;

  const fill = (sel, blank, opts, value) => {
    sel.replaceChildren(h('option', { value: '' }, blank), ...opts.map((o) => h('option', { value: o.value }, o.label)));
    sel.value = opts.some((o) => o.value === value) ? value : '';
  };

  async function load() {
    if (!['all', 'campaigns', 'team'].includes(scopeType())) return false;
    const f = focus.get();
    const [clients, projects] = await Promise.all([lookup('clients').catch(() => []), lookup('campaigns', f.client_id ? { client_id: f.client_id } : {}).catch(() => [])]);
    if (!clients.length && !projects.length) return false;
    fill(clientSel, 'All clients', clients, f.client_id);
    fill(projectSel, 'All projects', projects, f.campaign_id);
    // A remembered choice that no longer exists is dropped rather than silently hiding everything.
    if (clientSel.value !== f.client_id || projectSel.value !== f.campaign_id) focus.set({ client_id: clientSel.value, campaign_id: projectSel.value });
    clientSel.classList.toggle('hidden', !clients.length);
    return true;
  }

  const apply = async () => { await load(); resolve(); };
  clientSel.addEventListener('change', () => { focus.set({ client_id: clientSel.value, campaign_id: '' }); apply(); });
  projectSel.addEventListener('change', () => { focus.set({ client_id: clientSel.value, campaign_id: projectSel.value }); resolve(); });

  return {
    el: bar,
    /** Called on every route change. */
    async update(path) {
      const show = focusOnRoute(path);
      if (!show) { bar.classList.add('hidden'); return; }
      if (!ready) ready = await load();
      bar.classList.toggle('hidden', !ready);
    },
  };
}
