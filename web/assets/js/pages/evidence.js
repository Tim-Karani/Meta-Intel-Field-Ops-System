import { h, clear } from '../core/dom.js';
import { api } from '../core/api.js';
import { fmtNumber } from '../core/format.js';
import { pageHead, asyncView } from '../ui/feedback.js';
import { photoGrid } from '../ui/gallery.js';
import { selectControl } from '../ui/form.js';
import { lookup } from '../core/lookups.js';
import { icon } from '../core/icons.js';

export default async function render({ container }) {
  const campaigns = await lookup('campaigns').catch(() => []);
  const filters = { campaign_id: '', review_status: '', kind: '' };
  let page = 1;
  const grid = h('div');
  const pager = h('div', { class: 'pager' });
  const bar = h('div', { class: 'toolbar' },
    selectControl({ label: 'Campaign', options: campaigns, blank: 'All campaigns', onChange: (v) => { filters.campaign_id = v; page = 1; load(); } }),
    selectControl({ label: 'Review status', options: ['pending', 'accepted', 'rejected'], blank: 'Any review status', onChange: (v) => { filters.review_status = v; page = 1; load(); } }),
    selectControl({ label: 'Kind', options: ['evidence', 'signature'], blank: 'Photos & signatures', onChange: (v) => { filters.kind = v; page = 1; load(); } }));
  container.append(pageHead({ title: 'Evidence', subtitle: 'Photos and signatures captured in the field. Open a photo to review it.' }), h('div', { class: 'card' }, bar, h('div', { class: 'card-body' }, grid), pager));
  let run;
  function load() {
    run = asyncView(grid, () => api.get('photos', { ...Object.fromEntries(Object.entries(filters).filter(([, v]) => v)), page, per_page: 24 }), ({ data, meta }) => {
      clear(pager);
      if (meta?.total) {
        pager.append(h('span', null, `${fmtNumber(meta.total)} item${meta.total === 1 ? '' : 's'}`), h('span', { class: 'spacer' }),
          h('button', { class: 'btn sm', type: 'button', disabled: meta.page <= 1, onclick: () => { page--; load(); } }, icon('chevronLeft', { size: 14 }), 'Prev'),
          h('span', null, `Page ${meta.page} of ${meta.last_page}`),
          h('button', { class: 'btn sm', type: 'button', disabled: meta.page >= meta.last_page, onclick: () => { page++; load(); } }, 'Next', icon('chevronRight', { size: 14 })));
      }
      return photoGrid(data, { onChange: () => run() });
    });
  }
  load();
}
