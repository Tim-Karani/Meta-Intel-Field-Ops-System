# Web app

Vanilla ES modules under `web/assets/js`, no bundler. Served as static files; talks to `/api/v1`.

- `core/`: `dom.js` (`h()`/`svg()`), `api.js` (envelope, errors, request ids, cancellation), `auth.js` (session token, `can()`), `router.js` (hash router with permission guards), `lookups.js`, `format.js`, `icons.js`.
- `ui/`: `table.js` (server-driven sort/filter/search/pagination), `form.js`, `crud.js` (`crudPage`, `formModal`), `feedback.js` (toast, modal, confirm, tabs), `charts.js` and `map.js` (custom SVG, no libraries), `gallery.js` (evidence review).
- `pages/`: one lazy module per route (`export default async function render(ctx)`), 32 pages: dashboard, clients, campaigns, activities, teams, territories, locations, products, forms and builder, assignments, attendance, visits, issues, evidence, reports (13 types), analytics, users, roles, audit, system, settings, client portal, profile, notifications.
- The dashboard focuses on one client (and optionally one campaign) at a time via `client_id`/`campaign_id`; the choice is remembered per browser session and "All clients" is an explicit option.
- Navigation and actions are filtered by the user's permissions; the server remains the authority.
- Deliberately absent: a visit-capture UI (mobile), campaign actuals on the campaign page (use Reports).
- Add a page: create `pages/x.js`, register it in `routes.js` with its permission, add a nav entry.
