import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { openModal } from './feedback.js';
import { mapView } from './map.js';
import { api } from '../core/api.js';

/* Set an outlet's position from the office: click the map, or paste "lat, lng" (or a Google Maps link) copied from the place.
   Positions set here are trusted, so agents are checked in against them instead of creating (or faking) their own. */

/** Pulls two coordinates out of "-1.2673, 36.8118", "-1.2673 36.8118" or a maps link containing "@-1.2673,36.8118" / "!3d-1.26!4d36.81". */
export function parseCoords(text) {
  const t = String(text || '');
  const m = t.match(/@(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)/) || t.match(/!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)/) || t.match(/(-?\d{1,2}(?:\.\d+)?)\s*[,;\s]\s*(-?\d{1,3}(?:\.\d+)?)/);
  if (!m) return null;
  const lat = Number(m[1]); const lng = Number(m[2]);
  return Math.abs(lat) <= 90 && Math.abs(lng) <= 180 ? { lat, lng } : null;
}

/**
 * The map pane used by the picker and the outlet panel: search a shop by name, click the map, or paste coordinates.
 * onChange(pos|null, place|null) runs on every change; place is the searched shop when the pin came from a search result.
 */
export function geoPane({ lat, lng, searchText = '', height = 320, onChange } = {}) {
  const cfg = window.APP_CONFIG || {};
  let pos = Number.isFinite(lat) && Number.isFinite(lng) ? { lat, lng } : null;
  let place = null;
  const q = h('input', { class: 'input', type: 'search', value: searchText || '', placeholder: 'Search a shop or place by name, e.g. the shop name and area', 'aria-label': 'Search a place', style: { flex: '1' } });
  const go = h('button', { class: 'btn', type: 'button' }, icon('search', { size: 14 }), 'Search');
  const results = h('div', { class: 'stack sm', 'aria-live': 'polite' });
  const readout = h('div', { class: 'small', 'aria-live': 'polite' });
  const paste = h('input', { class: 'input', type: 'text', placeholder: 'Or paste coordinates or a Google Maps link, e.g. -1.2673, 36.8118', 'aria-label': 'Paste coordinates' });
  const marks = () => (pos ? [{ lat: pos.lat, lng: pos.lng, label: 'Outlet', tone: 'selected' }] : []);
  const map = mapView({ markers: marks(), height, zoom: pos ? 17 : 12, center: pos ? [pos.lat, pos.lng] : cfg.defaultMapCenter, onPick: (a, b) => { place = null; set({ lat: a, lng: b }, false); } });

  function set(p, recenter = true, fromPlace = null) {
    pos = p; place = fromPlace;
    readout.textContent = pos ? `Pin at ${pos.lat.toFixed(6)}, ${pos.lng.toFixed(6)}` : 'Click the map to drop the pin where the shop entrance is.';
    map.setMarkers(marks());
    onChange?.(pos, place);
  }
  async function search() {
    const term = q.value.trim();
    if (term.length < 3) { results.replaceChildren(h('p', { class: 'muted small' }, 'Type at least 3 letters.')); return; }
    go.disabled = true; results.replaceChildren(h('p', { class: 'muted small' }, 'Searching…'));
    try {
      const rows = (await api.get('places/search', { q: term })).data;
      results.replaceChildren(...(rows.length
        ? rows.map((r) => h('button', { class: 'btn sm', type: 'button', style: { textAlign: 'left', justifyContent: 'flex-start', whiteSpace: 'normal', height: 'auto', padding: '8px 10px' },
          onclick: () => { set({ lat: r.lat, lng: r.lng }, true, r); results.replaceChildren(); } }, h('span', null, h('strong', null, r.name || 'Place'), h('span', { class: 'muted small' }, ` — ${r.address}`))))
        : [h('p', { class: 'muted small' }, 'Nothing found. Try the shop name with the town, or click the map or paste coordinates.')]));
    } catch (e) { results.replaceChildren(h('p', { class: 'muted small' }, e.message || 'Search is not available. Click the map or paste coordinates.')); }
    go.disabled = false;
  }
  go.addEventListener('click', search);
  q.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); search(); } });
  paste.addEventListener('input', () => { const c = parseCoords(paste.value); if (c) set(c); });
  set(pos);

  const el = h('div', { class: 'stack' }, h('div', { class: 'row', style: { gap: '8px' } }, q, go), results, map, paste, readout);
  return {
    el,
    getPos: () => pos,
    getPlace: () => place,
    /** Move the pin from outside (e.g. typed coordinates). */
    setPos: (p) => { if (p && Number.isFinite(p.lat) && Number.isFinite(p.lng)) set({ lat: p.lat, lng: p.lng }); else set(null); },
    setSearchText: (t) => { if (!q.value.trim()) q.value = t || ''; },
  };
}

/** Opens the picker. onUse({lat,lng}, place) runs when the user presses "Use this position"; place is the searched shop, if one was chosen. */
export function openGeoPicker({ lat, lng, title = 'Set position', searchText = '', onUse }) {
  const use = h('button', { class: 'btn primary', type: 'button', disabled: !(Number.isFinite(lat) && Number.isFinite(lng)) }, 'Use this position');
  const pane = geoPane({ lat, lng, searchText, onChange: (p) => { use.disabled = !p; } });
  const m = openModal({
    title, size: 'lg',
    body: h('div', { class: 'stack' }, h('p', { class: 'muted small' }, icon('info', { size: 14 }), ' Search the shop by name, or zoom in and click its entrance. You can also paste coordinates or a Google Maps link below.'), pane.el),
    footer: (close) => [h('button', { class: 'btn', type: 'button', onclick: () => close() }, 'Cancel'), use],
  });
  use.addEventListener('click', () => { const p = pane.getPos(); if (p) { onUse(p, pane.getPlace()); m.close(); } });
}

/** A small button for a form field: opens the picker seeded from the form, then writes the chosen position back. */
export const geoPickButton = (latName = 'latitude', lngName = 'longitude') => (form) => h('button', {
  class: 'btn sm', type: 'button', style: { marginTop: '6px' },
  onclick: () => { const v = form.read(); openGeoPicker({ lat: v[latName], lng: v[lngName], searchText: [v.name, v.town].filter(Boolean).join(' '), onUse: (p, pl) => { form.set(latName, p.lat); form.set(lngName, p.lng); if (pl && !v.name && pl.name) form.set('name', pl.name); if (pl && !v.address && pl.address) form.set('address', pl.address); } }); },
}, icon('pin', { size: 14 }), 'Pick on map / paste coordinates');
