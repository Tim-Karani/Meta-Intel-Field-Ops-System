import { h } from '../core/dom.js';

/* Declarative form builder with server-side error mapping.
   fields: [{ name, label, type, required, options, help, full, placeholder, rows, min, max, step, maxlength, value, hidden }] */

export function buildForm(fields, values = {}) {
  const controls = new Map();
  const errorEls = new Map();
  const grid = h('div', { class: 'form-grid' });

  for (const f of fields) {
    if (f.hidden) continue;
    const id = `f-${f.name}-${Math.random().toString(36).slice(2, 6)}`;
    const errId = `${id}-err`;
    const initial = values[f.name] ?? f.value ?? (f.type === 'checkbox' ? false : f.type === 'multiselect' ? [] : '');
    const control = makeControl(f, id, initial, errId);
    controls.set(f.name, { field: f, control });
    const err = h('div', { class: 'err', id: errId, role: 'alert' });
    errorEls.set(f.name, err);
    const wrap = f.type === 'checkbox'
      ? h('div', { class: ['field', f.full && 'full'] }, h('label', { class: 'check', for: id }, control.el, f.label), f.help && h('div', { class: 'help' }, f.help), err)
      : h('div', { class: ['field', (f.full || f.type === 'textarea' || f.type === 'multiselect') && 'full'] },
        h('label', { for: id, class: f.type === 'multiselect' ? 'lbl' : null }, f.label, f.required && h('span', { class: 'req', 'aria-hidden': 'true' }, ' *')),
        control.el, f.help && h('div', { class: 'help' }, f.help), err);
    grid.append(wrap);
  }

  const read = () => {
    const out = {};
    for (const [name, { field, control }] of controls) {
      let v = control.get();
      if (field.type === 'number' || field.type === 'decimal') v = v === '' ? null : Number(v);
      else if (typeof v === 'string' && field.type !== 'password') { v = v.trim(); if (v === '' && !field.keepEmpty) v = field.emptyAs === undefined ? null : field.emptyAs; }
      out[name] = v;
    }
    return out;
  };

  const showErrors = (err) => {
    clearErrors();
    let first = null;
    for (const [name, msgs] of Object.entries(err?.errors || {})) {
      const key = controls.has(name) ? name : name.split('.')[0];
      const el = errorEls.get(key);
      if (!el) continue;
      el.textContent = Array.isArray(msgs) ? msgs[0] : String(msgs);
      const ctl = controls.get(key)?.control.el;
      ctl?.setAttribute?.('aria-invalid', 'true'); ctl?.setAttribute?.('aria-describedby', el.id);
      first ??= ctl;
    }
    first?.focus?.();
    return !!first;
  };
  const clearErrors = () => {
    errorEls.forEach((el) => { el.textContent = ''; });
    controls.forEach(({ control }) => control.el.removeAttribute?.('aria-invalid'));
  };
  const set = (name, value) => controls.get(name)?.control.set(value);
  const setOptions = (name, options) => controls.get(name)?.control.setOptions?.(options);
  const el = (name) => controls.get(name)?.control.el;

  return { el: grid, read, showErrors, clearErrors, set, setOptions, control: el };
}

function makeControl(f, id, initial, errId) {
  const common = { id, name: f.name, required: false, 'aria-describedby': errId, disabled: f.disabled, placeholder: f.placeholder, maxlength: f.maxlength, autocomplete: f.autocomplete };
  switch (f.type) {
    case 'textarea': {
      const el = h('textarea', { ...common, class: 'textarea', rows: f.rows || 3, value: initial });
      return { el, get: () => el.value, set: (v) => { el.value = v ?? ''; } };
    }
    case 'select': {
      const el = h('select', { ...common, class: 'select' });
      const fill = (opts, keep) => {
        el.replaceChildren(...(f.blank === false ? [] : [h('option', { value: '' }, f.blank || '— Select —')]), ...normalise(opts).map((o) => h('option', { value: o.value }, o.label)));
        el.value = keep ?? '';
      };
      fill(f.options || [], initial === null ? '' : initial);
      return { el, get: () => el.value, set: (v) => { el.value = v ?? ''; }, setOptions: (opts) => fill(opts, el.value) };
    }
    case 'checkbox': {
      const el = h('input', { ...common, type: 'checkbox', checked: !!initial });
      return { el, get: () => el.checked, set: (v) => { el.checked = !!v; } };
    }
    case 'multiselect': {
      const wrap = h('div', { class: 'checklist', id, role: 'group', tabindex: '-1' });
      let selected = new Set(initial || []);
      const paint = (opts) => {
        wrap.replaceChildren(...normalise(opts).map((o) => {
          const cb = h('input', { type: 'checkbox', value: o.value, checked: selected.has(o.value), onchange: () => { cb.checked ? selected.add(o.value) : selected.delete(o.value); } });
          return h('label', null, cb, o.label);
        }));
        if (!wrap.children.length) wrap.append(h('div', { class: 'muted small', style: { padding: '8px' } }, f.emptyText || 'Nothing to choose from.'));
      };
      paint(f.options || []);
      return { el: wrap, get: () => [...selected], set: (v) => { selected = new Set(v || []); paint(f.options || []); }, setOptions: (opts) => { f.options = opts; paint(opts); } };
    }
    default: {
      const type = ['number', 'decimal'].includes(f.type) ? 'number' : (f.type || 'text');
      const el = h('input', { ...common, class: 'input', type, value: initial ?? '', min: f.min, max: f.max, step: f.step ?? (f.type === 'decimal' ? 'any' : null), inputmode: f.type === 'decimal' ? 'decimal' : null, spellcheck: type === 'text' ? null : 'false' });
      return { el, get: () => el.value, set: (v) => { el.value = v ?? ''; } };
    }
  }
}

export const normalise = (opts) => (opts || []).map((o) => (typeof o === 'object' && o !== null ? { value: String(o.value ?? o.id), label: o.label ?? o.name } : { value: String(o), label: String(o).replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase()) }));

/** Convenience: stand-alone labelled control for toolbars (filters). */
export function selectControl({ label, options, value = '', onChange, blank = 'All' }) {
  const el = h('select', { class: 'select', 'aria-label': label, onchange: () => onChange?.(el.value) },
    h('option', { value: '' }, blank), ...normalise(options).map((o) => h('option', { value: o.value }, o.label)));
  el.value = value;
  return h('div', { class: 'field' }, el);
}
