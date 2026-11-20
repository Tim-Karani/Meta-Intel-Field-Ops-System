import { h } from '../core/dom.js';
import { fmtDate, fmtMoney, fmtNumber, fmtDateTime } from '../core/format.js';
import { badge, pageHead } from '../ui/feedback.js';
import { dataTable } from '../ui/table.js';
import { lookup } from '../core/lookups.js';
import { can } from '../core/auth.js';
import { navigate } from '../core/router.js';

const yn = (b) => (b === null || b === undefined ? h('span', { class: 'muted' }, '—') : b ? badge('yes', { tone: 'ok' }) : badge('no', { tone: 'danger' }));
const visitCols = [
  { key: 'visit_date', label: 'Visit', render: (r) => fmtDate(r.visit?.visit_date) },
  { key: 'location', label: 'Location', render: (r) => r.visit?.location?.name || '—' },
  { key: 'agent', label: 'Agent', render: (r) => r.visit?.agent?.name || '—' },
];

const KINDS = {
  sales: {
    title: 'Sales', subtitle: 'Sales recorded during visits.', endpoint: 'sales', sort: '-captured_at',
    columns: [...visitCols,
      { key: 'items', label: 'Items', render: (r) => h('span', { title: (r.items || []).map((i) => `${i.product?.name} × ${i.quantity}`).join('\n') }, (r.items || []).slice(0, 2).map((i) => `${i.product?.name || '?'} × ${i.quantity}`).join(', '), (r.items || []).length > 2 && ` +${r.items.length - 2} more`) },
      { key: 'payment_method', label: 'Payment', render: (r) => (r.payment_method ? r.payment_method.toUpperCase() : '—') },
      { key: 'total_value', label: 'Total', sort: 'total_value', align: 'right', render: (r) => h('strong', null, fmtMoney(r.total_value)) },
      { key: 'captured_at', label: 'Captured', sort: 'captured_at', render: (r) => fmtDateTime(r.captured_at) }],
  },
  osa: {
    title: 'On-shelf availability', subtitle: 'Product availability, facings and shelf price captured in stores.', endpoint: 'osa', sort: '-captured_at',
    columns: [...visitCols, { key: 'product', label: 'Product', render: (r) => r.product?.name || '—' }, { key: 'available', label: 'Available', render: (r) => yn(r.available) },
      { key: 'out_of_stock', label: 'Out of stock', render: (r) => yn(r.out_of_stock) }, { key: 'shelf_quantity', label: 'Shelf qty', align: 'right', render: (r) => fmtNumber(r.shelf_quantity) },
      { key: 'facings', label: 'Facings', align: 'right', render: (r) => fmtNumber(r.facings) }, { key: 'price_on_shelf', label: 'Shelf price', align: 'right', render: (r) => fmtMoney(r.price_on_shelf) }],
  },
  orders: {
    title: 'Orders', subtitle: 'Orders taken at outlets: quantity and value.', endpoint: 'orders', sort: '-captured_at',
    columns: [...visitCols,
      { key: 'items', label: 'Items', render: (r) => h('span', { title: (r.items || []).map((i) => `${i.product?.name} × ${i.quantity}`).join('\n') }, (r.items || []).slice(0, 2).map((i) => `${i.product?.name || '?'} × ${i.quantity}`).join(', ') + ((r.items || []).length > 2 ? ` +${r.items.length - 2}` : '')) },
      { key: 'qty', label: 'Quantity', align: 'right', render: (r) => fmtNumber((r.items || []).reduce((a, i) => a + i.quantity, 0)) },
      { key: 'total_value', label: 'Value', sort: 'total_value', align: 'right', render: (r) => h('strong', null, fmtMoney(r.total_value)) },
      { key: 'captured_at', label: 'Captured', sort: 'captured_at', render: (r) => fmtDateTime(r.captured_at) }],
  },
  expiries: {
    title: 'Expiries', subtitle: 'Stock that expires: product, expiry date and quantity, with the date it was captured.', endpoint: 'expiries', sort: 'expiry_date',
    columns: [...visitCols, { key: 'product', label: 'Product', render: (r) => r.product?.name || '—' }, { key: 'batch_no', label: 'Batch', render: (r) => r.batch_no || '—' },
      { key: 'expiry_date', label: 'Expiry date', sort: 'expiry_date', render: (r) => fmtDate(r.expiry_date) }, { key: 'quantity', label: 'Quantity', align: 'right', render: (r) => fmtNumber(r.quantity) },
      { key: 'captured_at', label: 'Captured', sort: 'captured_at', render: (r) => fmtDateTime(r.captured_at) }],
  },
  stock_counts: {
    title: 'Stock count', subtitle: 'What is at the outlet: on the shelf and in the back room.', endpoint: 'stock-counts', sort: '-captured_at',
    columns: [...visitCols, { key: 'product', label: 'Product', render: (r) => r.product?.name || '—' }, { key: 'shelf_quantity', label: 'Shelf', align: 'right', render: (r) => fmtNumber(r.shelf_quantity) },
      { key: 'backroom_quantity', label: 'Back room', align: 'right', render: (r) => fmtNumber(r.backroom_quantity) }, { key: 'total_quantity', label: 'Total', align: 'right', render: (r) => h('strong', null, fmtNumber(r.total_quantity)) },
      { key: 'captured_at', label: 'Captured', sort: 'captured_at', render: (r) => fmtDateTime(r.captured_at) }],
  },
  merchandising: {
    title: 'Merchandising', subtitle: 'POSM, planogram and price compliance.', endpoint: 'merchandising', sort: '-captured_at',
    columns: [...visitCols, { key: 'product', label: 'Product', render: (r) => r.product?.name || '—' }, { key: 'posm_present', label: 'POSM', render: (r) => yn(r.posm_present) },
      { key: 'planogram_compliant', label: 'Planogram', render: (r) => yn(r.planogram_compliant) }, { key: 'price_compliant', label: 'Price', render: (r) => yn(r.price_compliant) },
      { key: 'competitor_present', label: 'Competitor', render: (r) => yn(r.competitor_present) }],
  },
};

export default async function render({ container, route }) {
  const k = KINDS[route.kind];
  const [campaigns, agents] = await Promise.all([lookup('campaigns').catch(() => []), can('assignments.create') ? lookup('agents').catch(() => []) : []]);
  container.append(pageHead({ title: k.title, subtitle: k.subtitle }), dataTable({
    endpoint: k.endpoint, id: k.endpoint, defaultSort: k.sort, search: false, columns: k.columns,
    filters: [{ key: 'campaign_id', label: 'All campaigns', options: campaigns }, agents.length && { key: 'agent_id', label: 'All agents', options: agents }, { key: 'date_from', label: 'From date', type: 'date' }, { key: 'date_to', label: 'To date', type: 'date' }].filter(Boolean),
    onRowClick: (r) => r.visit_id && can('visits.view') && navigate(`/visits/${r.visit_id}`), emptyTitle: `No ${k.title.toLowerCase()} data yet`,
    emptyText: 'Records appear here when agents capture them during visits.',
  }));
}
