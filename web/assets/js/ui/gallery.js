import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api } from '../core/api.js';
import { can } from '../core/auth.js';
import { fmtDateTime, fmtBytes } from '../core/format.js';
import { badge, openModal, confirmDialog, toast, toastError, empty } from './feedback.js';
import { osmLink } from './map.js';

/** Images need the bearer token, so they are fetched as blobs and revoked when the node leaves the page. */
export function secureImage(photo, { alt = 'Evidence photo', cls = '' } = {}) {
  const img = h('img', { alt, class: cls, loading: 'lazy' });
  const holder = h('div', { class: 'thumb-loading' }, h('div', { class: 'spinner' }));
  let url = null;
  const load = () => api.blobUrl(`photos/${photo.id}/file`).then((u) => { url = u; img.src = u; holder.replaceWith(img); }).catch(() => { holder.replaceChildren(icon('alert'), h('span', { class: 'small' }, 'Unavailable')); });
  const wrap = h('div', { class: 'secure-img' }, holder);
  if ('IntersectionObserver' in window) {
    const io = new IntersectionObserver((es) => { if (es.some((e) => e.isIntersecting)) { io.disconnect(); load(); } }, { rootMargin: '200px' });
    queueMicrotask(() => io.observe(wrap));
  } else load();
  const obs = new MutationObserver(() => { if (!wrap.isConnected) { obs.disconnect(); if (url) URL.revokeObjectURL(url); } });
  queueMicrotask(() => obs.observe(document.body, { childList: true, subtree: true }));
  // keep the img inside the wrapper once loaded
  img.addEventListener('load', () => { if (!wrap.contains(img)) wrap.replaceChildren(img); });
  return wrap;
}

export function photoGrid(photos, { onChange } = {}) {
  if (!photos.length) return empty('No photos', 'Evidence appears here once agents upload it.');
  return h('div', { class: 'photo-grid' }, photos.map((p) => h('button', { type: 'button', class: 'photo-tile', 'aria-label': `Open ${p.kind || 'photo'}`, onclick: () => openPhoto(p, onChange) },
    secureImage(p), h('span', { class: 'tile-badge' }, badge(p.review_status)))));
}

export function openPhoto(p, onChange) {
  const canReview = can('evidence.review');
  const body = h('div', { class: 'stack sm' },
    h('div', { class: 'photo-full' }, secureImage(p, { cls: 'full' })),
    h('dl', { class: 'kv' },
      h('dt', null, 'Status'), h('dd', null, badge(p.review_status)),
      h('dt', null, 'Visit'), h('dd', null, p.visit ? [p.visit.location?.name, p.visit.campaign?.name].filter(Boolean).join(' · ') || p.visit_id : p.visit_id),
      h('dt', null, 'Captured'), h('dd', null, fmtDateTime(p.captured_at || p.created_at)),
      h('dt', null, 'Uploaded by'), h('dd', null, p.photographer?.name || '—'),
      h('dt', null, 'File'), h('dd', null, `${p.width || '?'}×${p.height || '?'} · ${fmtBytes(p.size_bytes)}`),
      p.latitude != null && h('dt', null, 'Photo location'), p.latitude != null && h('dd', null, h('a', { href: osmLink(p.latitude, p.longitude), target: '_blank', rel: 'noopener noreferrer' }, `${Number(p.latitude).toFixed(5)}, ${Number(p.longitude).toFixed(5)}`)),
      p.review_note && h('dt', null, 'Review note'), p.review_note && h('dd', null, p.review_note)));
  const review = (status) => async () => {
    let note;
    if (status === 'rejected') {
      const r = await confirmDialog({ title: 'Reject this photo?', message: 'The agent will see the reason.', confirmLabel: 'Reject', danger: true, reason: { label: 'Reason', required: true } });
      if (!r) return; note = r.reason;
    }
    try { await api.post(`photos/${p.id}/review`, { status, note }); toast(status === 'accepted' ? 'Photo accepted.' : 'Photo rejected.'); m.close(); onChange?.(); } catch (e) { toastError(e); }
  };
  const m = openModal({ title: p.kind === 'signature' ? 'Signature' : 'Evidence photo', body, size: 'lg',
    footer: (close) => [h('button', { class: 'btn', type: 'button', onclick: () => close() }, 'Close'),
      canReview && p.review_status !== 'rejected' && h('button', { class: 'btn danger', type: 'button', onclick: review('rejected') }, 'Reject'),
      canReview && p.review_status !== 'accepted' && h('button', { class: 'btn primary', type: 'button', onclick: review('accepted') }, 'Accept')] });
  return m;
}
