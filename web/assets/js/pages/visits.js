import { h } from '../core/dom.js';
import { can } from '../core/auth.js';
import { fmtDate, fmtDateTime, relTime } from '../core/format.js';
import { badge, pageHead, tabs } from '../ui/feedback.js';
import { dataTable } from '../ui/table.js';
import { lookup, enumOptions } from '../core/lookups.js';
import { navigate, href } from '../core/router.js';

export default async function render({ container, query }) {
  const [campaigns, statuses, outcomes, agents] = await Promise.all([lookup('campaigns').catch(() => []), enumOptions('visit_status').catch(() => []), enumOptions('gps_outcome').catch(() => []), can('assignments.create') ? lookup('agents').catch(() => []) : []]);
  const list = (extra = {}, sortDefault = '-visit_date') => () => dataTable({
    endpoint: 'visits', query: extra, id: `visits-${JSON.stringify(extra)}`, defaultSort: sortDefault, search: false,
    filters: [{ key: 'campaign_id', label: 'All campaigns', options: campaigns }, !extra.needs_review && { key: 'status', label: 'All statuses', options: statuses },
      { key: 'gps_outcome', label: 'Any GPS result', options: outcomes }, agents.length && { key: 'agent_id', label: 'All agents', options: agents }, { key: 'date_from', label: 'From date', type: 'date' }, { key: 'date_to', label: 'To date', type: 'date' }].filter(Boolean),
    columns: [
      { key: 'visit_date', label: 'Date', sort: 'visit_date', render: (r) => fmtDate(r.visit_date) },
      { key: 'location', label: 'Location', render: (r) => h('div', null, h('a', { href: href(`/visits/${r.id}`) }, h('strong', null, r.location?.name || '—')), r.location?.town && h('div', { class: 'small muted' }, r.location.town)) },
      { key: 'agent', label: 'Agent', render: (r) => r.agent?.name || '—' },
      { key: 'campaign', label: 'Campaign', render: (r) => r.campaign?.name || '—' },
      { key: 'gps', label: 'GPS', render: (r) => badge(r.gps_outcome) },
      { key: 'submitted_at', label: extra.needs_review ? 'Waiting' : 'Submitted', sort: 'submitted_at', render: (r) => (r.submitted_at ? h('span', { title: fmtDateTime(r.submitted_at) }, relTime(r.submitted_at)) : '—') },
      { key: 'status', label: 'Status', sort: 'status', render: (r) => badge(r.status) },
    ],
    onRowClick: (r) => navigate(`/visits/${r.id}`), rowClass: (r) => (r.gps_outcome === 'suspicious' ? 'row-flag' : ''),
    emptyTitle: extra.needs_review ? 'Nothing waiting for review' : 'No visits found', emptyText: extra.needs_review ? 'Submitted visits will queue here.' : 'Visits appear once agents start work.',
  });
  container.append(pageHead({ title: 'Visits', subtitle: 'Field visits, GPS validation and review.' }),
    tabs([
      can('visits.review') && { key: 'queue', label: 'Needs review', render: list({ needs_review: 1 }, '-submitted_at') },
      { key: 'all', label: 'All visits', render: list() },
    ].filter(Boolean), { initial: query?.tab === 'all' ? 'all' : undefined }));
}
