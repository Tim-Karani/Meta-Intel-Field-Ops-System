import { h, svg, onDetach } from '../core/dom.js';

/* Minimal slippy map (Web Mercator, 256px tiles, drag + wheel + buttons). No third-party library:
   tiles are plain <img> elements from the configured tile server; markers are drawn by us. */
const TILE = 256;
const clampLat = (lat) => Math.max(-85.0511, Math.min(85.0511, lat));
const project = (lat, lng, z) => {
  const s = TILE * 2 ** z; const sin = Math.sin((clampLat(lat) * Math.PI) / 180);
  return { x: ((lng + 180) / 360) * s, y: (0.5 - Math.log((1 + sin) / (1 - sin)) / (4 * Math.PI)) * s };
};
const unproject = (x, y, z) => {
  const s = TILE * 2 ** z; const n = Math.PI - (2 * Math.PI * y) / s;
  return { lat: (180 / Math.PI) * Math.atan(0.5 * (Math.exp(n) - Math.exp(-n))), lng: (x / s) * 360 - 180 };
};
const metersPerPixel = (lat, z) => (156543.03392 * Math.cos((lat * Math.PI) / 180)) / 2 ** z;

const COLORS = { valid: '#16a34a', warning: '#f59e0b', suspicious: '#dc2626', rejected: '#7f1d1d', default: '#1ba4a8', selected: '#3a5a96' };

/**
 * markers: [{ lat, lng, label, tone, onClick, radiusM }]   radiusM draws a geofence circle.
 * Returns an element with .setMarkers(markers) and .fit().
 */
export function mapView({ markers = [], height = 380, zoom, center, interactive = true } = {}) {
  const cfg = window.APP_CONFIG || {};
  const tiles = cfg.mapTileUrl || '';
  const el = h('div', { class: 'map', style: { height: `${height}px` }, role: 'application', 'aria-label': 'Map', tabindex: interactive ? 0 : null });
  const tileLayer = h('div', { style: { position: 'absolute', inset: 0 } });
  const overlay = svg('svg', { style: 'position:absolute;inset:0;width:100%;height:100%;pointer-events:none;overflow:visible' });
  const markerLayer = h('div', { style: { position: 'absolute', inset: 0 } });
  const tip = h('div', { class: 'tip hidden' });
  el.append(tileLayer, overlay, markerLayer, tip);
  if (tiles && cfg.mapAttribution) el.append(h('div', { class: 'attr' }, cfg.mapAttribution));

  let items = markers;
  let view = { lat: (center || cfg.defaultMapCenter || [0, 0])[0], lng: (center || cfg.defaultMapCenter || [0, 0])[1], z: zoom ?? 12 };
  const size = () => ({ w: el.clientWidth || 600, h: el.clientHeight || height });

  function draw() {
    const { w, h: hh } = size();
    const c = project(view.lat, view.lng, view.z);
    const left = c.x - w / 2; const top = c.y - hh / 2;
    const toScreen = (lat, lng) => { const p = project(lat, lng, view.z); return { x: p.x - left, y: p.y - top }; };

    if (tiles) {
      const n = 2 ** view.z; const frag = document.createDocumentFragment();
      for (let tx = Math.floor(left / TILE); tx <= Math.floor((left + w) / TILE); tx++) {
        for (let ty = Math.floor(top / TILE); ty <= Math.floor((top + hh) / TILE); ty++) {
          if (ty < 0 || ty >= n) continue;
          const wrapped = ((tx % n) + n) % n;
          const img = new Image(TILE, TILE);
          img.className = 'tile'; img.alt = ''; img.draggable = false; img.referrerPolicy = 'no-referrer'; img.loading = 'lazy';
          img.style.left = `${tx * TILE - left}px`; img.style.top = `${ty * TILE - top}px`;
          img.src = tiles.replace('{z}', view.z).replace('{x}', wrapped).replace('{y}', ty);
          img.onerror = () => { img.style.display = 'none'; };
          frag.append(img);
        }
      }
      tileLayer.replaceChildren(frag);
    }

    overlay.replaceChildren();
    markerLayer.replaceChildren();
    for (const m of items) {
      const p = toScreen(m.lat, m.lng);
      if (p.x < -40 || p.y < -40 || p.x > w + 40 || p.y > hh + 40) continue;
      if (m.radiusM) {
        overlay.append(svg('circle', { cx: p.x, cy: p.y, r: Math.max(4, m.radiusM / metersPerPixel(m.lat, view.z)), fill: 'rgba(27,164,168,.15)', stroke: '#1ba4a8', 'stroke-width': 1.5, 'stroke-dasharray': '4 3' }));
      }
      const color = COLORS[m.tone] || COLORS.default;
      const pin = h('div', { class: 'marker', style: { left: `${p.x}px`, top: `${p.y}px`, cursor: m.onClick ? 'pointer' : 'default' }, tabindex: m.onClick ? 0 : null, role: m.onClick ? 'button' : null, 'aria-label': m.label || 'Location' });
      const s = svg('svg', { viewBox: '0 0 24 24' }, svg('path', { d: 'M12 22s7-6.5 7-12a7 7 0 0 0-14 0c0 5.5 7 12 7 12z', fill: color, stroke: '#fff', 'stroke-width': 1.5 }), svg('circle', { cx: 12, cy: 10, r: 2.6, fill: '#fff' }));
      pin.append(s);
      if (m.label) {
        pin.addEventListener('mouseenter', () => { tip.textContent = m.label; tip.style.left = `${p.x}px`; tip.style.top = `${p.y}px`; tip.classList.remove('hidden'); });
        pin.addEventListener('mouseleave', () => tip.classList.add('hidden'));
      }
      if (m.onClick) { pin.addEventListener('click', (e) => { e.stopPropagation(); m.onClick(m); }); pin.addEventListener('keydown', (e) => { if (e.key === 'Enter') m.onClick(m); }); }
      markerLayer.append(pin);
    }
  }

  function fit() {
    const pts = items.filter((m) => Number.isFinite(m.lat) && Number.isFinite(m.lng));
    if (!pts.length) return draw();
    const { w, h: hh } = size();
    const lats = pts.map((p) => p.lat); const lngs = pts.map((p) => p.lng);
    const minLat = Math.min(...lats); const maxLat = Math.max(...lats); const minLng = Math.min(...lngs); const maxLng = Math.max(...lngs);
    view.lat = (minLat + maxLat) / 2; view.lng = (minLng + maxLng) / 2;
    let z = 17;
    while (z > 2) {
      const a = project(maxLat, minLng, z); const b = project(minLat, maxLng, z);
      if (Math.abs(b.x - a.x) < w - 120 && Math.abs(b.y - a.y) < hh - 120) break;
      z--;
    }
    view.z = pts.length === 1 ? (zoom ?? 16) : z;
    draw();
  }

  if (interactive) {
    let drag = null;
    el.addEventListener('pointerdown', (e) => { if (e.target.closest('.zoom,.marker')) return; drag = { x: e.clientX, y: e.clientY, p: project(view.lat, view.lng, view.z) }; el.setPointerCapture(e.pointerId); el.classList.add('dragging'); });
    el.addEventListener('pointermove', (e) => { if (!drag) return; const np = unproject(drag.p.x - (e.clientX - drag.x), drag.p.y - (e.clientY - drag.y), view.z); view.lat = clampLat(np.lat); view.lng = np.lng; draw(); });
    const end = () => { drag = null; el.classList.remove('dragging'); };
    el.addEventListener('pointerup', end); el.addEventListener('pointercancel', end);
    el.addEventListener('wheel', (e) => { e.preventDefault(); view.z = Math.max(2, Math.min(18, view.z + (e.deltaY < 0 ? 1 : -1))); draw(); }, { passive: false });
    el.addEventListener('keydown', (e) => { if (e.key === '+') { view.z = Math.min(18, view.z + 1); draw(); } if (e.key === '-') { view.z = Math.max(2, view.z - 1); draw(); } });
    el.append(h('div', { class: 'zoom' },
      h('button', { type: 'button', 'aria-label': 'Zoom in', onclick: () => { view.z = Math.min(18, view.z + 1); draw(); } }, '+'),
      h('button', { type: 'button', 'aria-label': 'Zoom out', onclick: () => { view.z = Math.max(2, view.z - 1); draw(); } }, '−')));
  }

  const ro = new ResizeObserver(() => draw());
  ro.observe(el);
  onDetach(el, () => ro.disconnect());
  requestAnimationFrame(fit);

  return Object.assign(el, { setMarkers(next) { items = next; fit(); }, fit });
}

export const osmLink = (lat, lng) => `https://www.openstreetmap.org/?mlat=${lat}&mlon=${lng}#map=18/${lat}/${lng}`;
