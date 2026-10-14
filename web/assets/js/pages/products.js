import { h } from '../core/dom.js';
import { crudPage } from '../ui/crud.js';
import { badge, tabs, pageHead } from '../ui/feedback.js';
import { lookup } from '../core/lookups.js';
import { api } from '../core/api.js';
import { fmtMoney } from '../core/format.js';

const opts = async (path) => (await api.get(path, { per_page: 100 })).data.map((r) => ({ value: r.id, label: r.name }));

export default async function render({ container }) {
  const [clients, brands, categories] = await Promise.all([lookup('clients').catch(() => []), opts('brands').catch(() => []), opts('product-categories').catch(() => [])]);

  const products = () => crudPage({
    embedded: true,
    title: 'Products', subtitle: 'Items sold, audited and merchandised in the field.', noun: 'product', endpoint: 'products',
    perms: { create: 'products.manage', edit: 'products.manage', delete: 'products.manage' }, defaultSort: 'name', searchPlaceholder: 'Search name or SKU…',
    fields: () => [
      { name: 'name', label: 'Product name', required: true }, { name: 'sku', label: 'SKU', maxlength: 60 },
      { name: 'client_id', label: 'Client', type: 'select', options: clients }, { name: 'brand_id', label: 'Brand', type: 'select', options: brands },
      { name: 'category_id', label: 'Category', type: 'select', options: categories }, { name: 'unit_price', label: 'Unit price (KES)', type: 'decimal', min: 0 },
      { name: 'status', label: 'Status', type: 'select', options: ['active', 'inactive'], blank: false, value: 'active' },
    ],
    filters: [{ key: 'client_id', label: 'All clients', options: clients }, { key: 'brand_id', label: 'All brands', options: brands }, { key: 'category_id', label: 'All categories', options: categories }, { key: 'status', label: 'All statuses', options: ['active', 'inactive'] }],
    columns: [
      { key: 'name', label: 'Product', sort: 'name', render: (r) => h('strong', null, r.name) },
      { key: 'sku', label: 'SKU', sort: 'sku', render: (r) => (r.sku ? h('span', { class: 'mono' }, r.sku) : '—') },
      { key: 'brand', label: 'Brand', render: (r) => r.brand?.name || '—' },
      { key: 'category', label: 'Category', render: (r) => r.category?.name || '—' },
      { key: 'client', label: 'Client', render: (r) => r.client?.name || '—' },
      { key: 'unit_price', label: 'Price', sort: 'unit_price', align: 'right', render: (r) => fmtMoney(r.unit_price) },
      { key: 'status', label: 'Status', render: (r) => badge(r.status) },
    ],
  });
  const brandTab = () => crudPage({
    embedded: true,
    title: 'Brands', noun: 'brand', endpoint: 'brands', perms: { create: 'products.manage', edit: 'products.manage', delete: 'products.manage' }, defaultSort: 'name',
    fields: () => [{ name: 'name', label: 'Brand name', required: true }, { name: 'client_id', label: 'Client', type: 'select', options: clients }],
    columns: [{ key: 'name', label: 'Brand', sort: 'name', render: (r) => h('strong', null, r.name) }, { key: 'client', label: 'Client', render: (r) => r.client?.name || '—' }],
  });
  const catTab = () => crudPage({
    embedded: true,
    title: 'Categories', noun: 'category', endpoint: 'product-categories', perms: { create: 'products.manage', edit: 'products.manage', delete: 'products.manage' }, defaultSort: 'name',
    fields: () => [{ name: 'name', label: 'Category name', required: true }],
    columns: [{ key: 'name', label: 'Category', sort: 'name', render: (r) => h('strong', null, r.name) }],
  });
  container.append(pageHead({ title: 'Products', subtitle: 'Catalogue used by sales, OSA and merchandising capture.' }),
    tabs([{ key: 'products', label: 'Products', render: products }, { key: 'brands', label: 'Brands', render: brandTab }, { key: 'categories', label: 'Categories', render: catTab }]));
}
