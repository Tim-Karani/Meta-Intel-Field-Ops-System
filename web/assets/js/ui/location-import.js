import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api } from '../core/api.js';
import { lookup } from '../core/lookups.js';
import { openModal, toast, toastError } from './feedback.js';

/* Bulk outlet import: upload or paste a CSV (Excel: Save As > CSV), optionally link every outlet to a campaign.
   The file is checked first (nothing is saved), then imported. Good rows are saved even if others are rejected. */

const COLUMNS = ['name', 'code', 'type', 'address', 'town', 'county', 'latitude', 'longitude', 'geofence_radius_m', 'contact_name', 'contact_phone', 'channel', 'channel_group', 'subtype', 'distributor', 'status', 'country', 'region', 'locality', 'area', 'shop_no', 'market_name', 'phone', 'email', 'setting', 'territory', 'opening_hours', 'notes'];
const ALIAS = {
  outlet: 'name', 'outlet name': 'name', 'location': 'name', 'location name': 'name', 'store': 'name', 'store name': 'name', 'shop': 'name',
  lat: 'latitude', lng: 'longitude', lon: 'longitude', long: 'longitude', radius: 'geofence_radius_m', 'radius m': 'geofence_radius_m', geofence: 'geofence_radius_m',
  city: 'town', 'outlet type': 'type', 'sub type': 'subtype', 'outlet status': 'status', 'shop number': 'shop_no', 'shop no': 'shop_no', mall: 'market_name', market: 'market_name', 'market name': 'market_name', neighbourhood: 'area', chain: 'channel', 'store chain': 'channel', 'channel name': 'channel', group: 'channel_group', 'trade group': 'channel_group', province: 'region', area: 'locality', sublocality: 'locality', 'sub locality': 'locality', 'contact phone': 'contact_phone', contact: 'contact_name', 'contact name': 'contact_name',
};
const TEMPLATE = `${COLUMNS.join(',')}\nChain A Branch 1,CHA-001,supermarket,1 Example Road,Town One,County One,-1.2000,36.8000,100,Contact One,0700000000,Chain A,Modern trade,Sub-type One,Distributor One,Active,Country One,Region One,Locality One,Area One,12,Mall One,0711111111,shop@example.com,urban,Territory One,Mon-Sat 08:00-20:00,Back entrance\nChain B Branch 2,CHB-002,supermarket,2 Example Road,Town One,County One,,,,,,Chain B,,,,,,Region One,Locality One,,,,,,,,,\n`;

/** Small RFC-4180 style parser: quoted fields, doubled quotes, commas or semicolons, CRLF. */
export function parseCsv(text) {
  const src = text.replace(/^﻿/, '');
  const first = src.split(/\r?\n/, 1)[0] || '';
  const delim = (first.match(/;/g) || []).length > (first.match(/,/g) || []).length ? ';' : (first.includes('\t') ? '\t' : ',');
  const rows = []; let row = []; let cell = ''; let q = false;
  for (let i = 0; i < src.length; i++) {
    const c = src[i];
    if (q) { if (c === '"') { if (src[i + 1] === '"') { cell += '"'; i++; } else q = false; } else cell += c; }
    else if (c === '"') q = true;
    else if (c === delim) { row.push(cell); cell = ''; }
    else if (c === '\n' || c === '\r') { if (c === '\r' && src[i + 1] === '\n') i++; row.push(cell); rows.push(row); row = []; cell = ''; }
    else cell += c;
  }
  if (cell !== '' || row.length) { row.push(cell); rows.push(row); }
  return rows.filter((r) => r.some((x) => x.trim() !== ''));
}

export function toRecords(text) {
  const grid = parseCsv(text);
  if (grid.length < 2) return { records: [], unknown: [] };
  const norm = (s) => s.trim().toLowerCase().replace(/[_\-]+/g, ' ');
  const keys = grid[0].map((hd) => { const n = norm(hd); return COLUMNS.find((c) => norm(c) === n) || ALIAS[n] || null; });
  const unknown = grid[0].filter((_, i) => !keys[i]).map((s) => s.trim()).filter(Boolean);
  const records = grid.slice(1).map((r) => { const o = {}; keys.forEach((k, i) => { if (k && (r[i] ?? '').trim() !== '') o[k] = r[i].trim(); }); return o; });
  return { records, unknown };
}

export async function openLocationImport({ onDone } = {}) {
  const campaigns = await lookup('campaigns').catch(() => []);
  const clients = await lookup('clients').catch(() => []);
  let records = [];
  let checked = false;

  const file = h('input', { type: 'file', accept: '.csv,text/csv,text/plain', class: 'input', 'aria-label': 'CSV file' });
  const paste = h('textarea', { class: 'textarea', rows: 5, placeholder: `Or paste rows here, with the header line:\n${COLUMNS.join(',')}` });
  const campaign = h('select', { class: 'select' }, h('option', { value: '' }, 'Do not link to a campaign'), ...campaigns.map((c) => h('option', { value: c.value }, c.label)));
  const client = h('select', { class: 'select' }, h('option', { value: '' }, 'No client (shared outlets)'), ...clients.map((c) => h('option', { value: c.value }, c.label)));
  const out = h('div', { class: 'stack sm' });
  const checkBtn = h('button', { class: 'btn', type: 'button' }, 'Check the file');
  const importBtn = h('button', { class: 'btn primary', type: 'button', disabled: true }, 'Import');

  const read = async () => {
    const text = file.files?.[0] ? await file.files[0].text() : paste.value;
    const { records: recs, unknown } = toRecords(text || '');
    records = recs; checked = false; importBtn.disabled = true;
    return unknown;
  };
  const show = (res, unknown) => {
    out.replaceChildren(
      h('div', { class: res.dry_run ? 'alert info' : 'alert ok' }, icon(res.dry_run ? 'info' : 'checkCircle'), h('div', null,
        h('strong', null, res.dry_run ? 'Checked - nothing saved yet. ' : 'Done. '),
        `${res.created} new outlet${res.created === 1 ? '' : 's'}${res.dry_run ? ' would be added' : ' added'}, ${res.linked_existing} already in the system (reused), ${res.rejected} rejected.`)),
      unknown.length > 0 && h('div', { class: 'alert warn' }, icon('alert'), h('div', null, `These columns were not recognised and were ignored: ${unknown.join(', ')}.`)),
      res.errors.length > 0 && h('div', { class: 'table-wrap' }, h('table', { class: 'data' }, h('thead', null, h('tr', null, h('th', null, 'Row'), h('th', null, 'Outlet'), h('th', null, 'Problem'))),
        h('tbody', null, res.errors.slice(0, 100).map((e) => h('tr', null, h('td', null, String(e.row + 1)), h('td', null, e.name || '—'), h('td', null, e.messages.join(' ')))))))); // +1: row 1 is the header line
  };
  const send = async (dry) => {
    const unknown = await read();
    if (!records.length) { out.replaceChildren(h('div', { class: 'alert warn' }, icon('alert'), h('div', null, 'No rows found. The first line must be the column names, followed by one outlet per line.'))); return; }
    if (records.length > 500) { out.replaceChildren(h('div', { class: 'alert warn' }, icon('alert'), h('div', null, `This file has ${records.length} rows; the limit is 500 per import. Split it into smaller files.`))); return; }
    checkBtn.disabled = importBtn.disabled = true;
    try {
      const res = (await api.post('locations/import', { rows: records, dry_run: dry, campaign_id: campaign.value || undefined, client_id: client.value || undefined })).data;
      show(res, unknown);
      if (dry) { checked = true; importBtn.disabled = res.created + res.linked_existing === 0; } else { toast(`Imported ${res.created} outlet(s).`); onDone?.(); }
    } catch (e) { toastError(e); } finally { checkBtn.disabled = false; if (!checked) importBtn.disabled = true; }
  };
  checkBtn.addEventListener('click', () => send(true));
  importBtn.addEventListener('click', () => send(false));
  [file, paste].forEach((el) => el.addEventListener('input', () => { checked = false; importBtn.disabled = true; }));

  const sample = h('button', { class: 'btn sm ghost', type: 'button', onclick: () => {
    const url = URL.createObjectURL(new Blob([TEMPLATE], { type: 'text/csv' }));
    const a = h('a', { href: url, download: 'outlets-template.csv' }); document.body.append(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(url), 2000);
  } }, icon('download', { size: 14 }), 'Download the template');

  openModal({
    title: 'Import outlets', size: 'lg',
    body: h('div', { class: 'stack' },
      h('p', { class: 'muted' }, 'Add many outlets at once. In Excel, fill the template and use Save As > CSV. Only the name is required, but include latitude and longitude: positions you enter are trusted and agents are checked in against them. Without them, the position is only saved from the agent first check-in, and only if the project allows that. Outlets already in the system (same code, or same name and town) are reused, not duplicated.'),
      sample,
      h('div', { class: 'field' }, h('label', null, 'CSV file'), file), paste,
      h('div', { class: 'grid cols-2' },
        h('div', { class: 'field' }, h('label', null, 'Link all of them to this campaign'), campaign, h('div', { class: 'help' }, 'Optional. This is the quick way to put a whole outlet list into a project.')),
        h('div', { class: 'field' }, h('label', null, 'Client'), client)),
      out),
    footer: (close) => [h('button', { class: 'btn', type: 'button', onclick: () => close() }, 'Close'), checkBtn, importBtn],
  });
}
