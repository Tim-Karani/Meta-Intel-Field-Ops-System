import { h, clear } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api } from '../core/api.js';
import { can } from '../core/auth.js';
import { QUESTION_TYPE_LABELS, CONDITION_OPERATORS } from '../core/constants.js';
import { asyncView, badge, pageHead, toast, toastError, confirmDialog, openModal, errorBox } from '../ui/feedback.js';
import { buildForm } from '../ui/form.js';
import { navigate } from '../core/router.js';

const CHOICE = ['single_select', 'multi_select', 'dropdown'];
const NO_DEPENDENCY = ['photo', 'signature'];
const uid = () => `q_${Math.random().toString(36).slice(2, 10)}`;
const slug = (s) => String(s).toLowerCase().trim().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '').slice(0, 60);
const typeOptions = Object.entries(QUESTION_TYPE_LABELS).map(([value, label]) => ({ value, label }));

export default async function render({ container, params }) {
  const holder = h('div'); container.append(holder);
  asyncView(holder, () => api.get(`forms/${params.id}`).then((r) => r.data), (form, reload) => builder(form, reload));
}

function builder(form, reload) {
  const editable = form.status === 'draft' && can('forms.manage');
  // Working copy; saved to the server as one atomic structure replace.
  const state = { sections: structuredClone(form.sections || []), dirty: false };
  const body = h('div');
  const saveBar = h('span', { class: 'small muted' });
  const mark = () => { state.dirty = true; saveBar.textContent = 'Unsaved changes'; };

  const allQuestions = () => state.sections.flatMap((s) => s.questions);

  function paint() {
    clear(body);
    if (!state.sections.length) body.append(h('div', { class: 'empty' }, icon('layers', { size: 36 }), h('h3', null, 'This form has no sections yet'), editable && h('p', null, 'Add a section, then add questions to it.')));
    state.sections.forEach((s, si) => body.append(sectionBlock(s, si)));
    if (editable) body.append(h('button', { class: 'btn', type: 'button', onclick: addSection }, icon('plus', { size: 16 }), 'Add section'));
  }

  const move = (arr, i, d) => { const j = i + d; if (j < 0 || j >= arr.length) return; [arr[i], arr[j]] = [arr[j], arr[i]]; mark(); paint(); };

  function addSection() {
    state.sections.push({ title: `Section ${state.sections.length + 1}`, description: '', questions: [] }); mark(); paint();
  }

  function editSection(s) {
    const { el, read } = buildForm([{ name: 'title', label: 'Section title', required: true, full: true }, { name: 'description', label: 'Description', type: 'textarea', full: true }], s);
    const m = openModal({ title: 'Edit section', body: el, footer: (close) => [h('button', { class: 'btn', type: 'button', onclick: () => close() }, 'Cancel'),
      h('button', { class: 'btn primary', type: 'button', onclick: () => { const v = read(); if (!v.title) return; Object.assign(s, { title: v.title, description: v.description || '' }); mark(); paint(); close(); } }, 'Apply')] });
    void m;
  }

  async function removeSection(si) {
    const s = state.sections[si];
    if (s.questions.length && !(await confirmDialog({ title: 'Delete section?', message: `“${s.title}” and its ${s.questions.length} question(s) will be removed from this draft.`, confirmLabel: 'Delete', danger: true }))) return;
    const gone = new Set(s.questions.map((q) => q.ref));
    state.sections.splice(si, 1);
    // drop conditions that pointed at removed questions
    allQuestions().forEach((q) => { q.conditions = (q.conditions || []).filter((c) => !gone.has(c.depends_on)); });
    mark(); paint();
  }

  async function questionDialog(existing, si, qi) {
    const prior = []; // questions strictly before this position
    state.sections.forEach((s, a) => s.questions.forEach((q, b) => { if (a < si || (a === si && b < (qi ?? s.questions.length))) prior.push(q); }));
    const result = await editQuestion(existing, prior.filter((q) => q !== existing));
    if (!result) return;
    if (existing) Object.assign(existing, result); else state.sections[si].questions.push(result);
    mark(); paint();
  }

  function sectionBlock(s, si) {
    const qs = s.questions;
    return h('div', { class: 'section-block' },
      h('div', { class: 'row', style: { marginBottom: '10px' } },
        h('div', { style: { flex: 1 } }, h('h3', { style: { margin: 0 } }, s.title), s.description && h('div', { class: 'small muted' }, s.description)),
        editable && h('div', { class: 'row' },
          h('button', { class: 'btn sm ghost', type: 'button', 'aria-label': 'Move section up', disabled: si === 0, onclick: () => move(state.sections, si, -1) }, icon('arrowUp', { size: 15 })),
          h('button', { class: 'btn sm ghost', type: 'button', 'aria-label': 'Move section down', disabled: si === state.sections.length - 1, onclick: () => move(state.sections, si, 1) }, icon('arrowDown', { size: 15 })),
          h('button', { class: 'btn sm ghost', type: 'button', 'aria-label': 'Edit section', onclick: () => editSection(s) }, icon('edit', { size: 15 })),
          h('button', { class: 'btn sm ghost', type: 'button', 'aria-label': 'Delete section', onclick: () => removeSection(si) }, icon('trash', { size: 15 })))),
      qs.length ? qs.map((q, qi) => questionCard(q, si, qi)) : h('div', { class: 'muted small', style: { padding: '8px 0' } }, 'No questions in this section.'),
      editable && h('div', { style: { marginTop: '10px' } }, h('button', { class: 'btn sm', type: 'button', onclick: () => questionDialog(null, si, null) }, icon('plus', { size: 14 }), 'Add question')));
  }

  function questionCard(q, si, qi) {
    const qs = state.sections[si].questions;
    const numbering = allQuestions().indexOf(q) + 1;
    const dep = (q.conditions || []).map((c) => { const t = allQuestions().find((x) => x.ref === c.depends_on); return t ? `${t.label} ${CONDITION_OPERATORS[c.operator] || c.operator}${c.operator === 'answered' ? '' : ` “${[].concat(c.value ?? '').join(', ')}”`}` : null; }).filter(Boolean);
    return h('div', { class: 'q-card' },
      h('span', { class: 'muted small', style: { minWidth: '24px' } }, `${numbering}.`),
      h('div', { class: 'q-main' },
        h('div', null, h('strong', null, q.label)),
        h('div', { class: 'pill-row', style: { marginTop: '4px' } }, h('span', { class: 'badge info' }, QUESTION_TYPE_LABELS[q.type] || q.type),
          q.required && h('span', { class: 'badge warn' }, 'Required'), q.evidence_required && h('span', { class: 'badge' }, 'Evidence required'),
          (q.options || []).length > 0 && h('span', { class: 'badge' }, `${q.options.length} options`)),
        dep.length > 0 && h('div', { class: 'small muted', style: { marginTop: '4px' } }, `Shown when: ${dep.join(' and ')}`),
        q.help_text && h('div', { class: 'small muted' }, q.help_text)),
      editable && h('div', { class: 'row' },
        h('button', { class: 'btn sm ghost', type: 'button', 'aria-label': 'Move question up', disabled: qi === 0, onclick: () => moveQuestion(si, qi, -1) }, icon('arrowUp', { size: 15 })),
        h('button', { class: 'btn sm ghost', type: 'button', 'aria-label': 'Move question down', disabled: qi === qs.length - 1, onclick: () => moveQuestion(si, qi, 1) }, icon('arrowDown', { size: 15 })),
        h('button', { class: 'btn sm ghost', type: 'button', 'aria-label': 'Edit question', onclick: () => questionDialog(q, si, qi) }, icon('edit', { size: 15 })),
        h('button', { class: 'btn sm ghost', type: 'button', 'aria-label': 'Delete question', onclick: () => removeQuestion(si, qi) }, icon('trash', { size: 15 }))));
  }

  function moveQuestion(si, qi, d) {
    // Conditions may only depend on earlier questions, so refuse a move that would break that.
    const qs = state.sections[si].questions; const j = qi + d;
    if (j < 0 || j >= qs.length) return;
    const trial = state.sections.map((s) => s.questions.slice()); [trial[si][qi], trial[si][j]] = [trial[si][j], trial[si][qi]];
    const order = new Map(trial.flat().map((q, i) => [q.ref, i]));
    const bad = trial.flat().find((q) => (q.conditions || []).some((c) => (order.get(c.depends_on) ?? -1) >= order.get(q.ref)));
    if (bad) { toast('That move would put a question before one it depends on.', 'warn'); return; }
    move(qs, qi, d);
  }

  function removeQuestion(si, qi) {
    const q = state.sections[si].questions[qi];
    const dependants = allQuestions().filter((x) => (x.conditions || []).some((c) => c.depends_on === q.ref));
    if (dependants.length) { toast(`Other questions depend on this one (${dependants.map((d) => d.label).slice(0, 2).join(', ')}). Remove those conditions first.`, 'warn'); return; }
    state.sections[si].questions.splice(qi, 1); mark(); paint();
  }

  const banner = h('div');
  async function save() {
    clear(banner);
    try {
      const sections = state.sections.map((s) => ({ title: s.title, description: s.description || null, questions: s.questions.map(cleanQuestion) }));
      await api.put(`forms/${form.id}/structure`, { sections });
      state.dirty = false; saveBar.textContent = ''; toast('Form saved.');
      return true;
    } catch (e) {
      banner.append(errorBox(e));
      if (e.errors) banner.firstChild.append(h('ul', { class: 'small' }, Object.entries(e.errors).slice(0, 6).map(([k, v]) => h('li', null, `${describePath(k, state.sections)}: ${[].concat(v)[0]}`))));
      return false;
    }
  }

  async function publish() {
    if (state.dirty && !(await save())) return;
    if (!(await confirmDialog({ title: 'Publish this form?', message: 'Published forms cannot be edited. To change one later you create a new version; existing visits keep the version they used.', confirmLabel: 'Publish' }))) return;
    try { await api.post(`forms/${form.id}/publish`); toast('Form published.'); reload(); } catch (e) { toastError(e); }
  }

  const act = (label, cls, fn, ic) => h('button', { class: ['btn', cls], type: 'button', onclick: fn }, ic && icon(ic, { size: 16 }), label);
  const actions = [badge(form.status), h('span', { class: 'badge' }, `v${form.version}`)];
  if (editable) actions.push(saveBar, act('Save', '', save, 'check'), act('Publish', 'primary', publish, 'zap'));
  if (can('forms.manage') && form.status !== 'draft') {
    actions.push(act('New version', 'primary', async () => { try { const { data } = await api.post(`forms/${form.id}/new-version`); toast('Draft version created.'); navigate(`/forms/${data.id}`); } catch (e) { toastError(e); } }, 'copy'));
    if (form.status === 'published') actions.push(act('Archive', 'danger', async () => { if (await confirmDialog({ title: 'Archive form?', message: 'Archived forms can no longer be linked to campaigns. Existing visits are unaffected.', confirmLabel: 'Archive', danger: true })) { try { await api.post(`forms/${form.id}/archive`); toast('Form archived.'); reload(); } catch (e) { toastError(e); } } }));
  }
  if (can('forms.manage')) actions.push(act('Duplicate', '', async () => { try { const { data } = await api.post(`forms/${form.id}/duplicate`, {}); toast('Duplicated as a new draft.'); navigate(`/forms/${data.id}`); } catch (e) { toastError(e); } }, 'copy'));

  const meta = editable ? h('div', { class: 'card', style: { marginBottom: '16px' } }, h('div', { class: 'card-body' }, (() => {
    const f = buildForm([{ name: 'name', label: 'Form name', required: true }, { name: 'description', label: 'Description' }, { name: 'requires_live_capture', label: 'Require live capture for photo questions', type: 'checkbox', full: true }], form);
    let t; f.el.addEventListener('input', () => { clearTimeout(t); t = setTimeout(async () => { const v = f.read(); if (!v.name) return; try { await api.patch(`forms/${form.id}`, { name: v.name, description: v.description, requires_live_capture: !!v.requires_live_capture }); } catch (e) { toastError(e); } }, 700); });
    f.el.addEventListener('change', () => f.el.dispatchEvent(new Event('input')));
    return f.el;
  })())) : null;

  paint();
  return h('div', null,
    pageHead({ title: form.name, subtitle: form.description || (editable ? 'Draft — build the form, then publish it.' : form.status === 'published' ? 'Published — read only.' : 'Archived — read only.'), actions }),
    form.status !== 'draft' && h('div', { class: 'alert info', style: { marginBottom: '16px' } }, icon('info'), h('div', null, 'This version is locked so historical visits stay consistent. Use “New version” to make changes.')),
    banner, meta, body);
}

const cleanQuestion = (q) => ({
  ref: q.ref, type: q.type, label: q.label, help_text: q.help_text || null, required: !!q.required, evidence_required: !!q.evidence_required,
  validation: prune(q.validation), scoring: prune(q.scoring), settings: prune(q.settings),
  options: CHOICE.includes(q.type) ? (q.options || []).map((o) => ({ label: o.label, value: String(o.value), score: o.score ?? null })) : [],
  conditions: (q.conditions || []).map((c) => ({ depends_on: c.depends_on, operator: c.operator, ...(c.operator === 'answered' ? {} : { value: [].concat(c.value ?? '')[0] ?? '' }) })),
});
const prune = (o) => { if (!o) return null; const r = Object.fromEntries(Object.entries(o).filter(([, v]) => v !== null && v !== '' && v !== undefined)); return Object.keys(r).length ? r : null; };

function describePath(path, sections) {
  const m = /^sections\.(\d+)(?:\.questions\.(\d+))?/.exec(path);
  if (!m) return path;
  const s = sections[+m[1]]; const q = m[2] !== undefined ? s?.questions[+m[2]] : null;
  return q ? `“${q.label}”` : s ? `Section “${s.title}”` : path;
}

/** Modal editor for one question. Resolves with the edited question, or null when cancelled. */
function editQuestion(existing, priorQuestions) {
  return new Promise((resolve) => {
    const q = existing ? structuredClone(existing) : { ref: uid(), type: 'short_text', label: '', required: false, evidence_required: false, options: [], conditions: [], validation: {}, scoring: {}, settings: {} };
    const v = q.validation || {}; const sc = q.scoring || {}; const st = q.settings || {};
    const basic = buildForm([
      { name: 'type', label: 'Question type', type: 'select', options: typeOptions, blank: false, required: true },
      { name: 'label', label: 'Question', required: true, full: true, maxlength: 500 },
      { name: 'help_text', label: 'Help text', full: true },
      { name: 'required', label: 'Required', type: 'checkbox' }, { name: 'evidence_required', label: 'Evidence required', type: 'checkbox' },
    ], q);
    const typeEl = basic.control('type');

    const optionsHost = h('div'); const validationHost = h('div'); const conditionsHost = h('div');
    let options = (q.options || []).map((o) => ({ ...o }));
    let conditions = (q.conditions || []).map((c) => ({ ...c, value: [].concat(c.value ?? '')[0] ?? '' }));

    const paintOptions = () => {
      clear(optionsHost);
      if (!CHOICE.includes(typeEl.value)) return;
      optionsHost.append(h('h4', { style: { margin: '12px 0 6px' } }, 'Options'),
        ...options.map((o, i) => h('div', { class: 'row', style: { marginBottom: '6px' } },
          h('input', { class: 'input', placeholder: 'Label', 'aria-label': `Option ${i + 1} label`, value: o.label, oninput: (e) => { o.label = e.target.value; } }),
          h('input', { class: 'input', placeholder: 'Value (auto)', 'aria-label': `Option ${i + 1} value`, value: o.value || '', style: { maxWidth: '150px' }, oninput: (e) => { o.value = e.target.value; o._manual = true; } }),
          h('input', { class: 'input', type: 'number', step: 'any', placeholder: 'Score', 'aria-label': `Option ${i + 1} score`, value: o.score ?? '', style: { maxWidth: '90px' }, oninput: (e) => { o.score = e.target.value === '' ? null : Number(e.target.value); } }),
          h('button', { class: 'btn sm ghost', type: 'button', 'aria-label': 'Remove option', onclick: () => { options.splice(i, 1); paintOptions(); } }, icon('x', { size: 14 })))),
        h('button', { class: 'btn sm', type: 'button', onclick: () => { options.push({ label: '', value: '', score: null }); paintOptions(); } }, icon('plus', { size: 14 }), 'Add option'));
    };

    let vmin; let vmax; let vminLen; let vmaxLen; let vpat; let weight; let ratingMax; let minPhotos;
    const num = (label, init, props = {}) => { const el = h('input', { class: 'input', type: 'number', step: 'any', value: init ?? '', 'aria-label': label, ...props }); return [el, h('div', { class: 'field' }, h('label', null, label), el)]; };
    const paintValidation = () => {
      clear(validationHost);
      const t = typeEl.value; const cells = [];
      if (['integer', 'decimal', 'currency', 'percentage', 'rating'].includes(t)) { let w; [vmin, w] = num('Minimum', v.min); cells.push(w); [vmax, w] = num('Maximum', v.max); cells.push(w); }
      if (['short_text', 'long_text'].includes(t)) { let w; [vminLen, w] = num('Minimum length', v.min_length, { min: 0 }); cells.push(w); [vmaxLen, w] = num('Maximum length', v.max_length, { min: 1 }); cells.push(w);
        vpat = h('input', { class: 'input', value: v.pattern || '', maxlength: 200, 'aria-label': 'Pattern' }); cells.push(h('div', { class: 'field full' }, h('label', null, 'Pattern (regular expression)'), vpat, h('div', { class: 'help' }, 'Optional. Answers must match this expression.'))); }
      if (t === 'rating') { let w; [ratingMax, w] = num('Rating scale (max)', st.max ?? 5, { min: 2, max: 10 }); cells.push(w); }
      if (t === 'photo') { let w; [minPhotos, w] = num('Minimum photos', st.min_photos ?? 1, { min: 1, max: 10 }); cells.push(w); }
      let w; [weight, w] = num('Scoring weight', sc.weight, { min: 0 }); cells.push(w);
      validationHost.append(h('h4', { style: { margin: '12px 0 6px' } }, 'Validation & scoring'), h('div', { class: 'form-grid' }, cells));
    };

    const paintConditions = () => {
      clear(conditionsHost);
      const deps = priorQuestions.filter((p) => !NO_DEPENDENCY.includes(p.type));
      conditionsHost.append(h('h4', { style: { margin: '12px 0 6px' } }, 'Conditional logic'));
      if (!deps.length) { conditionsHost.append(h('p', { class: 'muted small' }, 'Add an earlier question first to show this one conditionally.')); return; }
      conditions.forEach((c, i) => {
        const target = deps.find((d) => d.ref === c.depends_on);
        const dep = h('select', { class: 'select', 'aria-label': 'Depends on', onchange: (e) => { c.depends_on = e.target.value; c.value = ''; paintConditions(); } }, h('option', { value: '' }, 'Choose question…'), deps.map((d) => h('option', { value: d.ref, selected: d.ref === c.depends_on }, d.label)));
        const op = h('select', { class: 'select', 'aria-label': 'Operator', onchange: (e) => { c.operator = e.target.value; paintConditions(); } }, Object.entries(CONDITION_OPERATORS).map(([k, l]) => h('option', { value: k, selected: k === c.operator }, l)));
        let val = null;
        if (c.operator !== 'answered') {
          val = target?.options?.length
            ? h('select', { class: 'select', 'aria-label': 'Value', onchange: (e) => { c.value = e.target.value; } }, h('option', { value: '' }, 'Choose…'), target.options.map((o) => h('option', { value: o.value, selected: String(o.value) === String(c.value) }, o.label)))
            : target?.type === 'yes_no' ? h('select', { class: 'select', 'aria-label': 'Value', onchange: (e) => { c.value = e.target.value; } }, ['', 'yes', 'no'].map((x) => h('option', { value: x, selected: x === c.value }, x || 'Choose…')))
              : h('input', { class: 'input', 'aria-label': 'Value', value: c.value ?? '', oninput: (e) => { c.value = e.target.value; } });
        }
        conditionsHost.append(h('div', { class: 'row', style: { marginBottom: '6px', flexWrap: 'wrap' } }, h('span', { class: 'small muted' }, i ? 'and' : 'Show when'), dep, op, val,
          h('button', { class: 'btn sm ghost', type: 'button', 'aria-label': 'Remove condition', onclick: () => { conditions.splice(i, 1); paintConditions(); } }, icon('x', { size: 14 }))));
      });
      if (conditions.length < 10) conditionsHost.append(h('button', { class: 'btn sm', type: 'button', onclick: () => { conditions.push({ depends_on: '', operator: 'equals', value: '' }); paintConditions(); } }, icon('plus', { size: 14 }), 'Add condition'));
    };

    typeEl.addEventListener('change', () => { if (CHOICE.includes(typeEl.value) && options.length < 2) options = [{ label: '', value: '', score: null }, { label: '', value: '', score: null }]; paintOptions(); paintValidation(); });
    if (CHOICE.includes(typeEl.value) && !options.length) options = [{ label: '', value: '', score: null }, { label: '', value: '', score: null }];
    paintOptions(); paintValidation(); paintConditions();

    const err = h('div', { class: 'hidden', role: 'alert' });
    const body = h('div', null, err, basic.el, optionsHost, validationHost, conditionsHost);
    let done = false;
    const finish = (val, close) => { done = true; resolve(val); close?.(); };
    const m = openModal({ title: existing ? 'Edit question' : 'Add question', body, size: 'lg', onClose: () => { if (!done) resolve(null); },
      footer: (close) => [h('button', { class: 'btn', type: 'button', onclick: () => close() }, 'Cancel'), h('button', { class: 'btn primary', type: 'button', onclick: () => {
        basic.clearErrors(); err.className = 'hidden';
        const b = basic.read();
        const fail = (msg) => { err.className = 'alert error'; err.replaceChildren(icon('alert'), h('div', null, msg)); };
        if (!b.label) return basic.showErrors({ errors: { label: ['Enter the question text.'] } });
        let opts = [];
        if (CHOICE.includes(b.type)) {
          opts = options.filter((o) => o.label.trim()).map((o) => ({ label: o.label.trim(), value: (o.value || '').trim() || slug(o.label), score: o.score ?? null }));
          if (opts.length < 2) return fail('Choice questions need at least two options with labels.');
          if (new Set(opts.map((o) => o.value)).size !== opts.length) return fail('Option values must be unique.');
        }
        const conds = conditions.filter((c) => c.depends_on);
        if (conds.some((c) => c.operator !== 'answered' && String(c.value ?? '') === '')) return fail('Every condition needs a value to compare with (or use “is answered”).');
        const nv = (el) => (el && el.value !== '' ? Number(el.value) : null);
        const validation = prune({ min: nv(vmin), max: nv(vmax), min_length: nv(vminLen), max_length: nv(vmaxLen), pattern: vpat?.value.trim() || null });
        if (validation?.min != null && validation?.max != null && validation.min > validation.max) return fail('Minimum cannot exceed maximum.');
        const settings = prune({ max: ratingMax ? nv(ratingMax) : null, min_photos: minPhotos ? nv(minPhotos) : null });
        finish({ ...q, type: b.type, label: b.label, help_text: b.help_text, required: !!b.required, evidence_required: !!b.evidence_required, options: opts, conditions: conds, validation: validation || {}, scoring: prune({ weight: nv(weight) }) || {}, settings: settings || {} }, m.close);
      } }, 'Apply')] });
  });
}
