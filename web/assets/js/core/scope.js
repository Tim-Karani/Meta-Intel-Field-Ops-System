/* The client / project a person is focused on. It narrows the working lists to one client and/or project instead of
   showing everything added together. It only filters what the user could already see; the server enforces access. */
import { session } from './auth.js';

const KEY = 'fo.focus.v1';
const subs = new Set();
let state = { client_id: '', campaign_id: '' };
try { const s = JSON.parse(sessionStorage.getItem(KEY) || 'null'); if (s) state = { client_id: s.client_id || '', campaign_id: s.campaign_id || '' }; } catch { /* in-memory only */ }

/** Pages (and the list endpoints behind them) the focus applies to. */
export const FOCUS_ROUTES = ['/assignments', '/journey-plans', '/attendance', '/attendance-board', '/field-agents', '/jp-requests', '/visits', '/issues', '/evidence', '/sales', '/stock', '/osa', '/merchandising', '/reports'];
/** Planning and insight list pages: focus applies to the list itself, not to the detail pages opened from it. */
export const FOCUS_LIST_ROUTES = ['/clients', '/campaigns', '/activities', '/forms', '/teams', '/territories', '/locations', '/products', '/report-schedules', '/analytics'];
const FOCUS_ENDPOINTS = /^(assignments|journey-plans|jp-requests|visits|issues|photos|sales|osa|merchandising|attendance|attendance\/board|agents\/live|clients|campaigns|activities|forms|teams|territories|routes|locations|products|report-schedules|reports\/[a-z_]+(\/export)?)$/;

export const focusOnRoute = (path = (window.location.hash.replace(/^#/, '').split('?')[0] || '/')) => FOCUS_LIST_ROUTES.includes(path) || FOCUS_ROUTES.some((r) => path === r || path.startsWith(`${r}/`));

export const focus = {
  get: () => ({ ...state }),
  set(next) {
    state = { client_id: next.client_id || '', campaign_id: next.campaign_id || '' };
    try { sessionStorage.setItem(KEY, JSON.stringify(state)); } catch { /* ignore */ }
    subs.forEach((fn) => fn(state));
  },
  clear() { focus.set({}); },
  subscribe(fn) { subs.add(fn); return () => subs.delete(fn); },
};

/** Adds the focus to a list request, unless the caller already chose a client/project or we are not on a focused page. */
export function withFocus(path, query) {
  if (!FOCUS_ENDPOINTS.test(path.replace(/^\//, '')) || !focusOnRoute()) return query;
  const q = { ...(query || {}) };
  if (!q.client_id && state.client_id) q.client_id = state.client_id;
  if (!q.campaign_id && state.campaign_id) q.campaign_id = state.campaign_id;
  return q;
}

// A different person signing in on the same tab must not inherit the previous focus.
session.subscribe((s) => { if (!s.token && (state.client_id || state.campaign_id)) focus.clear(); });
