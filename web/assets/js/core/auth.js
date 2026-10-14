/* Session state. The token lives in sessionStorage (cleared when the tab closes) — never localStorage. */
const KEY = 'fo.session.v1';

function load() {
  try { return JSON.parse(sessionStorage.getItem(KEY) || 'null') || { token: null, user: null, expiresAt: null }; }
  catch { return { token: null, user: null, expiresAt: null }; }
}
function save(s) { try { sessionStorage.setItem(KEY, JSON.stringify(s)); } catch { /* private mode: keep in memory only */ } }

let state = load();
const subs = new Set();
const emit = () => subs.forEach((fn) => fn(state));

export const session = {
  get token() { return state.token; },
  get user() { return state.user; },
  get expiresAt() { return state.expiresAt; },
  get isAuthenticated() { return !!state.token && !!state.user && (!state.expiresAt || new Date(state.expiresAt) > new Date()); },
  set({ token, user, expires_at }) { state = { token: token ?? state.token, user: user ?? state.user, expiresAt: expires_at ?? state.expiresAt }; save(state); emit(); },
  setUser(user) { state = { ...state, user }; save(state); emit(); },
  clear() { state = { token: null, user: null, expiresAt: null }; try { sessionStorage.removeItem(KEY); } catch { /* ignore */ } emit(); },
  subscribe(fn) { subs.add(fn); return () => subs.delete(fn); },
};

export const can = (perm) => !!state.user?.permissions?.includes(perm);
export const canAny = (...perms) => perms.flat().some(can);
export const scopeType = () => state.user?.scope_type || 'none';
export const isClientUser = () => scopeType() === 'client';
export const isFieldAgent = () => scopeType() === 'own';
export const roleName = () => state.user?.roles?.[0]?.name || '';
export const hasRole = (slug) => !!state.user?.roles?.some((r) => r.slug === slug);
