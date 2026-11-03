import { h } from '../core/dom.js';
import { api } from '../core/api.js';
import { can } from '../core/auth.js';
import { fmtDateTime } from '../core/format.js';
import { asyncView, badge, confirmDialog, empty, openModal, pageHead, toast, toastError, actionButton } from '../ui/feedback.js';
import { simpleTable } from '../ui/table.js';
import { selectControl } from '../ui/form.js';
import { osmLink } from '../ui/map.js';

/* Journey plan change requests: an agent asks to add, remove or move an outlet on their weekly route, with GPS and a photo as proof.
   The supervisor or team leader decides here; an approval updates the route straight away. */

const DAYS = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
const TYPE = { add: 'Add outlet', remove: 'Remove outlet', move: 'Move to another day' };
const TONE = { pending: 'warn', approved: 'ok', rejected: 'danger' };

function proof(r) {
  const img = h('img', { class: 'full', alt: 'Proof photo' });
  api.blobUrl(`jp-requests/${r.id}/proof`).then((u) => { img.src = u; }).catch(() => img.replaceWith(h('p', { class: 'err' }, 'Photo unavailable.')));
  openModal({ title: `Proof from ${r.agent?.name || 'agent'}`, size: 'lg', body: h('div', { class: 'photo-full' }, img), onClose: () => { if (img.src.startsWith('blob:')) URL.revokeObjectURL(img.src); } });
}

export default async function render({ container }) {
  let status = 'pending';
  const host = h('div');
  container.append(pageHead({ title: 'Journey plan requests', subtitle: 'Agents ask to change their route. Approve or reject; an approval updates the route and is recorded in the audit log.' }),
    h('div', { class: 'card' }, h('div', { class: 'toolbar' }, selectControl({ label: 'Status', options: [{ value: 'approved', label: 'Approved' }, { value: 'rejected', label: 'Rejected' }, { value: 'pending', label: 'Pending' }], value: 'pending', blank: 'All', onChange: (v) => { status = v; load(); } }))), host);

  let load = () => {};
  load = asyncView(host, () => api.get('jp-requests', { status }).then((r) => r.data), (rows, reload) => {
    if (!rows.length) return empty('No requests', status === 'pending' ? 'Nothing is waiting for a decision.' : 'No requests match.');
    const decide = (r, ok) => async () => {
      const res = await confirmDialog({ title: ok ? 'Approve this change?' : 'Reject this request?', message: `${r.agent?.name}: ${TYPE[r.type]} - ${r.location?.name}. ${ok ? 'The route updates straight away.' : 'The agent is told why.'}`,
        confirmLabel: ok ? 'Approve' : 'Reject', danger: !ok, reason: { label: ok ? 'Note (optional)' : 'Reason', required: !ok } });
      if (!res) return;
      try { await api.post(`jp-requests/${r.id}/${ok ? 'approve' : 'reject'}`, { note: res.reason || undefined }); toast(ok ? 'Approved. Route updated.' : 'Rejected.'); reload(); } catch (e) { toastError(e); }
    };
    return simpleTable([
      { label: 'When', render: (r) => h('span', { class: 'small' }, fmtDateTime(r.created_at)) },
      { label: 'Agent', render: (r) => h('strong', null, r.agent?.name || '—') },
      { label: 'Request', render: (r) => h('div', null, h('strong', null, TYPE[r.type]), h('div', { class: 'small' }, `${r.location?.name || '—'}${r.location?.town ? `, ${r.location.town}` : ''}`),
        h('div', { class: 'small muted' }, r.type === 'move' ? `${DAYS[r.weekday]} to ${DAYS[r.to_weekday]}` : r.type === 'add' ? `On ${DAYS[r.to_weekday]}` : `On ${DAYS[r.weekday]}`, r.campaign ? ` · ${r.campaign.name}` : '')) },
      { label: 'Reason', render: (r) => h('div', { class: 'small', style: { maxWidth: '280px' } }, r.reason) },
      { label: 'Proof', render: (r) => h('div', { class: 'small' }, r.has_proof && h('button', { class: 'btn sm', type: 'button', onclick: () => proof(r) }, 'Photo'), r.latitude != null && h('div', null, h('a', { href: osmLink(r.latitude, r.longitude), target: '_blank', rel: 'noopener noreferrer' }, 'GPS location'))) },
      { label: 'Status', render: (r) => h('div', null, badge(r.status, { tone: TONE[r.status] }), r.decided_by && h('div', { class: 'small muted' }, `by ${r.decided_by}`), r.decision_note && h('div', { class: 'small muted' }, r.decision_note)) },
      { label: '', render: (r) => (r.status === 'pending' && can('journey.review') ? h('div', { class: 'row' }, actionButton('Approve', decide(r, true), { cls: 'btn sm primary' }), h('button', { class: 'btn sm', type: 'button', onclick: decide(r, false) }, 'Reject')) : null) },
    ], rows);
  });
}
