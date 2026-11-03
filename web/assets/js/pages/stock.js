import { h } from '../core/dom.js';
import { api } from '../core/api.js';
import { can } from '../core/auth.js';
import { fmtDateTime, fmtNumber, fmtMoney, titleCase } from '../core/format.js';
import { asyncView, badge, card, empty, pageHead, toast } from '../ui/feedback.js';
import { simpleTable } from '../ui/table.js';
import { formModal } from '../ui/crud.js';
import { lookup } from '../core/lookups.js';
import { selectControl } from '../ui/form.js';
import { focus } from '../core/scope.js';

/* Stock for projects where agents sell (sales reps, foot soldiers): what each agent was given, what they sold, what they
   handed back, and what they should still be holding. Merchandising projects do not use this screen. */

const KEY = 'stock.campaign';
const remembered = () => { try { return sessionStorage.getItem(KEY) || ''; } catch { return ''; } };
const remember = (v) => { try { sessionStorage.setItem(KEY, v); } catch { /* fine */ } };

export default async function render({ container }) {
  const campaigns = await lookup('campaigns').catch(() => []);
  const host = h('div', { class: 'stack' });
  const focused = focus.get().campaign_id;
  let campaignId = campaigns.some((c) => c.value === focused) ? focused : campaigns.some((c) => c.value === remembered()) ? remembered() : (campaigns[0]?.value || '');
  const pick = selectControl({ label: 'Project', options: campaigns, blank: '— Choose a project —', value: campaignId, onChange: (v) => { campaignId = v; remember(v); load(); } });
  container.append(pageHead({ title: 'Stock', subtitle: 'Stock given to agents, what they sold and what they should still hold.' }), h('div', { class: 'card filter-bar' }, h('div', { class: 'toolbar' }, pick)), host);

  function load() {
    if (!campaignId) { host.replaceChildren(empty('No projects yet', 'Create a sales project first.')); return; }
    asyncView(host, async () => {
      const [s, m] = await Promise.all([api.get('stock', { campaign_id: campaignId }), api.get('stock/movements', { campaign_id: campaignId })]);
      return { s: s.data, m: m.data };
    }, ({ s, m }, reload) => {
      if (!s.campaign.sales_enabled) return empty('This project does not record sales', 'Switch the Sales task on in the project setup to issue and track stock.');
      const act = (kind, label) => h('button', { class: kind === 'issue' ? 'btn primary' : 'btn', type: 'button', onclick: () => movement(kind, label, reload) }, label);
      return h('div', { class: 'stack' },
        can('stock.manage') && h('div', { class: 'row' }, act('issue', 'Issue stock'), act('return', 'Record a return'), act('adjust', 'Correct a count')),
        card('Per agent', simpleTable([
          { label: 'Agent', key: 'agent' }, { label: 'Product', key: 'product' },
          { label: 'Issued', align: 'right', render: (r) => fmtNumber(r.issued) }, { label: 'Sold', align: 'right', render: (r) => fmtNumber(r.sold) },
          { label: 'Returned', align: 'right', render: (r) => fmtNumber(r.returned) },
          { label: 'Should hold', align: 'right', render: (r) => (r.balance < 0 ? badge(`${fmtNumber(r.balance)} (sold more than issued)`, { tone: 'danger' }) : h('strong', null, fmtNumber(r.balance))) },
          { label: 'Sales value', align: 'right', render: (r) => fmtMoney(r.sales_value) },
        ], s.rows, { emptyText: 'No stock has been issued and no sales recorded yet.' }), { flush: true }),
        card('Hand-over log', simpleTable([
          { label: 'When', render: (r) => fmtDateTime(r.created_at) }, { label: 'Agent', key: 'agent' }, { label: 'Product', key: 'product' },
          { label: 'What', render: (r) => titleCase(r.kind) }, { label: 'Qty', align: 'right', render: (r) => fmtNumber(r.quantity) }, { label: 'Note', render: (r) => r.note || '' },
        ], m, { emptyText: 'Nothing recorded yet.' }), { flush: true }));
    });
  }

  async function movement(kind, title, reload) {
    const [agents, leaders, products] = await Promise.all([lookup('agents').catch(() => []), lookup('team_leaders').catch(() => []), lookup('products').catch(() => [])]);
    formModal({
      title, size: 'md',
      intro: kind === 'issue' ? 'Record stock handed to an agent.' : kind === 'return' ? 'Record stock an agent handed back.' : 'Correct what the agent should hold. Use a negative number to remove stock. A reason is required.',
      fields: [
        { name: 'agent_id', label: 'Agent', type: 'select', required: true, options: [...agents, ...leaders], blank: '— Choose —' },
        { name: 'product_id', label: 'Product', type: 'select', required: true, options: products, blank: '— Choose —' },
        { name: 'quantity', label: 'Quantity', type: 'number', required: true, min: kind === 'adjust' ? -100000 : 1 },
        { name: 'note', label: kind === 'adjust' ? 'Reason' : 'Note (optional)', required: kind === 'adjust', maxlength: 255, full: true },
      ],
      submitLabel: 'Save',
      onSubmit: async (v) => { await api.post('stock', { ...v, campaign_id: campaignId, kind }); toast('Saved.'); },
      onSaved: reload,
    });
  }
  load();
}
