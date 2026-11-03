/* Navigation model. An item is shown only if the user holds ANY listed permission — but real
   enforcement is always server-side; this just keeps the UI honest.
   The menu is deliberately short. Related pages are grouped into "hubs" (see HUBS): one menu item opens a page
   that lists everything of that kind with a plain-English line about what each page is for. */

const T = (label, icon, route, perm, desc, extra = {}) => ({ label, icon, route, perm, desc, ...extra });

export const HUBS = {
  field: { title: 'Field team', icon: 'users', intro: 'Plan who goes where, check who is working, and follow agents in the field.', sections: [
    { title: 'Plan the work', items: [
      T('Journey plans', 'route', '/journey-plans', ['assignments.create'], 'Fixed outlets by weekday for each agent, with activities per day.'),
      T('Assignments', 'calendar', '/assignments', ['assignments.view'], 'One-off jobs given to an agent on a date.'),
      T('Plan requests', 'route', '/jp-requests', ['journey.review'], 'Changes agents asked for on their journey plans, waiting for you.'),
    ] },
    { title: 'Check attendance', items: [
      T('Team attendance', 'users', '/attendance-board', ['assignments.create'], 'Who has checked in today and who has not.'),
      T('Attendance records', 'clock', '/attendance', ['attendance.view'], 'Check-in and check-out history.'),
    ] },
    { title: 'Follow agents', items: [
      T('Field agents', 'pin', '/field-agents', ['agents.track'], 'Where agents are right now and what they did today.'),
    ] },
  ] },
  results: { title: 'Results', icon: 'chart', intro: 'Everything the field team has captured, and the reports built from it.', sections: [
    { title: 'Check the work', items: [
      T('Visits', 'pin', '/visits', ['visits.view'], 'Every visit with its answers, photos and GPS. Open the ones that need review.', { badge: 'review' }),
      T('Issues', 'alert', '/issues', ['issues.view'], 'Problems agents reported from outlets, and who is handling them.'),
      T('Evidence', 'image', '/evidence', ['evidence.view'], 'All photos taken in the field.'),
    ] },
    { title: 'Captured data', items: [
      T('Sales', 'dollar', '/sales', ['sales.view'], 'Units and value sold, per outlet and product.', { feature: 'sales' }),
      T('Stock', 'box', '/stock', ['stock.view'], 'Stock counts per outlet and product.', { feature: 'sales' }),
      T('On-shelf availability', 'checkCircle', '/osa', ['osa.view'], 'Which products were on the shelf and which were missing.'),
      T('Merchandising', 'layers', '/merchandising', ['merchandising.view'], 'Shelf and display checks.'),
    ] },
    { title: 'Reports', items: [
      T('Reports', 'list', '/reports', ['reports.view'], 'Ready-made reports you can filter and export.'),
      T('Scheduled reports', 'mail', '/report-schedules', ['reports.schedule'], 'Reports emailed automatically on a schedule.'),
      T('Analytics', 'bars', '/analytics', ['reports.view'], 'Charts and trends.'),
      T('Payroll', 'dollar', '/payroll', ['payroll.view'], 'Agent pay worked out from attendance and visits.'),
    ] },
  ] },
  setup: { title: 'Setup', icon: 'settings', intro: 'The lists the rest of the system uses. Most of these you set once, then rarely touch.', sections: [
    { title: 'Who you work for', items: [
      T('Clients', 'building', '/clients', ['clients.view'], 'The companies you run projects for.'),
      T('Products', 'box', '/products', ['products.view'], 'Products agents report on (sales, shelf checks).'),
    ] },
    { title: 'How projects run', items: [
      T('Project templates', 'layers', '/project-templates', ['campaigns.view'], 'Ready-made project types that pre-fill the rules for a new project.'),
      T('Activities', 'zap', '/activities', ['activities.view'], 'The kinds of work inside a project (merchandising, activation...).'),
      T('Forms', 'file', '/forms', ['forms.view'], 'The question forms agents fill at an outlet.'),
    ] },
    { title: 'People and places', items: [
      T('Teams', 'users', '/teams', ['teams.view'], 'Groups of agents and their leader.'),
      T('Territories & routes', 'route', '/territories', ['territories.view'], 'Areas and routes that outlets and agents belong to.'),
      T('Outlet setup', 'map', '/outlet-setup', ['locations.manage'], 'Chains, outlet types, statuses, distributors and geography.'),
    ] },
  ] },
  admin: { title: 'Admin', icon: 'shield', intro: 'Users, permissions and system care.', sections: [
    { title: 'People and access', items: [
      T('Users', 'user', '/users', ['users.view'], 'Create users, reset passwords, and switch "Can add outlets" per agent.'),
      T('Roles & permissions', 'shield', '/roles', ['roles.view', 'users.view'], 'What each role can see and do.'),
    ] },
    { title: 'System', items: [
      T('Audit logs', 'activity', '/audit', ['audit.view'], 'Who changed what, and when.'),
      T('System health', 'heart', '/system', ['system.manage'], 'Is everything running.'),
      T('Settings', 'settings', '/settings', ['system.manage'], 'System-wide options.'),
    ] },
  ] },
};

const hubPerms = (k) => HUBS[k].sections.flatMap((s) => s.items.flatMap((i) => i.perm));
const hubItem = (k) => ({ label: HUBS[k].title, icon: HUBS[k].icon, route: `/hub/${k}`, perm: hubPerms(k), hub: k, badge: k === 'results' ? 'review' : undefined,
  also: HUBS[k].sections.flatMap((s) => s.items.map((i) => i.route)) });

export const NAV = [
  { group: null, items: [
    { label: 'Home', icon: 'home', route: '/dashboard', perm: ['dashboard.view'], hideFor: 'client' },
    { label: 'My work', icon: 'clipboard', route: '/my-work', perm: ['assignments.respond'] },
    { label: 'Client portal', icon: 'chart', route: '/portal', perm: ['portal.view'] },
    { label: 'Executive summary', icon: 'pie', route: '/executive', perm: ['reports.view'], hideFor: 'client' },
  ] },
  { group: 'Projects', items: [
    { label: 'Start a project', icon: 'zap', route: '/onboarding', perm: ['campaigns.create'] },
    { label: 'My projects', icon: 'flag', route: '/campaigns', perm: ['campaigns.view'] },
    { label: 'Outlets', icon: 'map', route: '/locations', perm: ['locations.view'] },
  ] },
  { group: 'Run it', items: [hubItem('field'), hubItem('results')] },
  { group: 'Manage', items: [hubItem('setup'), hubItem('admin')] },
];
