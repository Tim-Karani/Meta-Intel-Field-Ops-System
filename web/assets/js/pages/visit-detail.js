import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api } from '../core/api.js';
import { can, session } from '../core/auth.js';
import { fmtDate, fmtDateTime, fmtDistance, fmtMoney, fmtNumber, titleCase } from '../core/format.js';
import { asyncView, badge, kv, card, pageHead, tabs, toast, toastError, confirmDialog, empty } from '../ui/feedback.js';
import { simpleTable } from '../ui/table.js';
import { mapView, osmLink } from '../ui/map.js';
import { photoGrid } from '../ui/gallery.js';
import { href } from '../core/router.js';

export default async function render({ container, params }) {
  const holder = h('div'); container.append(holder);
  asyncView(holder, () => api.get(`visits/${params.id}`).then((r) => r.data), (v, reload) => {
    const me = session.user?.id;
    const canDecide = (v.status === 'submitted' || v.status === 'under_review') && v.agent?.id !== me;
    const act = (label, cls, ic, fn) => h('button', { class: ['btn', cls], type: 'button', onclick: fn }, icon(ic, { size: 16 }), label);
    const actions = [badge(v.status), badge(v.gps_outcome, { label: `GPS ${v.gps_outcome ?? 'n/a'}` })];
    if (canDecide && can('visits.review') && v.status === 'submitted') actions.push(act('Start review', '', 'eye', async () => { try { await api.post(`visits/${v.id}/review`); toast('Review started.'); reload(); } catch (e) { toastError(e); } }));
    if (canDecide && can('visits.reject')) actions.push(act('Reject', 'danger', 'x', async () => {
      const r = await confirmDialog({ title: 'Reject this visit?', message: 'The agent is notified and can correct and resubmit.', confirmLabel: 'Reject visit', danger: true, reason: { label: 'What needs fixing?', required: true } });
      if (!r) return; try { await api.post(`visits/${v.id}/reject`, { note: r.reason }); toast('Visit rejected.'); reload(); } catch (e) { toastError(e); }
    }));
    if (canDecide && can('visits.approve')) actions.push(act('Approve', 'primary', 'check', async () => {
      const r = await confirmDialog({ title: 'Approve this visit?', message: 'Approved visits become visible to the client.', confirmLabel: 'Approve', reason: { label: 'Note (optional)', required: false } });
      if (!r) return; try { await api.post(`visits/${v.id}/approve`, { note: r.reason || null }); toast('Visit approved.'); reload(); } catch (e) { toastError(e); }
    }));

    const flagged = v.gps_outcome === 'suspicious' || v.gps_outcome === 'warning';
    return h('div', null,
      pageHead({ title: v.location?.name || 'Visit', subtitle: `${fmtDate(v.visit_date)} · ${v.agent?.name || ''} · ${v.campaign?.name || ''}`, actions }),
      v.status === 'rejected' && v.review_note && h('div', { class: 'alert error', style: { marginBottom: '16px' } }, icon('alert'), h('div', null, h('strong', null, 'Rejected: '), v.review_note)),
      flagged && h('div', { class: 'alert warn', style: { marginBottom: '16px' } }, icon('alert'), h('div', null, h('strong', null, `GPS result: ${v.gps_outcome}. `), (v.checkin?.reasons || []).map((r) => r.detail).join(' ') || 'See the GPS tab.')),
      tabs([
        { key: 'overview', label: 'Overview', render: () => overview(v) },
        { key: 'gps', label: 'GPS', render: () => gpsTab(v) },
        { key: 'responses', label: 'Form responses', render: () => responsesTab(v) },
        { key: 'sales', label: `Sales (${v.sales?.length ?? 0})`, render: () => salesTab(v) },
        { key: 'osa', label: `OSA (${v.osa_records?.length ?? 0})`, render: () => osaTab(v) },
        { key: 'merch', label: `Merchandising (${v.merchandising_records?.length ?? 0})`, render: () => merchTab(v) },
        { key: 'photos', label: `Photos (${v.photos?.length ?? 0})`, render: () => photoGrid((v.photos || []).map((p) => ({ ...p, visit: { location: v.location, campaign: v.campaign } })), { onChange: reload }) },
      ]));
  });
}

function overview(v) {
  return h('div', { class: 'grid split' },
    h('div', { class: 'stack' },
      card('Visit', kv([['Location', v.location?.id ? h('a', { href: href(`/locations/${v.location.id}`) }, v.location.name) : null], ['Agent', v.agent?.name], ['Campaign', v.campaign?.id ? h('a', { href: href(`/campaigns/${v.campaign.id}`) }, v.campaign.name) : null], ['Activity', v.activity?.name],
        ['Started', fmtDateTime(v.started_at)], ['Completed', fmtDateTime(v.completed_at)], ['Submitted', fmtDateTime(v.submitted_at)], ['Reviewed', v.reviewed_at ? `${fmtDateTime(v.reviewed_at)} by ${v.reviewer?.name || '—'}` : null], ['Review note', v.review_note], ['Agent notes', v.notes]])),
      v.issues?.length > 0 && card('Issues raised', simpleTable([{ label: 'Issue', key: 'title' }, { label: 'Priority', render: (i) => badge(i.priority) }, { label: 'Status', render: (i) => badge(i.status) }], v.issues), { flush: true })),
    card('Timeline', (v.events || []).length ? h('ol', { class: 'timeline' }, v.events.map((e) => h('li', null,
      h('div', null, h('strong', null, titleCase(e.type)), e.to_status && h('span', { class: 'muted' }, ` → ${titleCase(e.to_status)}`)),
      h('div', { class: 'small muted' }, fmtDateTime(e.created_at)), e.note && h('div', { class: 'small' }, e.note)))) : h('span', { class: 'muted' }, 'No events recorded.')));
}

function gpsTab(v) {
  const loc = v.location; const ci = v.checkin; const co = v.checkout;
  if (!ci) return empty('No check-in yet', 'The agent has not checked in at this location.');
  const markers = [];
  if (loc?.latitude != null) markers.push({ lat: Number(loc.latitude), lng: Number(loc.longitude), label: `${loc.name} (geofence)`, radiusM: loc.geofence_radius_m ?? 100 });
  markers.push({ lat: Number(ci.latitude), lng: Number(ci.longitude), label: 'Check-in', tone: ci.outcome === 'valid' ? 'ok' : ci.outcome === 'warning' ? 'warn' : 'danger' });
  if (co) markers.push({ lat: Number(co.latitude), lng: Number(co.longitude), label: 'Check-out', tone: 'info' });
  const reasons = (c) => (c?.reasons?.length ? h('ul', { class: 'small', style: { margin: '6px 0 0', paddingLeft: '18px' } }, c.reasons.map((r) => h('li', null, r.detail, r.hard && h('span', { class: 'badge danger', style: { marginLeft: '6px' } }, 'hard')))) : h('span', { class: 'muted' }, 'No anomalies.'));
  const detail = (c) => kv([['Result', badge(c.outcome)], ['Distance from location', fmtDistance(Number(c.distance_m))], ['Accuracy', c.accuracy_m != null ? `±${Math.round(Number(c.accuracy_m))} m` : null], ['Device time', fmtDateTime(c.device_ts)], ['Server time', fmtDateTime(c.server_ts)],
    ['Mock-location flag', c.mock_flag == null ? null : c.mock_flag ? badge('detected', { tone: 'danger' }) : 'No'], ['Coordinates', h('a', { href: osmLink(c.latitude, c.longitude), target: '_blank', rel: 'noopener noreferrer' }, `${Number(c.latitude).toFixed(5)}, ${Number(c.longitude).toFixed(5)}`)], ['Findings', reasons(c)]]);
  return h('div', { class: 'grid split' }, card('Map', mapView({ markers, height: 420 })),
    h('div', { class: 'stack' }, card('Check-in', detail(ci)), co && card('Check-out', detail(co))));
}

function responsesTab(v) {
  const host = h('div');
  if (!v.form_id) return empty('No form', 'This visit has no form attached.');
  asyncView(host, () => api.get(`forms/${v.form_id}`).then((r) => r.data), (form) => {
    const answers = v.responses || {};
    return h('div', { class: 'stack' }, (form.sections || []).map((s) => card(s.title, h('div', { class: 'stack sm' }, s.questions.filter((q) => !['photo', 'signature'].includes(q.type)).map((q) => {
      const raw = answers[q.id];
      return h('div', null, h('div', { class: 'small muted' }, q.label), h('div', null, display(q, raw)));
    })))), h('p', { class: 'small muted' }, `Form: ${form.name} v${form.version}`));
  });
  return host;
}
function display(q, raw) {
  if (raw === undefined || raw === null || raw === '') return h('span', { class: 'muted' }, 'No answer');
  const val = raw?.value ?? raw;
  const label = (x) => q.options?.find((o) => String(o.value) === String(x))?.label ?? x;
  if (Array.isArray(val)) return val.map(label).join(', ');
  if (typeof val === 'boolean') return val ? 'Yes' : 'No';
  if (q.type === 'currency') return fmtMoney(val);
  if (q.type === 'percentage') return `${val}%`;
  if (q.type === 'gps' && typeof val === 'object') return `${val.latitude ?? val.lat}, ${val.longitude ?? val.lng}`;
  if (typeof val === 'object') return JSON.stringify(val);
  return label(val);
}

const salesTab = (v) => (v.sales?.length ? h('div', { class: 'stack' }, v.sales.map((s, i) => card(`Sale ${i + 1} · ${fmtDateTime(s.captured_at)}`, simpleTable([
  { label: 'Product', render: (it) => it.product?.name || '—' }, { label: 'Qty', align: 'right', render: (it) => fmtNumber(it.quantity) }, { label: 'Unit price', align: 'right', render: (it) => fmtMoney(it.unit_price) },
  { label: 'Line total', align: 'right', render: (it) => fmtMoney(it.line_total ?? Number(it.quantity) * Number(it.unit_price)) }, { label: 'Promo', render: (it) => (it.is_promo ? badge('promo', { tone: 'info' }) : '') }], s.items || []),
{ flush: true, actions: h('span', { class: 'small' }, `${titleCase(s.payment_method || '')} · `, h('strong', null, fmtMoney(s.total_value))) }))) : empty('No sales recorded'));
const yn = (b) => (b === null || b === undefined ? '—' : b ? badge('yes', { tone: 'ok' }) : badge('no', { tone: 'danger' }));
const osaTab = (v) => (v.osa_records?.length ? card('On-shelf availability', simpleTable([
  { label: 'Product', render: (r) => r.product?.name || '—' }, { label: 'Available', render: (r) => yn(r.available) }, { label: 'Out of stock', render: (r) => yn(r.out_of_stock) },
  { label: 'Shelf qty', align: 'right', key: 'shelf_quantity' }, { label: 'Facings', align: 'right', key: 'facings' }, { label: 'Share of shelf', align: 'right', render: (r) => (r.share_of_shelf != null ? `${r.share_of_shelf}%` : '—') },
  { label: 'Price on shelf', align: 'right', render: (r) => fmtMoney(r.price_on_shelf) }, { label: 'Display OK', render: (r) => yn(r.display_compliant) }], v.osa_records), { flush: true }) : empty('No OSA records'));
const merchTab = (v) => (v.merchandising_records?.length ? card('Merchandising', simpleTable([
  { label: 'POSM', render: (r) => yn(r.posm_present) }, { label: 'POSM type', key: 'posm_type' }, { label: 'Shelf position', key: 'shelf_position' }, { label: 'Planogram', render: (r) => yn(r.planogram_compliant) },
  { label: 'Price', render: (r) => yn(r.price_compliant) }, { label: 'Competitor', render: (r) => yn(r.competitor_present) }, { label: 'Notes', render: (r) => r.competitor_notes || r.notes || '—' }], v.merchandising_records), { flush: true }) : empty('No merchandising records'));
