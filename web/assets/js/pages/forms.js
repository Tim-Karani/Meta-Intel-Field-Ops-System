import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api } from '../core/api.js';
import { can } from '../core/auth.js';
import { fmtDate, fmtNumber } from '../core/format.js';
import { badge, pageHead, toast, toastError, confirmDialog } from '../ui/feedback.js';
import { dataTable } from '../ui/table.js';
import { formModal } from '../ui/crud.js';
import { href, navigate } from '../core/router.js';

export default async function render({ container }) {
  const manage = can('forms.manage');
  let table;
  const reload = () => table.reload();

  const create = () => formModal({
    title: 'New form', intro: 'Start with a name — you will add sections and questions in the builder.',
    fields: [{ name: 'name', label: 'Form name', required: true, full: true }, { name: 'description', label: 'Description', type: 'textarea', full: true },
      { name: 'requires_live_capture', label: 'Require live capture (no gallery uploads for photo questions)', type: 'checkbox', full: true }],
    onSubmit: async (v) => (await api.post('forms', { ...v, requires_live_capture: !!v.requires_live_capture })).data,
    onSaved: (f) => navigate(`/forms/${f.id}`), submitLabel: 'Create and open builder',
  });

  const duplicate = async (r) => {
    try { const { data } = await api.post(`forms/${r.id}/duplicate`, {}); toast('Form duplicated as a new draft.'); navigate(`/forms/${data.id}`); } catch (e) { toastError(e); }
  };
  const remove = async (r) => {
    if (!(await confirmDialog({ title: 'Delete draft form?', message: `“${r.name}” will be deleted.`, confirmLabel: 'Delete', danger: true }))) return;
    try { await api.delete(`forms/${r.id}`); toast('Form deleted.'); reload(); } catch (e) { toastError(e); }
  };

  container.append(pageHead({
    title: 'Forms', subtitle: 'Versioned data-capture forms. Published versions are immutable.',
    actions: manage && h('button', { class: 'btn primary', type: 'button', onclick: create }, icon('plus', { size: 16 }), 'New form'),
  }));
  table = dataTable({
    endpoint: 'forms', defaultSort: '-created_at', searchPlaceholder: 'Search forms…',
    filters: [{ key: 'status', label: 'All statuses', options: ['draft', 'published', 'archived'] }],
    columns: [
      { key: 'name', label: 'Form', sort: 'name', render: (r) => h('a', { href: href(`/forms/${r.id}`) }, h('strong', null, r.name)) },
      { key: 'version', label: 'Version', sort: 'version', render: (r) => `v${r.version}` },
      { key: 'status', label: 'Status', sort: 'status', render: (r) => badge(r.status) },
      { key: 'questions_count', label: 'Questions', align: 'right', render: (r) => fmtNumber(r.questions_count) },
      { key: 'created_at', label: 'Created', sort: 'created_at', render: (r) => fmtDate(r.created_at) },
      { key: '_a', label: '', align: 'right', render: (r) => h('div', { class: 'row', style: { justifyContent: 'flex-end' } },
        manage && h('button', { class: 'btn sm ghost', type: 'button', title: 'Duplicate', 'aria-label': `Duplicate ${r.name}`, onclick: () => duplicate(r) }, icon('copy', { size: 15 })),
        manage && r.status === 'draft' && h('button', { class: 'btn sm ghost', type: 'button', title: 'Delete', 'aria-label': `Delete ${r.name}`, onclick: () => remove(r) }, icon('trash', { size: 15 }))) },
    ],
    onRowClick: (r) => navigate(`/forms/${r.id}`),
    emptyTitle: 'No forms yet', emptyText: manage ? 'Create a form to collect visit data in the field.' : undefined,
  });
  container.append(table);
}
