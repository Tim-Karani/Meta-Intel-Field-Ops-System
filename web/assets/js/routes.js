/* Route table. `load` is a lazy dynamic import so each page is only fetched when visited. */
const page = (file) => () => import(`./pages/${file}.js`);

export const ROUTES = [
  { path: '/login', public: true, title: 'Sign in', load: page('login'), layout: 'bare' },
  { path: '/forgot-password', public: true, title: 'Forgot password', load: page('login'), layout: 'bare', mode: 'forgot' },
  { path: '/reset-password', public: true, title: 'Reset password', load: page('login'), layout: 'bare', mode: 'reset' },

  { path: '/dashboard', title: 'Dashboard', perm: ['dashboard.view'], load: page('dashboard') },
  { path: '/my-work', title: 'My work', perm: ['assignments.respond'], load: page('mywork') },
  { path: '/profile', title: 'My profile', load: page('profile') },
  { path: '/notifications', title: 'Notifications', perm: ['notifications.view'], load: page('notifications') },

  { path: '/clients', title: 'Clients', perm: ['clients.view'], load: page('clients') },
  { path: '/clients/:id', title: 'Client', perm: ['clients.view'], load: page('client-detail'), crumbs: [['Clients', '/clients']] },
  { path: '/campaigns', title: 'Campaigns', perm: ['campaigns.view'], load: page('campaigns') },
  { path: '/campaigns/:id', title: 'Campaign', perm: ['campaigns.view'], load: page('campaign-detail'), crumbs: [['Campaigns', '/campaigns']] },
  { path: '/activities', title: 'Activities', perm: ['activities.view'], load: page('activities') },
  { path: '/teams', title: 'Teams', perm: ['teams.view'], load: page('teams') },
  { path: '/territories', title: 'Territories & routes', perm: ['territories.view'], load: page('territories') },
  { path: '/locations', title: 'Locations', perm: ['locations.view'], load: page('locations') },
  { path: '/locations/:id', title: 'Location', perm: ['locations.view'], load: page('location-detail'), crumbs: [['Locations', '/locations']] },
  { path: '/products', title: 'Products', perm: ['products.view'], load: page('products') },
  { path: '/forms', title: 'Forms', perm: ['forms.view'], load: page('forms') },
  { path: '/forms/:id', title: 'Form builder', perm: ['forms.view'], load: page('form-builder'), crumbs: [['Forms', '/forms']] },

  { path: '/assignments', title: 'Assignments', perm: ['assignments.view'], load: page('assignments') },
  { path: '/attendance', title: 'Attendance', perm: ['attendance.view'], load: page('attendance') },
  { path: '/visits', title: 'Visits', perm: ['visits.view'], load: page('visits') },
  { path: '/visits/:id', title: 'Visit', perm: ['visits.view'], load: page('visit-detail'), crumbs: [['Visits', '/visits']] },
  { path: '/sales', title: 'Sales', perm: ['sales.view'], load: page('capture'), kind: 'sales' },
  { path: '/osa', title: 'On-shelf availability', perm: ['osa.view'], load: page('capture'), kind: 'osa' },
  { path: '/merchandising', title: 'Merchandising', perm: ['merchandising.view'], load: page('capture'), kind: 'merchandising' },
  { path: '/issues', title: 'Issues', perm: ['issues.view'], load: page('issues') },
  { path: '/evidence', title: 'Evidence', perm: ['evidence.view'], load: page('evidence') },

  { path: '/reports', title: 'Reports', perm: ['reports.view'], load: page('reports') },
  { path: '/reports/:type', title: 'Report', perm: ['reports.view'], load: page('reports'), crumbs: [['Reports', '/reports']] },
  { path: '/analytics', title: 'Analytics', perm: ['reports.view'], load: page('analytics') },

  { path: '/users', title: 'Users', perm: ['users.view'], load: page('users') },
  { path: '/roles', title: 'Roles & permissions', perm: ['roles.view', 'users.view'], load: page('roles') },
  { path: '/audit', title: 'Audit logs', perm: ['audit.view'], load: page('audit') },
  { path: '/system', title: 'System health', perm: ['system.manage'], load: page('system') },
  { path: '/settings', title: 'Settings', perm: ['system.manage'], load: page('settings') },

  { path: '/portal', title: 'Client portal', perm: ['portal.view'], load: page('portal') },
  { path: '/portal/visits/:id', title: 'Visit', perm: ['portal.view'], load: page('portal'), mode: 'visit', crumbs: [['Client portal', '/portal']] },
  { path: '/portal/reports/:type', title: 'Report', perm: ['portal.view'], load: page('portal'), mode: 'report', crumbs: [['Client portal', '/portal']] },
];
