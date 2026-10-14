# Decisions adopted

Where the specification was silent or the template disagreed, these defaults were applied (originally A1–A14 in the architecture blueprint). All are easy to change; the right-hand column says where.

| ID | Decision | Where to change |
|---|---|---|
| A1 | Web app is **vanilla HTML/CSS/JS** (ES modules, no build), per the spec; the React template is archived in `legacy/` | `web/` |
| A2 | **Sanctum bearer tokens** for web and mobile. Web keeps the token in `sessionStorage` | `config/sanctum.php`, `core/auth.js` |
| A3 | Fresh database; no Supabase data migration | – |
| A4 | Campaign states Draft → Planned → Active → Paused → Completed → Archived; no `cancelled` (archive with reason) | `App\Enums\CampaignStatus` |
| A5 | Assignment `overdue` is stored, set by the daily `assignments:mark-overdue` job | `routes/console.php` |
| A6 | "Management" merged into Operations Manager | `RolePermissionSeeder` |
| A7 | Supervisors and campaign managers may approve/reject visits within scope | `RolePermissionSeeder` |
| A8 | Client portal: approved visits and approved photos only, aggregated, agent identities hidden | `PortalController` |
| A9 | GPS: default radius 100 m, accuracy over 100 m is a warning; configurable globally and per campaign | `config/field.php`, `gps_rules` |
| A10 | Live-capture photo requirement is per question/campaign | form builder |
| A11 | Exports are **CSV only** (formula-injection safe, audited). XLSX/PDF deferred | `ReportController` |
| A12 | English, KES, M-Pesa kept | `web/config.js` |
| A13 | Docker on a VPS | `docker-compose.yml` |
| A14 | Notifications are in-app only (email/push deferred) | `notifications` table |
| – | Database queue and database cache so every container shares state without Redis | `.env.example` |
| – | The web app has **no visit-capture screen**: field work happens in the mobile app | `pages/mywork.js` |
