import { svg, h } from '../core/dom.js';
import { fmtNumber } from '../core/format.js';

/* Dependency-free SVG charts. All values come from API data; labels are inserted as text nodes. */
export const PALETTE = ['#1ba4a8', '#3a5a96', '#f59e0b', '#ef4444', '#8b5cf6', '#10b981', '#64748b', '#ec4899'];

const niceMax = (v) => {
  if (v <= 0) return 1;
  const p = 10 ** Math.floor(Math.log10(v)); const n = v / p;
  return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10) * p;
};

export function barChart({ labels, series, height = 240, stacked = false, format = fmtNumber }) {
  const W = 640; const H = height; const m = { t: 12, r: 12, b: 34, l: 44 };
  const iw = W - m.l - m.r; const ih = H - m.t - m.b;
  const totals = labels.map((_, i) => (stacked ? series.reduce((s, x) => s + (x.values[i] || 0), 0) : Math.max(...series.map((x) => x.values[i] || 0))));
  const max = niceMax(Math.max(...totals, 0));
  const root = svg('svg', { class: 'chart', viewBox: `0 0 ${W} ${H}`, role: 'img', 'aria-label': series.map((s) => s.name).join(', ') });
  for (let i = 0; i <= 4; i++) {
    const y = m.t + ih - (ih * i) / 4;
    root.append(svg('line', { x1: m.l, x2: W - m.r, y1: y, y2: y, stroke: '#e2e8f0' }), svg('text', { x: m.l - 6, y: y + 4, 'text-anchor': 'end' }, format((max * i) / 4)));
  }
  const band = iw / Math.max(labels.length, 1);
  const bw = Math.min(40, (band * 0.7) / (stacked ? 1 : series.length));
  labels.forEach((lab, i) => {
    const x0 = m.l + band * i + band / 2;
    let acc = 0;
    series.forEach((s, si) => {
      const v = s.values[i] || 0; const bh = (v / max) * ih;
      const x = stacked ? x0 - bw / 2 : x0 - (bw * series.length) / 2 + bw * si;
      const y = stacked ? m.t + ih - ((acc + v) / max) * ih : m.t + ih - bh;
      acc += v;
      const bar = svg('rect', { x, y, width: bw - 2, height: Math.max(bh, 0), rx: 3, fill: s.color || PALETTE[si % PALETTE.length] });
      bar.append(svg('title', null, `${lab} — ${s.name}: ${format(v)}`));
      root.append(bar);
    });
    if (labels.length <= 16 || i % Math.ceil(labels.length / 12) === 0) root.append(svg('text', { x: x0, y: H - 12, 'text-anchor': 'middle' }, lab));
  });
  return h('div', null, root, legend(series));
}

export function lineChart({ labels, series, height = 240, format = fmtNumber }) {
  const W = 640; const H = height; const m = { t: 12, r: 14, b: 34, l: 44 };
  const iw = W - m.l - m.r; const ih = H - m.t - m.b;
  const max = niceMax(Math.max(...series.flatMap((s) => s.values.map((v) => v || 0)), 0));
  const root = svg('svg', { class: 'chart', viewBox: `0 0 ${W} ${H}`, role: 'img', 'aria-label': series.map((s) => s.name).join(', ') });
  for (let i = 0; i <= 4; i++) {
    const y = m.t + ih - (ih * i) / 4;
    root.append(svg('line', { x1: m.l, x2: W - m.r, y1: y, y2: y, stroke: '#e2e8f0' }), svg('text', { x: m.l - 6, y: y + 4, 'text-anchor': 'end' }, format((max * i) / 4)));
  }
  const px = (i) => m.l + (labels.length <= 1 ? iw / 2 : (iw * i) / (labels.length - 1));
  const py = (v) => m.t + ih - ((v || 0) / max) * ih;
  series.forEach((s, si) => {
    const color = s.color || PALETTE[si % PALETTE.length];
    const pts = s.values.map((v, i) => `${px(i)},${py(v)}`);
    if (pts.length > 1) root.append(svg('polyline', { points: pts.join(' '), fill: 'none', stroke: color, 'stroke-width': 2.5, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }));
    s.values.forEach((v, i) => { const c = svg('circle', { cx: px(i), cy: py(v), r: 3.5, fill: '#fff', stroke: color, 'stroke-width': 2 }); c.append(svg('title', null, `${labels[i]} — ${s.name}: ${format(v)}`)); root.append(c); });
  });
  labels.forEach((lab, i) => { if (labels.length <= 14 || i % Math.ceil(labels.length / 10) === 0) root.append(svg('text', { x: px(i), y: H - 12, 'text-anchor': 'middle' }, lab)); });
  return h('div', null, root, legend(series));
}

export function donutChart({ items, size = 180, centerLabel }) {
  const total = items.reduce((s, i) => s + (i.value || 0), 0);
  const r = 70; const c = 2 * Math.PI * r; const root = svg('svg', { class: 'chart', viewBox: '0 0 180 180', width: size, height: size, role: 'img', 'aria-label': 'Distribution', style: { margin: '0 auto', display: 'block', width: size + 'px', maxWidth: '100%', height: 'auto' } });
  root.append(svg('circle', { cx: 90, cy: 90, r, fill: 'none', stroke: '#eef2f6', 'stroke-width': 22 }));
  let offset = 0;
  items.forEach((it, i) => {
    if (!it.value) return;
    const len = (it.value / total) * c;
    const seg = svg('circle', { cx: 90, cy: 90, r, fill: 'none', stroke: it.color || PALETTE[i % PALETTE.length], 'stroke-width': 22, 'stroke-dasharray': `${len} ${c - len}`, 'stroke-dashoffset': -offset, transform: 'rotate(-90 90 90)' });
    seg.append(svg('title', null, `${it.label}: ${fmtNumber(it.value)}`));
    root.append(seg); offset += len;
  });
  root.append(svg('text', { x: 90, y: 92, 'text-anchor': 'middle', style: 'font:700 24px Inter,sans-serif;fill:#0f172a' }, fmtNumber(total)), svg('text', { x: 90, y: 110, 'text-anchor': 'middle' }, centerLabel || 'total'));
  return h('div', null, root, h('div', { class: 'legend', style: { justifyContent: 'center' } }, items.map((it, i) => h('span', null, h('i', { style: { background: it.color || PALETTE[i % PALETTE.length] } }), `${it.label} (${fmtNumber(it.value)})`))));
}

export function hBars({ items, format = fmtNumber, max }) {
  const top = max ?? Math.max(...items.map((i) => i.value || 0), 1);
  return h('div', { class: 'stack sm' }, items.map((it) => h('div', null,
    h('div', { class: 'row small' }, h('span', null, it.label), h('span', { class: 'spacer' }), h('strong', null, format(it.value))),
    h('div', { class: 'progress', role: 'img', 'aria-label': `${it.label}: ${format(it.value)}` }, h('span', { style: { width: `${Math.min(100, ((it.value || 0) / top) * 100)}%`, background: it.color || '' } })))));
}

function legend(series) {
  if (series.length < 2) return null;
  return h('div', { class: 'legend' }, series.map((s, i) => h('span', null, h('i', { style: { background: s.color || PALETTE[i % PALETTE.length] } }), s.name)));
}
