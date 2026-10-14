import { h, mount } from './core/dom.js';
import { session } from './core/auth.js';
import { api, onUnauthorized } from './core/api.js';
import { defineRoutes, onRoute, startRouter, navigate } from './core/router.js';
import { ROUTES } from './routes.js';
import { createShell } from './shell.js';
import { errorBox, toast } from './ui/feedback.js';
import { icon } from './core/icons.js';

defineRoutes(ROUTES);
const app = document.getElementById('app');
let shell = null;
let renderSeq = 0;

function homeFor(user) {
  const perms = user?.permissions || [];
  if (user?.scope_type === 'client') return '/portal';
  return perms.includes('dashboard.view') ? '/dashboard' : '/profile';
}

async function logout(silent = false) {
  try { if (!silent && session.token) await api.post('auth/logout'); } catch { /* token may already be invalid */ }
  shell?.dispose(); shell = null;
  session.clear();
  navigate('/login', { replace: true });
}
onUnauthorized(() => { if (session.token) { toast('Your session has expired. Please sign in again.', 'warn'); logout(true); } });

onRoute(async (state) => {
  const seq = ++renderSeq;

  if (state.status === 401) { navigate(`/login`, { replace: true }); return; }
  if (state.path === '/' || state.path === '') { navigate(session.isAuthenticated ? homeFor(session.user) : '/login', { replace: true }); return; }

  // Already signed in and visiting the login screen: go home (but not for password-reset links).
  if (state.route?.public && session.isAuthenticated && state.route.path === '/login') { navigate(homeFor(session.user), { replace: true }); return; }

  document.title = `${state.route?.title || 'Not found'} · ${window.APP_CONFIG?.appName || 'Field Operations'}`;

  if (state.route?.layout === 'bare') {
    shell?.dispose(); shell = null;
    const holder = h('div'); mount(app, holder);
    const mod = await state.route.load();
    if (seq === renderSeq) await mod.default({ container: holder, route: state.route, params: state.params, query: state.query, navigate });
    return;
  }

  if (!shell) { shell = createShell({ onLogout: () => logout() }); mount(app, shell.el); }
  shell.setActive(state.path);
  shell.setCrumbs(state.route?.crumbs || [], state.route?.title || 'Not found');
  const view = shell.main; mount(view, h('div', { class: 'empty', role: 'status' }, h('div', { class: 'spinner' })));
  window.scrollTo({ top: 0 });

  if (state.status === 404) { mount(view, notFound('Page not found', 'The page you are looking for does not exist.')); return; }
  if (state.status === 403) { mount(view, notFound('Access denied', 'Your role does not include access to this page.', 'shield')); return; }

  try {
    const mod = await state.route.load();
    if (seq !== renderSeq) return;
    mount(view);
    await mod.default({ container: view, route: state.route, params: state.params, query: state.query, navigate, shell });
    view.focus({ preventScroll: true });
  } catch (err) {
    if (seq !== renderSeq) return;
    console.error(err);
    mount(view, errorBox(err, () => startRouter()));
  }
});

// Revalidate the session profile on boot so stale permissions never linger in sessionStorage.
async function boot() {
  if (session.isAuthenticated) {
    try { const { data } = await api.get('auth/me'); session.setUser(data); }
    catch (e) { if (e.status === 401 || e.status === 403) session.clear(); }
  } else if (session.token) session.clear();
  startRouter();
}

function notFound(title, text, ic = 'search') {
  return h('div', { class: 'empty' }, icon(ic, { size: 36 }), h('h1', { style: { fontSize: '1.15rem' } }, title), h('p', null, text), h('p', { style: { marginTop: '12px' } }, h('a', { class: 'btn', href: '#/' }, 'Go to start page')));
}

window.addEventListener('unhandledrejection', (e) => console.error('Unhandled', e.reason));
boot();
