import { session, canAny } from './auth.js';

/* Hash router with auth + permission guards. Route table lives in ../routes.js. */
let table = [];
let current = null;
const listeners = new Set();

const compile = (path) => {
  const keys = [];
  const re = new RegExp(`^${path.replace(/\/:([A-Za-z]+)/g, (_, k) => { keys.push(k); return '/([^/]+)'; })}/?$`);
  return { re, keys };
};

export function defineRoutes(routes) { table = routes.map((r) => ({ ...r, ...compile(r.path) })); }
export const onRoute = (fn) => { listeners.add(fn); return () => listeners.delete(fn); };
export const currentRoute = () => current;

export function parseHash() {
  const raw = window.location.hash.replace(/^#/, '') || '/';
  const [path, qs] = raw.split('?');
  return { path: path || '/', query: Object.fromEntries(new URLSearchParams(qs || '')) };
}

export function navigate(path, { replace = false } = {}) {
  const target = `#${path}`;
  if (window.location.hash === target) { resolve(); return; }
  if (replace) history.replaceState(null, '', target); else window.location.hash = path;
  if (replace) resolve();
}

export const href = (path) => `#${path}`;

export function resolve() {
  const { path, query } = parseHash();
  let match = null;
  for (const r of table) {
    const m = r.re.exec(path);
    if (m) { match = { route: r, params: Object.fromEntries(r.keys.map((k, i) => [k, decodeURIComponent(m[i + 1])])) }; break; }
  }
  let state;
  if (!match) state = { status: 404, path, query, params: {} };
  else if (match.route.public) state = { status: 200, route: match.route, path, query, params: match.params };
  else if (!session.isAuthenticated) state = { status: 401, path, query, params: {} };
  else if (match.route.perm && !canAny(match.route.perm)) state = { status: 403, route: match.route, path, query, params: match.params };
  else state = { status: 200, route: match.route, path, query, params: match.params };
  current = state;
  listeners.forEach((fn) => fn(state));
}

export function startRouter() {
  window.addEventListener('hashchange', resolve);
  resolve();
}
