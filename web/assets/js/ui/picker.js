import { h, clear, debounce } from '../core/dom.js';
import { api } from '../core/api.js';
import { openModal, toast, toastError, loading } from './feedback.js';
import { icon } from '../core/icons.js';

/**
 * Multi-select with server-side search (lookups are capped at 200 rows, so large sets stay searchable).
 * selected: [{ value, label }]  → onSave(selectedArray) must perform the API call.
 */
export function pickerModal({ title, entity, params = {}, selected = [], onSave, intro, extraControls, submitLabel = 'Save selection' }) {
  const chosen = new Map(selected.map((s) => [s.value, s.label]));
  const list = h('div', { class: 'checklist', style: { maxHeight: '320px' }, role: 'group', 'aria-label': title });
  const count = h('span', { class: 'muted small' });
  const banner = h('div', { class: 'hidden' });
  const paintCount = () => { count.textContent = `${chosen.size} selected`; };

  async function fetchOptions(search = '') {
    clear(list).append(loading('Loading…'));
    try {
      const { data } = await api.get(`lookups/${entity}`, { ...params, search: search || undefined });
      const opts = data.map((r) => ({ value: r.id, label: r.name }));
      // keep already-selected items visible even if they are outside the current search result
      const shown = new Map(opts.map((o) => [o.value, o.label]));
      if (!search) chosen.forEach((label, value) => { if (!shown.has(value)) shown.set(value, label); });
      clear(list);
      if (!shown.size) list.append(h('div', { class: 'muted small', style: { padding: '10px' } }, search ? 'No matches.' : 'Nothing available to choose from.'));
      shown.forEach((label, value) => {
        const cb = h('input', { type: 'checkbox', checked: chosen.has(value), onchange: () => { cb.checked ? chosen.set(value, label) : chosen.delete(value); paintCount(); } });
        list.append(h('label', null, cb, label));
      });
    } catch (e) { clear(list).append(h('div', { class: 'alert error' }, e.message)); }
  }

  const search = h('input', { class: 'input', type: 'search', placeholder: 'Search…', 'aria-label': 'Search options', oninput: debounce(() => fetchOptions(search.value.trim()), 300) });
  const body = h('div', { class: 'stack sm' }, intro && h('p', { class: 'muted' }, intro), banner, extraControls, h('div', { class: 'row' }, search, count), list);
  paintCount(); fetchOptions();

  const save = h('button', { class: 'btn primary', type: 'button' }, submitLabel);
  const m = openModal({ title, body, footer: (close) => [h('button', { class: 'btn', type: 'button', onclick: () => close() }, 'Cancel'), save] });
  save.addEventListener('click', async () => {
    save.disabled = true; const t = save.textContent; save.replaceChildren(h('span', { class: 'spinner' }), ' Saving…');
    try { await onSave([...chosen].map(([value, label]) => ({ value, label }))); m.close(true); }
    catch (e) { banner.className = 'alert error'; banner.replaceChildren(icon('alert'), h('div', null, e.message)); }
    finally { save.disabled = false; save.textContent = t; }
  });
  return m;
}
