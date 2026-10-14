import { h, clear } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api } from '../core/api.js';
import { can } from '../core/auth.js';
import { fmtDate, fmtBytes, fmtDateTime } from '../core/format.js';
import { asyncView, badge, kv, card, pageHead, tabs, toast, toastError, confirmDialog, empty } from '../ui/feedback.js';
import { dataTable, simpleTable } from '../ui/table.js';
import { formModal } from '../ui/crud.js';
import { clientFields } from './clients.js';
import { href, navigate } from '../core/router.js';

export default async function render({ container, params }) {
  const holder = h('div'); container.append(holder);
  asyncView(holder, () => api.get(`clients/${params.id}`).then((r) => r.data), (client, reload) => {
    const edit = can('clients.edit') && h('button', { class: 'btn', type: 'button', onclick: () => formModal({
      title: 'Edit client', fields: clientFields(), values: client, submitLabel: 'Save changes',
      onSubmit: async (v) => { await api.patch(`clients/${client.id}`, v); toast('Client updated.'); }, onSaved: reload,
    }) }, icon('edit', { size: 16 }), 'Edit');

    return h('div', null,
      pageHead({ title: client.name, subtitle: [client.company_name, client.industry].filter(Boolean).join(' · ') || undefined, actions: [badge(client.status), edit] }),
      tabs([
        { key: 'overview', label: 'Overview', render: () => overview(client) },
        { key: 'campaigns', label: `Campaigns (${client.campaigns_count ?? 0})`, render: () => campaignsTab(client) },
        { key: 'contacts', label: 'Contacts', render: () => contactsTab(client) },
        { key: 'documents', label: 'Documents', render: () => documentsTab(client) },
        { key: 'notes', label: 'Notes', render: () => card('Internal notes', client.notes ? h('p', { style: { whiteSpace: 'pre-wrap' } }, client.notes) : h('span', { class: 'muted' }, 'No notes.')) },
      ]));
  });
}

const overview = (c) => h('div', { class: 'grid cols-2' },
  card('Details', kv([['Name', c.name], ['Code', c.code && h('span', { class: 'mono' }, c.code)], ['Company', c.company_name], ['Industry', c.industry], ['Email', c.email], ['Phone', c.phone], ['Address', c.address], ['Created', fmtDate(c.created_at)]])),
  card('Summary', kv([['Status', badge(c.status)], ['Campaigns', String(c.campaigns_count ?? 0)]])));

const campaignsTab = (client) => dataTable({
  endpoint: 'campaigns', query: { client_id: client.id }, defaultSort: '-start_date', searchPlaceholder: 'Search campaigns…',
  columns: [
    { key: 'name', label: 'Campaign', render: (r) => h('a', { href: href(`/campaigns/${r.id}`) }, h('strong', null, r.name)) },
    { key: 'status', label: 'Status', render: (r) => badge(r.status) },
    { key: 'dates', label: 'Dates', render: (r) => `${fmtDate(r.start_date)} → ${fmtDate(r.end_date)}` },
  ],
  onRowClick: (r) => navigate(`/campaigns/${r.id}`), emptyTitle: 'No campaigns for this client',
});

function contactsTab(client) {
  const host = h('div');
  const fields = [
    { name: 'name', label: 'Name', required: true }, { name: 'title', label: 'Job title' }, { name: 'email', label: 'Email', type: 'email' },
    { name: 'phone', label: 'Phone' }, { name: 'is_primary', label: 'Primary contact', type: 'checkbox' },
  ];
  const canEdit = can('clients.edit');
  const run = asyncView(host, () => api.get(`clients/${client.id}/contacts`).then((r) => r.data), (rows, reload) => card('Contacts', simpleTable([
    { label: 'Name', render: (r) => h('div', null, h('strong', null, r.name), r.is_primary && h('span', { style: { marginLeft: '6px' } }, badge('primary', { tone: 'info' }))) },
    { label: 'Title', key: 'title' }, { label: 'Email', render: (r) => r.email ? h('a', { href: `mailto:${r.email}` }, r.email) : '—' }, { label: 'Phone', key: 'phone' },
    canEdit && { label: '', align: 'right', render: (r) => h('div', { class: 'row', style: { justifyContent: 'flex-end' } },
      h('button', { class: 'btn sm ghost', type: 'button', 'aria-label': 'Edit contact', onclick: () => formModal({ title: 'Edit contact', fields, values: r, onSubmit: async (v) => { await api.patch(`clients/${client.id}/contacts/${r.id}`, v); toast('Contact updated.'); }, onSaved: reload }) }, icon('edit', { size: 15 })),
      h('button', { class: 'btn sm ghost', type: 'button', 'aria-label': 'Delete contact', onclick: async () => { if (await confirmDialog({ title: 'Delete contact?', message: `Remove ${r.name}?`, confirmLabel: 'Delete', danger: true })) { try { await api.delete(`clients/${client.id}/contacts/${r.id}`); reload(); } catch (e) { toastError(e); } } } }, icon('trash', { size: 15 }))) },
  ].filter(Boolean), rows, { emptyText: 'No contacts recorded.' }), {
    flush: true, actions: canEdit && h('button', { class: 'btn sm primary', type: 'button', onclick: () => formModal({ title: 'New contact', fields, onSubmit: async (v) => { await api.post(`clients/${client.id}/contacts`, { ...v, is_primary: !!v.is_primary }); toast('Contact added.'); }, onSaved: reload }) }, icon('plus', { size: 14 }), 'Add contact'),
  }));
  return host;
}

function documentsTab(client) {
  const host = h('div'); const canEdit = can('clients.edit');
  asyncView(host, () => api.get(`clients/${client.id}/documents`).then((r) => r.data), (rows, reload) => card('Documents', simpleTable([
    { label: 'Title', render: (r) => h('strong', null, r.title) }, { label: 'Type', key: 'mime', class: 'mono' }, { label: 'Size', render: (r) => fmtBytes(r.size_bytes) }, { label: 'Uploaded', render: (r) => fmtDateTime(r.created_at) },
    { label: '', align: 'right', render: (r) => h('div', { class: 'row', style: { justifyContent: 'flex-end' } },
      h('button', { class: 'btn sm', type: 'button', onclick: () => api.download(`clients/${client.id}/documents/${r.id}/download`, null, r.title).catch(toastError) }, icon('download', { size: 14 }), 'Download'),
      canEdit && h('button', { class: 'btn sm ghost', type: 'button', 'aria-label': 'Delete document', onclick: async () => { if (await confirmDialog({ title: 'Delete document?', message: r.title, confirmLabel: 'Delete', danger: true })) { try { await api.delete(`clients/${client.id}/documents/${r.id}`); reload(); } catch (e) { toastError(e); } } } }, icon('trash', { size: 15 }))) },
  ], rows, { emptyText: 'No documents uploaded.' }), {
    flush: true, actions: canEdit && h('button', { class: 'btn sm primary', type: 'button', onclick: () => uploadDoc(client, reload) }, icon('upload', { size: 14 }), 'Upload'),
  }));
  return host;
}

function uploadDoc(client, done) {
  const file = h('input', { type: 'file', class: 'input', accept: 'application/pdf,image/jpeg,image/png' });
  const { form } = formModal({
    title: 'Upload document', fields: [{ name: 'title', label: 'Title', required: true, full: true }],
    extra: h('div', { class: 'field' }, h('label', null, 'File (PDF, JPEG or PNG)'), file),
    onSubmit: async (v) => {
      if (!file.files[0]) throw Object.assign(new Error('Choose a file to upload.'), { status: 0 });
      const fd = new FormData(); fd.append('title', v.title); fd.append('file', file.files[0]);
      await api.post(`clients/${client.id}/documents`, fd); toast('Document uploaded.');
    }, onSaved: done,
  });
}
