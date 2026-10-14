/* Deployment configuration. Edit per environment; contains NO secrets. */
window.APP_CONFIG = {
  // Base URL of the Laravel API. A relative value works when nginx proxies /api to the backend.
  apiBase: '/api/v1',
  appName: 'Field Operations',
  // Slippy-map tiles for location maps. Set to '' to draw markers without a basemap.
  mapTileUrl: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
  mapAttribution: '© OpenStreetMap contributors',
  currency: 'KES',
  locale: 'en-KE',
  defaultMapCenter: [-1.2864, 36.8172],
};
