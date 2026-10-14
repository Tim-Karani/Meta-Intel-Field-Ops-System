import { h } from '../core/dom.js';
import { icon } from '../core/icons.js';
import { api } from '../core/api.js';
import { asyncView, card, pageHead, tabs, toast, errorBox } from '../ui/feedback.js';
import { buildForm, selectControl } from '../ui/form.js';
import { lookup } from '../core/lookups.js';


const GPS_FIELDS = [
  { name: 'default_radius_m', label: 'Default geofence radius (m)', type: 'number', min: 10, max: 5000, help: 'Used when a location has no radius of its own.' },
  { name: 'accuracy_poor_m', label: 'Poor accuracy above (m)', type: 'number', min: 10, max: 1000 },
  { name: 'accuracy_unusable_m', label: 'Unusable accuracy above (m)', type: 'number', min: 50, max: 5000 },
  { name: 'accuracy_credit_cap_m', label: 'Accuracy credit cap (m)', type: 'number', min: 0, max: 500, help: 'Maximum reported accuracy that may extend the geofence.' },
  { name: 'near_multiplier', label: '“Near” zone multiplier', type: 'decimal', min: 1, max: 10, help: 'Outside the radius but within radius × this value is a minor flag.' },
  { name: 'far_distance_m', label: '“Far” distance (m)', type: 'number', min: 100, max: 50000 },
  { name: 'far_action', label: 'Action when far', type: 'select', options: [{ value: 'reject', label: 'Reject the check-in' }, { value: 'flag', label: 'Accept but flag as suspicious' }], blank: false },
  { name: 'skew_warn_s', label: 'Clock skew warning (s)', type: 'number', min: 30, max: 3600 },
  { name: 'skew_suspicious_s', label: 'Clock skew suspicious (s)', type: 'number', min: 60, max: 86400 },
  { name: 'max_speed_kmh', label: 'Max plausible speed (km/h)', type: 'number', min: 20, max: 1000, help: 'Faster travel between check-ins is flagged as impossible.' },
  { name: 'suspicious_threshold', label: 'Suspicious score threshold', type: 'number', min: 1, max: 10, help: 'Total flag weight at which a visit is marked suspicious.' },
];

export default async function render({ container }) {
  container.append(pageHead({ title: 'Settings', subtitle: 'Agency profile and GPS validation rules.' }));
  container.append(tabs([{ key: 'agency', label: 'Agency', render: agencyTab }, { key: 'gps', label: 'GPS validation', render: gpsTab }]));
}

function agencyTab() {
  const host = h('div');
  asyncView(host, () => api.get('system/settings').then((r) => r.data), (s) => {
    const f = buildForm([{ name: 'agency_name', label: 'Agency name', required: true }, { name: 'contact_email', label: 'Contact email', type: 'email' }, { name: 'currency', label: 'Currency (ISO code)', maxlength: 3, help: 'Display currency for reports.' }], s);
    const banner = h('div');
    const save = h('button', { class: 'btn primary', type: 'button', onclick: async () => {
      f.clearErrors(); banner.replaceChildren(); save.disabled = true;
      try { const v = f.read(); await api.put('system/settings', { agency_name: v.agency_name, contact_email: v.contact_email, currency: (v.currency || '').toUpperCase() }); toast('Settings saved.'); }
      catch (e) { if (!f.showErrors(e)) banner.append(errorBox(e)); } finally { save.disabled = false; }
    } }, 'Save settings');
    return card('Agency', h('div', { class: 'stack' }, banner, f.el, h('div', null, save)));
  });
  return host;
}

function gpsTab() {
  const host = h('div', { class: 'stack' });
  let campaignId = '';
  const body = h('div');
  lookup('campaigns').catch(() => []).then((opts) => host.prepend(h('div', { class: 'card' }, h('div', { class: 'toolbar' }, selectControl({ label: 'Rules scope', options: opts, blank: 'Agency-wide defaults', onChange: (v) => { campaignId = v; load(); } }), h('span', { class: 'small muted' }, 'Campaign rules override the agency-wide rules for that campaign only.')))));
  host.append(body);
  function load() {
    asyncView(body, () => api.get('system/gps-rules', campaignId ? { campaign_id: campaignId } : {}).then((r) => r.data), (d) => {
      const effective = { ...d.defaults, ...(d.agency_override || {}), ...(campaignId ? d.campaign_override || {} : {}) };
      const own = campaignId ? d.campaign_override || {} : d.agency_override || {};
      const f = buildForm(GPS_FIELDS, effective);
      const banner = h('div');
      const save = h('button', { class: 'btn primary', type: 'button', onclick: async () => {
        f.clearErrors(); banner.replaceChildren(); save.disabled = true;
        try { const v = f.read(); Object.keys(v).forEach((k) => { if (v[k] === null || v[k] === '') delete v[k]; }); await api.put('system/gps-rules', { ...v, ...(campaignId ? { campaign_id: campaignId } : {}) }); toast('GPS rules saved.'); }
        catch (e) { if (!f.showErrors(e)) banner.append(errorBox(e)); } finally { save.disabled = false; }
      } }, icon('check', { size: 16 }), 'Save rules');
      return card(campaignId ? 'Campaign GPS rules' : 'Agency GPS rules', h('div', { class: 'stack' },
        h('p', { class: 'muted' }, Object.keys(own).length ? 'Showing saved values. Fields you do not change keep these values.' : 'Showing built-in defaults. Saving stores these values as overrides.'),
        banner, f.el, h('div', null, save)));
    });
  }
  load();
  return host;
}
