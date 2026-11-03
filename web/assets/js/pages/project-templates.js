import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api } from '../core/api.js';
import { can } from '../core/auth.js';
import { badge, pageHead, toast, toastError, confirmDialog } from '../ui/feedback.js';
import { dataTable } from '../ui/table.js';
import { formModal } from '../ui/crud.js';
import { setupFields, fromSettings, toSettings, settingsSummary } from '../ui/project-setup.js';

/** The reusable project setups: the five built-in ones (read-only) and any the agency adds. */
export default async function render({ container }) {
  const fields = await setupFields([], { withTemplate: false });
  const base = [{ name: 'name', label: 'Template name', required: true, maxlength: 120, full: true, tab: 'Details' }, { name: 'description', label: 'What is it for?', type: 'textarea', full: true, rows: 2, tab: 'Details' }];
  let table; const reload = () => table.reload();
  const canEdit = can('campaigns.edit'); const canDelete = can('campaigns.archive');

  const open = (rec, copyOf) => {
    const src = rec || copyOf;
    formModal({
      title: rec ? 'Edit template' : copyOf ? `Copy of ${copyOf.name}` : 'New template', size: 'lg', submitLabel: rec ? 'Save changes' : 'Create template',
      intro: !rec && copyOf ? 'A copy you can change. The built-in template stays as it is.' : undefined,
      fields: [...base, ...fields], values: { name: copyOf ? `${copyOf.name} (copy)` : rec?.name, description: src?.description, ...fromSettings(src?.settings) },
      onSubmit: async (v) => {
        const body = { name: v.name, description: v.description, settings: toSettings(v) };
        if (rec) await api.patch(`project-templates/${rec.id}`, body); else await api.post('project-templates', body);
        toast('Template saved.');
      }, onSaved: reload,
    });
  };
  const remove = async (r) => {
    if (!(await confirmDialog({ title: 'Delete template?', message: `“${r.name}” will be removed. Campaigns already created from it keep their settings.`, confirmLabel: 'Delete', danger: true }))) return;
    try { await api.delete(`project-templates/${r.id}`); toast('Template deleted.'); reload(); } catch (e) { toastError(e); }
  };

  container.append(pageHead({ title: 'Project templates', subtitle: 'Pick one when you set up a campaign: it decides how outlets are added and what agents see on the phone.',
    actions: canEdit && h('button', { class: 'btn primary', type: 'button', onclick: () => open(null) }, icon('plus', { size: 16 }), 'New template') }));
  table = dataTable({
    endpoint: 'project-templates', defaultSort: 'created_at', search: false, perPage: 50,
    columns: [
      { key: 'name', label: 'Template', render: (r) => h('div', null, h('strong', null, r.name), ' ', r.is_system && badge('built-in', { tone: 'neutral' }), h('div', { class: 'small muted' }, r.description || '')) },
      { key: 'settings', label: 'Setup', render: (r) => settingsSummary(r.settings) },
      { key: '_a', label: '', align: 'right', render: (r) => h('div', { class: 'row', style: { justifyContent: 'flex-end' } },
        canEdit && h('button', { class: 'btn sm', type: 'button', onclick: () => open(null, r) }, icon('copy', { size: 14 }), 'Use as a copy'),
        canEdit && !r.is_system && h('button', { class: 'btn sm ghost', type: 'button', title: 'Edit', 'aria-label': `Edit ${r.name}`, onclick: () => open(r) }, icon('edit', { size: 15 })),
        canDelete && !r.is_system && h('button', { class: 'btn sm ghost', type: 'button', title: 'Delete', 'aria-label': `Delete ${r.name}`, onclick: () => remove(r) }, icon('trash', { size: 15 }))) },
    ],
  });
  container.append(table);
}
