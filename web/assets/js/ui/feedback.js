import { h, clear } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { toneFor, titleCase } from '../core/format.js';

/* ---------- toasts ---------- */
export function toast(message, kind = 'ok', ms = 4500) {
  const host = document.getElementById('toasts');
  if (!host) return;
  const el = h('div', { class: ['toast', kind === 'ok' ? '' : kind], role: kind === 'error' ? 'alert' : 'status' },
    icon(kind === 'error' ? 'alert' : kind === 'warn' ? 'alert' : 'checkCircle', { size: 18 }),
    h('div', null, message),
    h('button', { type: 'button', 'aria-label': 'Dismiss', onclick: () => el.remove() }, icon('x', { size: 14 })));
  host.append(el);
  setTimeout(() => el.remove(), ms);
}
export const toastError = (err, fallback = 'Something went wrong.') => toast(err?.message || fallback, 'error', 7000);

/* ---------- badges ---------- */
export function badge(value, { tone, label } = {}) {
  if (value === null || value === undefined || value === '') return h('span', { class: 'muted' }, '—');
  return h('span', { class: ['badge', tone ?? toneFor(value)] }, label ?? titleCase(value));
}

/* ---------- empty / loading / error ---------- */
export function empty(title, text, action) {
  return h('div', { class: 'empty' }, icon('layers', { size: 36 }), h('h3', null, title), text && h('p', null, text), action && h('div', { style: { marginTop: '14px' } }, action));
}
export function loading(label = 'Loading…') {
  return h('div', { class: 'empty', role: 'status' }, h('div', { class: 'spinner' }), h('p', { style: { marginTop: '10px' } }, label));
}
export function errorBox(err, retry) {
  return h('div', { class: 'alert error', role: 'alert' }, icon('alert'),
    h('div', { style: { flex: 1 } }, h('strong', null, err?.status === 403 ? 'Access denied' : 'Could not load this'), h('div', null, err?.message || 'Unexpected error.'),
      err?.requestId && h('div', { class: 'small mono muted' }, `Reference: ${err.requestId}`)),
    retry && h('button', { class: 'btn sm', type: 'button', onclick: retry }, 'Retry'));
}

/** Render an async loader into a container with loading + error states. Returns a reload function. */
export function asyncView(container, loader, render) {
  let seq = 0;
  const run = async () => {
    const mine = ++seq;
    clear(container).append(loading());
    try {
      const data = await loader();
      if (mine !== seq || !container.isConnected) return;
      clear(container);
      const out = render(data, run);
      if (out) container.append(out);
    } catch (err) {
      if (mine !== seq || err?.code === 'timeout' && err.message === 'Request cancelled.') return;
      clear(container).append(errorBox(err, run));
    }
  };
  run();
  return run;
}

/* ---------- modal / drawer ---------- */
const stack = [];
export function openModal({ title, body, footer, size = '', drawer = false, onClose, dismissible = true }) {
  const previous = document.activeElement;
  const titleId = `m${Math.random().toString(36).slice(2, 8)}`;
  const close = (result) => {
    overlay.remove(); stack.splice(stack.indexOf(overlay), 1);
    document.body.style.overflow = stack.length ? 'hidden' : '';
    previous?.focus?.();
    onClose?.(result);
  };
  const box = h('div', { class: ['modal', size], role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': titleId, tabindex: '-1' },
    h('div', { class: 'modal-head' }, h('h2', { id: titleId }, title), dismissible && h('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Close', onclick: () => close() }, icon('x'))),
    h('div', { class: 'modal-body' }, body),
    footer && h('div', { class: 'modal-foot' }, typeof footer === 'function' ? footer(close) : footer));
  const overlay = h('div', { class: ['overlay', drawer && 'right'], onmousedown: (e) => { if (dismissible && e.target === overlay) close(); } }, box);
  overlay.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && dismissible && stack[stack.length - 1] === overlay) { e.stopPropagation(); close(); }
    if (e.key === 'Tab') trapFocus(e, box);
  });
  document.body.append(overlay); stack.push(overlay);
  document.body.style.overflow = 'hidden';
  (box.querySelector('[autofocus],input,select,textarea') || box).focus();
  return { close, box, body: box.querySelector('.modal-body') };
}

function trapFocus(e, box) {
  const f = [...box.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])')].filter((x) => x.offsetParent !== null);
  if (!f.length) return;
  const first = f[0]; const last = f[f.length - 1];
  if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
  else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
}

/** Promise-based confirmation for destructive actions. Optionally asks for a reason. */
export function confirmDialog({ title, message, confirmLabel = 'Confirm', danger = false, reason = null }) {
  return new Promise((resolve) => {
    let input = null;
    const body = h('div', { class: 'stack sm' }, h('p', null, message),
      reason && h('div', { class: 'field' }, h('label', { for: 'cd-reason' }, reason.label, reason.required && h('span', { class: 'req' }, ' *')), (input = h('textarea', { id: 'cd-reason', class: 'textarea', maxlength: 1000 }))));
    let settled = false;
    const done = (v) => { if (!settled) { settled = true; resolve(v); } };
    const m = openModal({
      title, body, size: 'sm', onClose: () => done(null),
      footer: (close) => [
        h('button', { class: 'btn', type: 'button', onclick: () => close() }, 'Cancel'),
        h('button', { class: ['btn', danger ? 'danger' : 'primary'], type: 'button', onclick: () => {
          const text = input?.value.trim() ?? '';
          if (reason?.required && !text) { input.setAttribute('aria-invalid', 'true'); input.focus(); return; }
          done(reason ? { reason: text } : true); close();
        } }, confirmLabel),
      ],
    });
    return m;
  });
}

/** Button that shows a spinner and ignores double clicks while `handler` runs. */
export function actionButton(label, handler, { cls = 'btn', iconName = null, title } = {}) {
  const btn = h('button', { class: cls, type: 'button', title, onclick: async () => {
    if (btn.disabled) return;
    btn.disabled = true; const prev = [...btn.childNodes];
    clear(btn).append(h('span', { class: 'spinner' }), ' ', label);
    try { await handler(btn); } finally { if (btn.isConnected) { clear(btn); btn.append(...prev); btn.disabled = false; } }
  } }, iconName && icon(iconName, { size: 16 }), label);
  return btn;
}

export function tabs(items, { initial, onChange } = {}) {
  const panel = h('div', { role: 'tabpanel' });
  let current = initial || items[0].key;
  const bar = h('div', { class: 'tabs', role: 'tablist' });
  const paint = () => {
    clear(bar);
    items.forEach((t) => bar.append(h('button', { type: 'button', role: 'tab', 'aria-selected': String(t.key === current), onclick: () => select(t.key) }, t.label)));
  };
  const select = (key) => {
    current = key; paint(); clear(panel);
    const item = items.find((t) => t.key === key);
    const out = item.render(panel);
    if (out) panel.append(out);
    onChange?.(key);
  };
  paint(); select(current);
  return h('div', null, bar, panel);
}

export function kv(pairs) {
  return h('dl', { class: 'kv' }, pairs.filter(Boolean).flatMap(([k, v]) => [h('dt', null, k), h('dd', null, v ?? h('span', { class: 'muted' }, '—'))]));
}

export function stat({ label, value, hint, icon: ic = 'activity' }) {
  return h('div', { class: 'card stat' }, h('div', { class: 'label' }, label, h('span', { class: 'icon-wrap' }, icon(ic, { size: 16 }))), h('div', { class: 'value' }, value ?? '—'), hint && h('div', { class: 'hint' }, hint));
}

export function pageHead({ title, subtitle, actions }) {
  return h('div', { class: 'page-head' }, h('div', { class: 'titles' }, h('h1', null, title), subtitle && h('p', null, subtitle)), actions && h('div', { class: 'actions' }, actions));
}

export function card(title, body, { actions, flush = false } = {}) {
  return h('section', { class: 'card' }, title && h('div', { class: 'card-head' }, h('h3', null, title), actions), h('div', { class: ['card-body', flush && 'flush'] }, body));
}
