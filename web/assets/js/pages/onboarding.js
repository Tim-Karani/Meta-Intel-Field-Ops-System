import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api } from '../core/api.js';
import { can } from '../core/auth.js';
import { lookup } from '../core/lookups.js';
import { href } from '../core/router.js';
import { buildForm, selectControl } from '../ui/form.js';
import { pageHead, card, toastError } from '../ui/feedback.js';
import { loadTemplates, SOURCES } from '../ui/project-setup.js';
import { toRecords } from '../ui/location-import.js';
import { openOutletForm } from '../ui/outlet-form.js';

/* New client & project: one guided page instead of eight screens. Nothing is saved until the last step ("Launch");
   then each part is created in order, and if something fails you fix it and press Launch again (finished parts are not repeated). */

const STEPS = ['Client', 'Project', 'Outlets', 'Products', 'People', 'Launch'];
const slug = (s, n) => String(s || '').toUpperCase().replace(/[^A-Z0-9]+/g, '').slice(0, n);

export default async function render({ container }) {
  const [clients, templates, staff, forms] = await Promise.all([
    lookup('clients').catch(() => []), loadTemplates().catch(() => []), lookup('staff').catch(() => []),
    api.get('forms', { status: 'published', per_page: 100 }).then((r) => r.data.map((f) => ({ value: f.id, label: `${f.name} (v${f.version})` }))).catch(() => []),
  ]);
  const canNewClient = can('clients.create');
  const st = { step: 0, clientMode: clients.length && !canNewClient ? 'existing' : (clients.length ? 'existing' : 'new'), clientId: '', campaignId: null, done: {}, outlets: [], outletText: '', productText: '', staffIds: [], activate: true, result: null };

  // ---- step 1: client
  const modeNew = h('input', { type: 'radio', name: 'cm', id: 'cm-new', checked: st.clientMode === 'new' });
  const modeOld = h('input', { type: 'radio', name: 'cm', id: 'cm-old', checked: st.clientMode === 'existing', disabled: !clients.length });
  const clientSel = selectControl({ label: 'Client', options: clients, blank: '— Choose a client —', onChange: (v) => { st.clientId = v; } });
  const newClient = buildForm([
    { name: 'name', label: 'Client name', required: true, maxlength: 255, full: true },
    { name: 'code', label: 'Short code', maxlength: 40, help: 'Optional, e.g. KBL. Made from the name if left empty.' },
    { name: 'industry', label: 'Industry' }, { name: 'email', label: 'Contact email', type: 'email' }, { name: 'phone', label: 'Contact phone' },
  ]);
  const clientBox = h('div', { class: 'stack' });
  const paintClient = () => {
    st.clientMode = modeNew.checked ? 'new' : 'existing';
    clientBox.replaceChildren(st.clientMode === 'new' ? newClient.el : clientSel);
  };
  modeNew.checked = st.clientMode === 'new'; modeOld.checked = st.clientMode === 'existing';
  [modeNew, modeOld].forEach((r) => r.addEventListener('change', paintClient));
  const stepClient = () => h('div', { class: 'stack' },
    h('p', { class: 'muted' }, 'Who is this work for? Pick a client you already have, or add a new one in a few seconds.'),
    h('div', { class: 'row' }, h('label', { class: 'check', for: 'cm-old' }, modeOld, 'Existing client'), canNewClient && h('label', { class: 'check', for: 'cm-new' }, modeNew, 'New client')),
    clientBox);

  // ---- step 2: project
  const tpl = templates.map((t) => ({ value: t.id, label: t.name }));
  const project = buildForm([
    { name: 'name', label: 'Project name', required: true, full: true, placeholder: 'e.g. Nairobi shelf audit - November' },
    { name: 'code', label: 'Project code', required: true, maxlength: 40, help: 'Filled in for you; change it if you like.' },
    { name: 'template_id', label: 'Project type', type: 'select', required: true, options: tpl, blank: '— Choose —', help: 'Decides how outlets are added, who may add them, what the agent does and which photos are needed.' },
    { name: 'start_date', label: 'Starts', type: 'date' }, { name: 'end_date', label: 'Ends', type: 'date' },
    { name: 'budget', label: 'Budget (optional)', type: 'decimal', min: 0 },
    { name: 'activity', label: 'What do agents do?', value: 'Store visit', help: 'The first activity, e.g. "Shelf audit". More can be added later.' },
    { name: 'form_id', label: 'Data-capture form (optional)', type: 'select', options: forms, blank: 'None yet - add later', help: 'Only published forms are listed. Build one under Forms.' },
  ]);
  const tplNote = h('div', { class: 'alert info' });
  const showTpl = () => {
    const t = templates.find((x) => x.id === project.read().template_id);
    tplNote.replaceChildren(...(t ? [icon('info'), h('div', null, h('strong', null, t.name), ' - ', t.description || '', ' Outlets: ', (SOURCES.find((s) => s.value === t.settings?.outlet_source)?.label || 'a mix of office and field').toLowerCase(), t.settings?.journey_plan ? '; permanent agents follow a fixed journey plan' : '', '.')] : []));
    tplNote.hidden = !t;
  };
  project.control('template_id').addEventListener('change', showTpl);
  project.control('name').addEventListener('input', () => {
    const c = project.control('code');
    if (!c.dataset.touched) { const cl = st.clientMode === 'existing' ? clients.find((x) => x.value === st.clientId)?.label : newClient.read().name; project.set('code', `${slug(cl, 4) || 'PRJ'}-${slug(project.read().name, 8)}-${String(new Date().getFullYear()).slice(2)}`); }
  });
  project.control('code').addEventListener('input', (e) => { e.target.dataset.touched = '1'; });
  const stepProject = () => h('div', { class: 'stack' }, h('p', { class: 'muted' }, 'Name the project and pick its type. The type fills in all the rules for you.'), project.el, tplNote);

  // ---- step 3: outlets (with their positions: set by the office, so agents are checked in against a known place)
  const file = h('input', { type: 'file', accept: '.csv,text/csv,text/plain', class: 'input', 'aria-label': 'Outlets CSV' });
  const paste = h('textarea', { class: 'textarea', rows: 4, placeholder: 'Or paste rows here, starting with the header line: name,code,type,address,town,county,latitude,longitude' });
  const outletInfo = h('div', { class: 'small' });
  const manualList = h('div', { class: 'stack' });
  const manual = []; let csvRows = [];
  const sync = () => {
    st.outlets = [...csvRows, ...manual];
    const withGeo = st.outlets.filter((o) => o.latitude !== '' && o.latitude != null && o.longitude !== '' && o.longitude != null).length;
    const without = st.outlets.length - withGeo;
    outletInfo.replaceChildren(...(st.outlets.length
      ? [h('strong', null, `${st.outlets.length} outlet${st.outlets.length === 1 ? '' : 's'}`), ` ready: ${withGeo} with a position${without ? `, ` : '.'}`, without ? h('span', { class: 'err' }, `${without} without one`) : null,
        without ? h('div', { class: 'help' }, 'Outlets without a position cannot be checked in against a known place. Add their coordinates now (or later under Locations, filter "Not mapped yet"), unless agents will map them on their first visit.') : null]
      : ['No outlets added - fine if agents will add them in the field.']));
    manualList.replaceChildren(...manual.map((o, i) => h('div', { class: 'row', style: { justifyContent: 'space-between' } },
      h('span', null, h('strong', null, o.name), (o.town || o.area || o.locality) ? ` · ${o.town || o.area || o.locality}` : '', o.channel ? ` · ${o.channel}` : '', o.latitude != null && o.latitude !== '' ? h('span', { class: 'badge ok', style: { marginLeft: '8px' } }, 'position set') : h('span', { class: 'badge warn', style: { marginLeft: '8px' } }, 'no position')),
      h('div', { class: 'row' }, h('button', { class: 'btn sm ghost', type: 'button', onclick: () => editDraft(i) }, icon('edit', { size: 14 }), 'Edit'), h('button', { class: 'btn sm ghost', type: 'button', onclick: () => { manual.splice(i, 1); sync(); } }, 'Remove')))));
  };
  const readCsv = async () => { const text = file.files?.[0] ? await file.files[0].text() : paste.value; csvRows = toRecords(text || '').records; sync(); };
  [file, paste].forEach((el) => el.addEventListener('input', readCsv));
  const addDraft = (row) => { manual.push(row); sync(); };
  const editDraft = (i) => openOutletForm({ draft: true, location: { ...manual[i]._form, name: manual[i].name }, onDraft: (row) => { manual[i] = row; sync(); } });
  const stepOutlets = () => { sync(); return h('div', { class: 'stack' },
    h('p', { class: 'muted' }, 'Add the outlets and where they are. Positions entered here are trusted, so agents are checked in against them (within 200 m) and cannot make up or move an outlet. Optional if agents add outlets as they go.'),
    h('h4', null, 'Upload a list'), h('div', { class: 'field' }, h('label', null, 'CSV file (Excel > Save As CSV)'), file), paste,
    h('div', { class: 'small muted' }, 'Columns: name (required) and any of: code, type, subtype, chain (channel), channel_group, distributor, status, region, locality, area, address, town, county, latitude, longitude, geofence_radius_m, contact_name, contact_phone, phone, email, shop_no, market_name, setting, territory, opening_hours, notes. Chains, distributors and places named in the file are created if new.'),
    h('h4', null, 'Or add them one at a time'),
    h('div', { class: 'row' }, h('button', { class: 'btn sm primary', type: 'button', onclick: () => openOutletForm({ draft: true, onDraft: addDraft }) }, icon('plus', { size: 14 }), 'Add an outlet'),
      h('span', { class: 'small muted' }, 'The full outlet form: map and search, chain, type, place, distributor, contact. You can reopen any outlet in the list to change it.')),
    manualList, outletInfo); };

  // ---- step 4: products
  const prodArea = h('textarea', { class: 'textarea', rows: 7, placeholder: 'One product per line. Price is optional.\nSoda 500ml, 60\nSoda 2L, 150\nJuice 1L' });
  prodArea.addEventListener('input', () => { st.productText = prodArea.value; });
  const stepProducts = () => { prodArea.value = st.productText; return h('div', { class: 'stack' },
    h('p', { class: 'muted' }, 'Optional. The items agents sell or audit. Type or paste one per line as "name, price". They are added to the client and linked to this project.'), prodArea); };

  // ---- step 5: people
  const people = buildForm([{ name: 'staff', label: 'Staff on this project', type: 'multiselect', options: staff, full: true, help: 'Team Leaders, supervisors and agents who may work on it. You can change this any time under the campaign.' }]);
  const stepPeople = () => h('div', { class: 'stack' }, h('p', { class: 'muted' }, 'Who works on this project? Need someone new? Add them under Users first.'), people.el);

  // ---- step 6: launch
  const progress = h('div', { class: 'stack' });
  const activate = h('input', { type: 'checkbox', id: 'act' }); activate.checked = true;
  activate.addEventListener('change', () => { st.activate = activate.checked; });
  const summary = () => {
    const p = project.read();
    const cl = st.clientMode === 'new' ? `${newClient.read().name || '?'} (new)` : clients.find((x) => x.value === st.clientId)?.label || '?';
    const rows = [['Client', cl], ['Project', `${p.name || '?'} (${p.code || '?'})`], ['Type', tpl.find((t) => t.value === p.template_id)?.label || '?'], ['Dates', `${p.start_date || 'not set'} to ${p.end_date || 'not set'}`],
      ['Activity', p.activity || 'Store visit'], ['Outlets to import', `${st.outlets.length} (${st.outlets.filter((o) => o.latitude !== '' && o.latitude != null).length} with a position)`], ['Products', String(parseProducts().length)], ['People', String(people.read().staff?.length || 0)]];
    return h('table', { class: 'data' }, h('tbody', null, rows.map(([k, v]) => h('tr', null, h('th', { scope: 'row' }, k), h('td', null, v)))));
  };
  const parseProducts = () => st.productText.split(/\r?\n/).map((l) => l.trim()).filter(Boolean).map((l) => { const i = l.lastIndexOf(','); const price = i > 0 ? Number(l.slice(i + 1).replace(/[^0-9.]/g, '')) : NaN; return i > 0 && !Number.isNaN(price) ? { name: l.slice(0, i).trim(), unit_price: price } : { name: l }; });
  const launchBtn = h('button', { class: 'btn primary', type: 'button' }, icon('zap', { size: 16 }), 'Launch project');
  const stepLaunch = () => h('div', { class: 'stack' }, h('p', { class: 'muted' }, 'Check the summary, then launch. Everything is created in one go.'), summary(),
    h('label', { class: 'check', for: 'act' }, activate, 'Make the project live straight away (otherwise it stays a draft)'), launchBtn, progress);

  const mark = (label, state, msg) => { // state: wait | run | ok | fail
    let li = progress.querySelector(`[data-k="${label}"]`);
    if (!li) { li = h('div', { class: 'row', 'data-k': label }); progress.append(li); }
    li.replaceChildren(icon(state === 'ok' ? 'checkCircle' : state === 'fail' ? 'alert' : 'clock', { size: 16 }), h('span', null, label), msg && h('span', { class: state === 'fail' ? 'err' : 'muted small' }, msg));
  };
  const stage = async (label, fn) => {
    if (st.done[label]) { mark(label, 'ok', 'done'); return; }
    mark(label, 'run');
    try { const note = await fn(); st.done[label] = true; mark(label, 'ok', note || ''); } catch (e) { mark(label, 'fail', e?.message || 'Failed'); throw e; }
  };
  const launch = async () => {
    launchBtn.disabled = true; progress.replaceChildren();
    const p = project.read();
    try {
      await stage('Client', async () => {
        if (st.clientMode === 'new') {
          const c = newClient.read();
          st.clientId = (await api.post('clients', { name: c.name, code: c.code || slug(c.name, 6) || undefined, industry: c.industry, email: c.email, phone: c.phone, status: 'active' })).data.id;
          return 'created';
        }
        return 'existing';
      });
      await stage('Project', async () => {
        const body = { client_id: st.clientId, name: p.name, code: p.code, template_id: p.template_id };
        ['start_date', 'end_date', 'budget'].forEach((k) => { if (p[k] !== null && p[k] !== '') body[k] = p[k]; });
        st.campaignId = (await api.post('campaigns', body)).data.id;
        return 'created as a draft';
      });
      await stage('Activity', async () => {
        const body = { campaign_id: st.campaignId, name: p.activity || 'Store visit', activity_type: 'visit' };
        if (p.form_id) body.form_id = p.form_id;
        await api.post('activities', body);
      });
      if (st.outlets.length) {
        await stage('Outlets', async () => {
          let created = 0; let linked = 0; let rejected = 0;
          for (let i = 0; i < st.outlets.length; i += 500) {
            const r = (await api.post('locations/import', { rows: st.outlets.slice(i, i + 500).map(({ _form, ...r }) => r), campaign_id: st.campaignId, client_id: st.clientId })).data;
            created += r.created; linked += r.linked_existing; rejected += r.rejected;
          }
          return `${created} added, ${linked} reused${rejected ? `, ${rejected} rejected (see Locations > Import to retry them)` : ''}`;
        });
      }
      const prods = parseProducts();
      if (prods.length) {
        await stage('Products', async () => {
          const ids = [];
          for (const x of prods) ids.push((await api.post('products', { ...x, client_id: st.clientId, status: 'active' })).data.id);
          await api.put(`campaigns/${st.campaignId}/links/products`, { ids });
          return `${ids.length} added`;
        });
      }
      if (p.form_id) await stage('Form', async () => { await api.put(`campaigns/${st.campaignId}/links/forms`, { ids: [p.form_id] }); });
      const ids = people.read().staff || [];
      if (ids.length) await stage('People', async () => { await api.put(`campaigns/${st.campaignId}/links/users`, { ids }); return `${ids.length} added`; });
      if (st.activate) {
        await stage('Go live', async () => {
          await api.post(`campaigns/${st.campaignId}/transition`, { status: 'planned' });
          await api.post(`campaigns/${st.campaignId}/transition`, { status: 'active' });
          return 'active';
        });
      }
      st.result = true;
      progress.append(h('div', { class: 'alert ok' }, icon('checkCircle'), h('div', null, h('strong', null, 'All set. '), 'Next: give agents their work.')),
        h('div', { class: 'row' }, h('a', { class: 'btn primary', href: href(`/campaigns/${st.campaignId}`) }, 'Open the project'),
          can('assignments.create') && h('a', { class: 'btn', href: href('/assignments') }, 'Create assignments'),
          can('assignments.create') && h('a', { class: 'btn', href: href('/journey-plans') }, 'Journey plans'),
          h('a', { class: 'btn ghost', href: href('/onboarding'), onclick: () => setTimeout(() => location.reload(), 0) }, 'Onboard another')));
      launchBtn.hidden = true;
    } catch (e) { toastError(e); progress.append(h('div', { class: 'alert warn' }, icon('alert'), h('div', null, 'Something needs fixing. Go back, correct it, then press Launch again - finished parts are not repeated.'))); launchBtn.disabled = false; }
  };
  launchBtn.addEventListener('click', launch);

  // ---- stepper chrome
  const panel = h('div', { class: 'wiz-panel' });
  const bar = h('ol', { class: 'wiz-steps' });
  const back = h('button', { class: 'btn', type: 'button' }, 'Back');
  const next = h('button', { class: 'btn primary', type: 'button' }, 'Next');
  const problem = h('div', { class: 'err', role: 'alert' });
  const bodies = [stepClient, stepProject, stepOutlets, stepProducts, stepPeople, stepLaunch];
  const paint = () => {
    bar.replaceChildren(...STEPS.map((s, i) => h('li', { class: [i === st.step && 'on', i < st.step && 'done'], 'aria-current': i === st.step ? 'step' : null }, h('span', { class: 'n' }, String(i + 1)), h('span', null, s))));
    panel.replaceChildren(bodies[st.step]());
    if (st.step === 0) paintClient();
    back.hidden = st.step === 0 || st.result === true; next.hidden = st.step === STEPS.length - 1; problem.textContent = '';
    if (st.step === 1) showTpl();
  };
  const valid = () => {
    if (st.step === 0) {
      if (st.campaignId) return true;
      if (st.clientMode === 'new') { if (!newClient.read().name) { problem.textContent = 'Enter the client name.'; return false; } }
      else if (!st.clientId) { problem.textContent = 'Choose a client.'; return false; }
    }
    if (st.step === 1) { const p = project.read(); if (!p.name || !p.code || !p.template_id) { problem.textContent = 'Project name, code and type are needed.'; return false; } if (p.start_date && p.end_date && p.end_date < p.start_date) { problem.textContent = 'The end date is before the start date.'; return false; } }
    return true;
  };
  next.addEventListener('click', () => { if (valid()) { st.step += 1; paint(); } });
  back.addEventListener('click', () => { st.step -= 1; paint(); });
  container.append(pageHead({ title: 'New client & project', subtitle: 'Set up a client and a live project in about five minutes. Nothing is saved until you launch.' }),
    card(null, h('div', null, bar, panel, problem, h('div', { class: 'row', style: { justifyContent: 'space-between', marginTop: '16px' } }, back, next))));
  paint();
}
