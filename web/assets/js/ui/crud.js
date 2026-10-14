import { h, clear } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api } from '../core/api.js';
import { can } from '../core/auth.js';
import { buildForm } from './form.js';
import { dataTable } from './table.js';
import { openModal, confirmDialog, toast, toastError, pageHead } from './feedback.js';
import { invalidateLookups } from '../core/lookups.js';

/** Modal with a form. `onSubmit(values)` should call the API; validation errors map back onto fields. */
export function formModal({ title, fields, values = {}, onSubmit, submitLabel = 'Save', size = '', intro, onSaved, drawer = false, extra }) {
  const form = buildForm(fields, values);
  const banner = h('div', { class: 'hidden', role: 'alert' });
  const body = h('form', { novalidate: true }, intro && h('p', { class: 'muted', style: { marginBottom: '14px' } }, intro), banner, form.el, extra);
  let modal;
  const saveBtn = h('button', { class: 'btn primary', type: 'submit', form: 'modal-form' }, submitLabel);
  body.id = 'modal-form';
  body.addEventListener('submit', async (e) => {
    e.preventDefault();
    form.clearErrors(); banner.className = 'hidden';
    saveBtn.disabled = true; const label = saveBtn.textContent; saveBtn.replaceChildren(h('span', { class: 'spinner' }), ' Saving…');
    try {
      const result = await onSubmit(form.read(), form);
      invalidateLookups();
      modal.close(result);
      onSaved?.(result);
    } catch (err) {
      const mapped = form.showErrors(err);
      if (!mapped || err.status !== 422) { banner.className = 'alert error'; banner.replaceChildren(icon('alert'), h('div', null, err.message || 'Could not save.')); }
    } finally {
      saveBtn.disabled = false; saveBtn.textContent = label;
    }
  });
  modal = openModal({ title, body, size, drawer, footer: (close) => [h('button', { class: 'btn', type: 'button', onclick: () => close() }, 'Cancel'), saveBtn] });
  return { modal, form };
}

/**
 * Standard resource page: header, searchable/sortable/filterable table, create/edit modal, delete.
 * fields: array or (record|null) => array.   toPayload(values, record) shapes the request body.
 */
export function crudPage({
  title, subtitle, noun, endpoint, columns, filters, fields, perms = {}, defaultSort, searchPlaceholder, toPayload = (v) => v, toValues = (r) => r,
  onRowClick, rowActions, headerActions, embedded = false, search = true, emptyText, formSize, loadRecord, canEditRow, canDeleteRow, deleteMessage, query, intro, afterSave,
}) {
  const wrap = h('div');
  const tableHost = h('div');
  const getFields = (rec) => (typeof fields === 'function' ? fields(rec) : fields);
  let table;
  const reload = () => table?.reload();

  async function openForm(row) {
    let rec = row;
    if (row && loadRecord) { try { rec = await loadRecord(row); } catch (e) { toastError(e); return; } }
    formModal({
      title: row ? `Edit ${noun}` : `New ${noun}`, fields: getFields(rec), values: rec ? toValues(rec) : {}, size: formSize, intro: row ? null : intro,
      submitLabel: row ? 'Save changes' : `Create ${noun}`,
      onSubmit: async (v) => {
        const body = toPayload(v, rec);
        const res = rec ? await api.patch(`${endpoint}/${rec.id}`, body) : await api.post(endpoint, body);
        toast(rec ? `${cap(noun)} updated.` : `${cap(noun)} created.`);
        return res.data;
      },
      onSaved: (saved) => { afterSave?.(saved); reload(); },
    });
  }

  async function remove(row) {
    const ok = await confirmDialog({ title: `Delete ${noun}?`, message: deleteMessage?.(row) || `“${row.name || row.title || 'This record'}” will be removed. This cannot be undone from the interface.`, confirmLabel: 'Delete', danger: true });
    if (!ok) return;
    try { await api.delete(`${endpoint}/${row.id}`); toast(`${cap(noun)} deleted.`); invalidateLookups(); reload(); } catch (e) { toastError(e); }
  }

  const actionCol = {
    key: '_actions', label: '', align: 'right', class: 'nowrap',
    render: (r) => h('div', { class: 'row', style: { justifyContent: 'flex-end' } },
      rowActions?.(r, reload),
      perms.edit && can(perms.edit) && (canEditRow?.(r) ?? true) && h('button', { class: 'btn sm ghost', type: 'button', 'aria-label': `Edit ${r.name || noun}`, title: 'Edit', onclick: () => openForm(r) }, icon('edit', { size: 15 })),
      perms.delete && can(perms.delete) && (canDeleteRow?.(r) ?? true) && h('button', { class: 'btn sm ghost', type: 'button', 'aria-label': `Delete ${r.name || noun}`, title: 'Delete', onclick: () => remove(r) }, icon('trash', { size: 15 }))),
  };
  const cols = [...columns];
  if ((perms.edit && can(perms.edit)) || (perms.delete && can(perms.delete)) || rowActions) cols.push(actionCol);

  const createBtn = perms.create && can(perms.create) ? h('button', { class: 'btn primary', type: 'button', onclick: () => openForm(null) }, icon('plus', { size: 16 }), `New ${noun}`) : null;
  wrap.append(embedded ? h('div', { class: 'row', style: { justifyContent: 'flex-end', marginBottom: '10px' } }, headerActions, createBtn) : pageHead({ title, subtitle, actions: [headerActions, createBtn] }), tableHost);
  table = dataTable({ endpoint, columns: cols, filters, defaultSort, searchPlaceholder, onRowClick, emptyTitle: `No ${plural(noun)} yet`, emptyText: emptyText ?? (createBtn ? `Create the first ${noun} to get started.` : undefined), emptyAction: createBtn && h('button', { class: 'btn primary', type: 'button', onclick: () => openForm(null) }, `New ${noun}`), query, search });
  tableHost.append(table);
  return Object.assign(wrap, { reload, openForm });
}

const cap = (s) => s.charAt(0).toUpperCase() + s.slice(1);
const plural = (s) => (s.endsWith('y') ? `${s.slice(0, -1)}ies` : `${s}s`);
