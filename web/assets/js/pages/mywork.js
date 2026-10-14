import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api } from '../core/api.js';
import { can } from '../core/auth.js';
import { fmtDate, today } from '../core/format.js';
import { asyncView, badge, card, pageHead, empty, toast, toastError, actionButton } from '../ui/feedback.js';
import { navigate } from '../core/router.js';

export default async function render({ container }) {
  container.append(pageHead({ title: 'My work', subtitle: 'Your assignments for today and the days ahead.' }),
    h('div', { class: 'alert info', style: { marginBottom: '16px' } }, icon('info'), h('div', null, 'Visits are captured in the Field Operations mobile app (GPS check-in, forms, photos, offline). This page lets you review and accept your assignments and follow up on submitted visits.')));
  const host = h('div'); container.append(host);
  asyncView(host, async () => {
    const from = today();
    const [upcoming, visits] = await Promise.all([
      api.get('assignments', { date_from: from, per_page: 50, sort: 'scheduled_date' }),
      can('visits.view') ? api.get('visits', { per_page: 10, sort: '-visit_date' }) : { data: [] },
    ]);
    return { upcoming: upcoming.data.filter((a) => a.status !== 'cancelled'), visits: visits.data };
  }, ({ upcoming, visits }, reload) => {
    const byDay = upcoming.reduce((m, a) => { (m[a.scheduled_date.slice(0, 10)] ||= []).push(a); return m; }, {});
    const days = Object.entries(byDay).sort(([a], [b]) => a.localeCompare(b));
    return h('div', { class: 'stack' },
      days.length ? days.map(([day, items]) => card(day === today() ? `Today · ${fmtDate(day)}` : fmtDate(day), h('ul', { class: 'work-list' }, items.map((a) => h('li', null,
        h('div', { style: { flex: 1 } }, h('strong', null, a.location?.name || 'Location'), h('div', { class: 'small muted' }, [a.location?.town, a.campaign?.name, a.activity?.name].filter(Boolean).join(' · ')),
          a.start_time && h('div', { class: 'small muted' }, `${a.start_time.slice(0, 5)}${a.end_time ? `–${a.end_time.slice(0, 5)}` : ''}`), a.instructions && h('div', { class: 'small', style: { marginTop: '4px' } }, a.instructions)),
        badge(a.status),
        a.status === 'pending' && can('assignments.respond') && actionButton('Accept', async () => { try { await api.post(`assignments/${a.id}/accept`); toast('Assignment accepted.'); reload(); } catch (e) { toastError(e); } }, { cls: 'btn sm primary' })))))) : card('Upcoming assignments', empty('Nothing scheduled', 'New assignments from your supervisor will appear here.')),
      visits.length > 0 && card('Recent visits', h('ul', { class: 'work-list' }, visits.map((v) => h('li', { class: 'clickable', onclick: () => navigate(`/visits/${v.id}`) },
        h('div', { style: { flex: 1 } }, h('strong', null, v.location?.name || '—'), h('div', { class: 'small muted' }, `${fmtDate(v.visit_date)} · ${v.campaign?.name || ''}`), v.status === 'rejected' && v.review_note && h('div', { class: 'small', style: { color: 'var(--danger)' } }, `Rejected: ${v.review_note}`)),
        badge(v.status))))));
  });
}
