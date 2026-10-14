import { session } from './auth.js';

export class ApiError extends Error {
  constructor(message, { status = 0, code = null, errors = {}, requestId = null } = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status; this.code = code; this.errors = errors; this.requestId = requestId;
  }
  /** First validation message for a field, if any. */
  field(name) { const e = this.errors?.[name]; return Array.isArray(e) ? e[0] : e || null; }
}

const base = () => (window.APP_CONFIG?.apiBase || '/api/v1').replace(/\/$/, '');
const TIMEOUT_MS = 30000;
const listeners = new Set();
export const onUnauthorized = (fn) => { listeners.add(fn); return () => listeners.delete(fn); };

function buildUrl(path, query) {
  const url = new URL(`${base()}/${path.replace(/^\//, '')}`, window.location.origin);
  for (const [k, v] of Object.entries(query || {})) {
    if (v === undefined || v === null || v === '') continue;
    url.searchParams.set(k, String(v));
  }
  return url;
}

async function request(method, path, { query, body, signal, raw = false, headers = {} } = {}) {
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), TIMEOUT_MS);
  signal?.addEventListener('abort', () => ctrl.abort());

  const init = { method, signal: ctrl.signal, headers: { Accept: 'application/json', ...headers }, credentials: 'omit', cache: 'no-store' };
  const token = session.token;
  if (token) init.headers.Authorization = `Bearer ${token}`;
  if (body instanceof FormData) init.body = body;
  else if (body !== undefined) { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(body); }

  let res;
  try {
    res = await fetch(buildUrl(path, query), init);
  } catch (e) {
    if (e.name === 'AbortError') throw new ApiError(signal?.aborted ? 'Request cancelled.' : 'The server took too long to respond.', { code: 'timeout' });
    throw new ApiError('Cannot reach the server. Check your connection and try again.', { code: 'network' });
  } finally {
    clearTimeout(timer);
  }

  if (raw) {
    if (!res.ok) throw await toError(res);
    return res;
  }
  if (res.status === 204) return { data: null, meta: {}, message: '' };

  let payload = null;
  try { payload = await res.json(); } catch { /* non-JSON error page */ }
  if (!res.ok || payload?.success === false) {
    const err = await toError(res, payload);
    if (res.status === 401 && token) listeners.forEach((fn) => fn(err));
    throw err;
  }
  return { data: payload?.data ?? null, meta: payload?.meta ?? {}, message: payload?.message ?? '' };
}

async function toError(res, payload) {
  if (payload === undefined) { try { payload = await res.json(); } catch { payload = null; } }
  const fallback = { 401: 'Your session has expired. Please sign in again.', 403: 'You are not allowed to do that.', 404: 'Not found.', 405: 'Not allowed.', 409: 'This conflicts with the current state.', 413: 'The file is too large.', 422: 'Please correct the highlighted fields.', 423: 'This account is temporarily locked.', 429: 'Too many requests. Please wait a moment.', 503: 'The service is temporarily unavailable.' };
  return new ApiError(payload?.message || fallback[res.status] || `Request failed (${res.status}).`, {
    status: res.status, code: payload?.code ?? null, errors: payload?.errors ?? {}, requestId: payload?.request_id ?? res.headers.get('X-Request-Id'),
  });
}

export const api = {
  get: (path, query, opts) => request('GET', path, { query, ...opts }),
  post: (path, body, opts) => request('POST', path, { body: body ?? {}, ...opts }),
  put: (path, body, opts) => request('PUT', path, { body: body ?? {}, ...opts }),
  patch: (path, body, opts) => request('PATCH', path, { body: body ?? {}, ...opts }),
  delete: (path, opts) => request('DELETE', path, opts),
  /** Authenticated download (CSV exports, documents). Triggers a browser save. */
  async download(path, query, fallbackName = 'download') {
    const res = await request('GET', path, { query, raw: true, headers: { Accept: '*/*' } });
    const blob = await res.blob();
    const m = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(res.headers.get('Content-Disposition') || '');
    const name = m ? decodeURIComponent(m[1]) : fallbackName;
    const url = URL.createObjectURL(blob);
    const a = Object.assign(document.createElement('a'), { href: url, download: name });
    document.body.append(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 10000);
  },
  /** Authenticated image fetch -> object URL (the API never serves evidence without a token). */
  async blobUrl(path) {
    const res = await request('GET', path, { raw: true, headers: { Accept: '*/*' } });
    return URL.createObjectURL(await res.blob());
  },
};

/** Cancels the previous in-flight request of the same key (typing in a search box, switching tabs). */
const inflight = new Map();
export function latest(key) {
  inflight.get(key)?.abort();
  const ctrl = new AbortController();
  inflight.set(key, ctrl);
  return ctrl.signal;
}
