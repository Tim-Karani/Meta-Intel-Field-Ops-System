/* Navigation model. An item is shown only if the user holds ANY listed permission — but real
   enforcement is always server-side; this just keeps the UI honest. */
export const NAV = [
  { group: null, items: [
    { label: 'Dashboard', icon: 'home', route: '/dashboard', perm: ['dashboard.view'], hideFor: 'client' },
    { label: 'My work', icon: 'clipboard', route: '/my-work', perm: ['assignments.respond'] },
    { label: 'Client portal', icon: 'chart', route: '/portal', perm: ['portal.view'] },
  ] },
  { group: 'Operations', items: [
    { label: 'Assignments', icon: 'calendar', route: '/assignments', perm: ['assignments.view'] },
    { label: 'Attendance', icon: 'clock', route: '/attendance', perm: ['attendance.view'] },
    { label: 'Visits', icon: 'pin', route: '/visits', perm: ['visits.view'], badge: 'review' },
    { label: 'Issues', icon: 'alert', route: '/issues', perm: ['issues.view'] },
    { label: 'Evidence', icon: 'image', route: '/evidence', perm: ['evidence.view'] },
  ] },
  { group: 'Data capture', items: [
    { label: 'Sales', icon: 'dollar', route: '/sales', perm: ['sales.view'] },
    { label: 'OSA', icon: 'checkCircle', route: '/osa', perm: ['osa.view'] },
    { label: 'Merchandising', icon: 'layers', route: '/merchandising', perm: ['merchandising.view'] },
  ] },
  { group: 'Planning', items: [
    { label: 'Clients', icon: 'building', route: '/clients', perm: ['clients.view'] },
    { label: 'Campaigns', icon: 'flag', route: '/campaigns', perm: ['campaigns.view'] },
    { label: 'Activities', icon: 'zap', route: '/activities', perm: ['activities.view'] },
    { label: 'Forms', icon: 'file', route: '/forms', perm: ['forms.view'] },
    { label: 'Teams', icon: 'users', route: '/teams', perm: ['teams.view'] },
    { label: 'Territories & routes', icon: 'route', route: '/territories', perm: ['territories.view'] },
    { label: 'Locations', icon: 'map', route: '/locations', perm: ['locations.view'] },
    { label: 'Products', icon: 'box', route: '/products', perm: ['products.view'] },
  ] },
  { group: 'Insights', items: [
    { label: 'Reports', icon: 'list', route: '/reports', perm: ['reports.view'] },
    { label: 'Analytics', icon: 'bars', route: '/analytics', perm: ['reports.view'] },
  ] },
  { group: 'Administration', items: [
    { label: 'Users', icon: 'user', route: '/users', perm: ['users.view'] },
    { label: 'Roles & permissions', icon: 'shield', route: '/roles', perm: ['roles.view', 'users.view'] },
    { label: 'Audit logs', icon: 'activity', route: '/audit', perm: ['audit.view'] },
    { label: 'System health', icon: 'heart', route: '/system', perm: ['system.manage'] },
    { label: 'Settings', icon: 'settings', route: '/settings', perm: ['system.manage'] },
  ] },
];
