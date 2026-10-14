const cfg = () => window.APP_CONFIG || {};

const dateFmt = () => new Intl.DateTimeFormat(cfg().locale || 'en-KE', { year: 'numeric', month: 'short', day: 'numeric' });
const dateTimeFmt = () => new Intl.DateTimeFormat(cfg().locale || 'en-KE', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
const timeFmt = () => new Intl.DateTimeFormat(cfg().locale || 'en-KE', { hour: '2-digit', minute: '2-digit' });

const parse = (v) => {
  if (!v) return null;
  // Plain dates (YYYY-MM-DD) are calendar dates, not instants: parse as local to avoid off-by-one days.
  const d = /^\d{4}-\d{2}-\d{2}$/.test(v) ? new Date(`${v}T00:00:00`) : new Date(v);
  return Number.isNaN(d.getTime()) ? null : d;
};

export const fmtDate = (v) => { const d = parse(v); return d ? dateFmt().format(d) : '—'; };
export const fmtDateTime = (v) => { const d = parse(v); return d ? dateTimeFmt().format(d) : '—'; };
export const fmtTime = (v) => { const d = parse(v); return d ? timeFmt().format(d) : '—'; };
export const fmtNumber = (v, digits = 0) => (v === null || v === undefined || v === '' ? '—' : new Intl.NumberFormat(cfg().locale || 'en-KE', { maximumFractionDigits: digits }).format(Number(v)));
export const fmtMoney = (v) => (v === null || v === undefined || v === '' ? '—' : new Intl.NumberFormat(cfg().locale || 'en-KE', { style: 'currency', currency: cfg().currency || 'KES', maximumFractionDigits: 2 }).format(Number(v)));
export const fmtPercent = (v, digits = 1) => (v === null || v === undefined || v === '' ? '—' : `${fmtNumber(v, digits)}%`);
export const fmtBytes = (n) => { if (n == null) return '—'; const u = ['B', 'KB', 'MB', 'GB']; let i = 0; let x = Number(n); while (x >= 1024 && i < u.length - 1) { x /= 1024; i++; } return `${x.toFixed(i ? 1 : 0)} ${u[i]}`; };
export const fmtDistance = (m) => (m == null ? '—' : m >= 1000 ? `${(m / 1000).toFixed(2)} km` : `${Math.round(m)} m`);
export const titleCase = (s) => String(s ?? '').replace(/[_-]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
export const initials = (name) => String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0].toUpperCase()).join('');
export const today = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };
export function relTime(v) {
  const d = parse(v); if (!d) return '—';
  const s = Math.round((Date.now() - d.getTime()) / 1000);
  const a = Math.abs(s); const sign = s >= 0 ? 'ago' : 'from now';
  if (a < 45) return 'just now';
  if (a < 3600) return `${Math.round(a / 60)} min ${sign}`;
  if (a < 86400) return `${Math.round(a / 3600)} h ${sign}`;
  if (a < 86400 * 14) return `${Math.round(a / 86400)} d ${sign}`;
  return fmtDate(v);
}

/** Status -> badge tone. One map for every status/outcome in the system. */
const TONES = {
  active: 'ok', approved: 'ok', completed: 'ok', published: 'ok', accepted: 'ok', valid: 'ok', resolved: 'ok', present: 'ok', closed: 'plain', done: 'ok',
  pending: 'warn', planned: 'info', scheduled: 'info', submitted: 'info', under_review: 'info', in_progress: 'info', started: 'info', assigned: 'info', open: 'warn', late: 'warn', warning: 'warn', paused: 'warn', draft: 'plain', medium: 'warn', overdue: 'danger',
  rejected: 'danger', cancelled: 'danger', suspicious: 'danger', critical: 'danger', high: 'danger', escalated: 'danger', absent: 'danger', disabled: 'danger', suspended: 'danger', locked: 'danger', failed: 'danger',
  archived: 'plain', inactive: 'plain', low: 'plain',
};
export const toneFor = (status) => TONES[String(status).toLowerCase()] || '';
