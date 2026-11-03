# Changelog

## 1.9.0 - Simpler menu and a project workspace (web only, no migration)
- **Short menu.** About nine items: Home, Executive summary, Start a project, My projects, Outlets, Field team, Results, Setup, Admin. Field team, Results, Setup and Admin each open a page that groups the related screens, with one plain line on what each is for. Every old page still exists and is reached from its group; the menu highlights the right group while you are on it.
- **Project workspace.** A project page now has five tabs: Overview, Setup (activities and KPIs), Outlets & team, Journey plans (plans and one-off assignments for that project), Visits & results (visits, issues, photos, reports).
- **Next steps checklist** on the project Overview: add activities, outlets, people, plan each agent's week, activate; each step has a button to the place to do it.
- Cache-bust bumped to 20. Needs only the web files (no rebuild, no migration).

## 1.8.0 - Per-agent outlet switch, scalable journey plans, campaign targets
**Requires migration `2026_01_01_000014_v18_agent_outlet_switch` (run `migrate --force`).**
- **Add-outlet is now a per-agent switch** (Users > agent > "Can add outlets", default off). Old campaign-level settings are carried over to the agents once by the migration, then removed.
- **Review is always by exception.** The "Every visit" review option is gone; team leaders check sensitive outlets and outlets with issues.
- **Journey plan board:** pick outlets with filters, search and chain, add hundreds at once, pick weekdays, then paint each day with an activity. Shows outlets per day, outlets also covered by another agent, and saves as one plan (up to 3000 stops).
- **Outlets can be edited in full** (chain, type, distributor, status, contacts, hours, notes); the onboarding screen uses the same full outlet form with inline chain/distributor/sub-type creation.
- **Campaign form in tabs;** "Type" renamed "Campaign category"; default outlet type comes from Outlet setup.
- **Campaign dates are enforced:** days-left on the list, assignments outside the dates are refused, journey plans start no earlier than the campaign.
- **Targets per campaign** (OSA, SOS, visits per outlet, coverage) compared in Reports and the Executive summary.
- Import accepts opening_hours, notes and territory columns. Budget is informational only (payroll is not per project).

## 1.7.3 - Executive summary fix; must-sell list removed
- **Fix:** the Executive summary (and payroll) failed with "invalid input syntax for type uuid: 0" when there were no field agents yet, for example on a fresh database. Empty lists are now handled properly.
- **Removed the must-sell list:** the "Must-sell list only" filter and card on the Executive summary, the checkbox and column on Products, and the column in the by-product shelf report. The old flag stays in the database, unused.

## 1.7.2 - Safe test runs; batch change fix
- **Tests can no longer touch a real database.** `phpunit.xml` now forces the test database (`field_ops_test`), array cache and sync queue even when the container sets its own values, and the suite refuses to start if the database name does not end in `_test`. See `docs/TESTING.md` for the safe command.
- Fix: `POST /locations/batch` returned 500 instead of 422 when `changes` held only unknown fields.
- Test fixes: stale expectations in the templates, shelf-report scope and SOS tests.

## 1.7.1 - Fix outlet migration
- **Fix:** migration `000013` failed on PostgreSQL ("no unique constraint matching given keys for referenced table geo_areas") because the places table pointed at itself before its primary key existed. The self-reference is now added after the table is created. Nothing from the failed attempt is left behind (the migration rolls back), so just run `migrate --force` again.
- Browser cache-bust bumped to 17 so the new Outlets pages load instead of the old ones.

## 1.7.0 - Outlets rebuilt: setup lists, one-panel form, bulk tools, overview
**Requires migration `2026_01_01_000013_v17_outlet_setup` (run `migrate --force`).**
- **Outlet setup** (Outlets > Outlet setup): manage Chains, Types & sub-types, Statuses, Distributors and Geography (country > region > locality > area) in one place. Types and statuses are no longer fixed lists; existing outlets keep their type and status (backfilled).
- **Statuses** have a colour and a "counts as active" flag; exactly one is the default. Outlets whose status does not count as active drop out of planning, same as before.
- **New outlet panel**: map and shop search on top, then About, Where (region > locality > area cascade), Contact and trade (distributor, status, phones, email, check-in radius, hours). Same panel edits an outlet. "Add another after saving" keeps chain/type/distributor.
- **Bulk tools**: tick outlets in the list and change chain, type, status, distributor, territory or place for all at once (`POST /locations/batch`); **Quick create** pastes many names that share chain, type, distributor and place.
- **Overview tab**: outlets grouped by chain, type, status, region or distributor with mapped / needs-review / active counts (`GET /locations/summary`); click a group to open the filtered list.
- **New filters**: sub-type, status, distributor, setting, place (includes everything beneath it).
- **CSV import** also takes country, region, locality, area (created once if missing), sub-type, distributor, status, phone, email, shop no., market name, setting.
- New outlet fields: sub-type, status, distributor, place, phone, email, shop no., market name, setting (urban / peri-urban / rural).
- Not added on purpose: "bypass geo-fencing" (conflicts with the anti-fake-location rule).
- Tests: `OutletSetupTest`.

## 1.6.2 - Search a shop by name in the outlet map picker
- The "Set position" map now has a search box: type the shop (and area), press Search, pick a result and the pin jumps there; the outlet name/address are filled in if empty. Clicking the map or pasting coordinates still works. Search runs through the server (`GET /places/search`, OpenStreetMap data, Kenya by default via `PLACE_SEARCH_COUNTRIES`), is rate-limited and cached. Small shops are only partly covered in OpenStreetMap, so check the pin before using it.

## 1.6.1 - Client / project focus on every working list
- The client / project picker in the top bar now also narrows **Reports, Analytics, Scheduled reports, Clients, Campaigns, Activities, Forms, Teams, Territories & routes, Locations and Products** (it already covered Assignments, Journey plans, Attendance, Team attendance, Field agents, JP requests, Visits, Issues, Evidence, Sales, Stock, OSA and Merchandising). Nothing is shown cumulatively once a client or project is chosen; "All clients / All projects" brings back the full list. Report exports follow the same choice. Everyone who works across projects (any role with the picker) gets it; it only narrows what they could already see.
- Not narrowed on purpose: Project templates (not tied to a client) and Payroll (a pay run covers each person's whole period across projects; splitting it would misstate pay).
- Tests: `FocusListsTest`.

## 1.6.1 - Client / project focus on working pages; fix for "New client & project"
- **Fix:** the *New client & project* page failed with "missing ) after argument list" (an unescaped apostrophe in the outlet-import help text, introduced in 1.5.0).
- **Client and project focus.** A Client and Project picker in the top bar now narrows Assignments, Journey plans, Attendance, Team attendance, Field agents, JP requests, Visits, Issues, Evidence, Sales, Stock, OSA and Merchandising to one client and/or one project, instead of everything added together. It appears only on those pages and only for people who work across projects (not field agents or client users). The choice is remembered for the tab and cleared at sign-out. The server applies it on top of each user's normal access scope, so it can only narrow, never widen, what someone sees. Dashboard, Executive summary and Reports already had their own client/project filters and are unchanged.

## 1.6.0 - Chains, reporting hierarchies, OSA/SOS reports, outlets not visited
- **Chains (channels).** Outlets can belong to a chain such as a supermarket group, with an optional wider channel group (e.g. modern trade). Manage them on **Locations > Chains**; filter outlets by chain; click a chain to see its outlets. New table `channels`, `locations.channel_id`, endpoints `/channels` (permissions `locations.view` / `locations.manage`, nothing new to grant).
- **Reporting structure on outlets and products.** Outlets get region, locality and distributor; categories can have sub-categories (one level; reports roll them up); products get pack size and units per case. All optional, so existing data keeps working and is reported as "Unassigned" / "Uncategorised" until filled in.
- **CSV outlet import** accepts `channel`, `channel_group`, `region`, `locality`, `distributor` columns; chains named in the file are matched case-insensitively or created once.
- **Nine new reports.** OSA by channel / category / outlet / product; share of shelf by channel / category / outlet / product (with expected share and gap); and **Outlets not visited** (outlets in the project that had no valid visit in the period, last visit date, never-visited vs planned-but-missed, coverage %). Filters: chain, category, brand, product, plus the usual date/client/project/territory. Only submitted, under-review and approved visits count. CSV export works for all of them. See `docs/REPORTS.md` for definitions.
- Tests: `ShelfReportsTest`; the report-count test now follows the report list.

## 1.5.2 - Fixes found by the first full test run: a conditional question set to "equals Yes/No" never showed (so its required answer was never enforced); an empty list now unlinks all locations from a campaign; evidence files keep their stricter sandbox policy; Operations Managers can delete empty clients again.

## 1.5.1 - Fix: check-in failed with a server error (a missing variable in the check-in transaction; present since 1.3.x). Found by the first test run.

## 1.5.0 - Least privilege, review by exception, office-set positions, optional sales
- **Roles trimmed to the minimum.** Every role now holds only the permissions its job needs (matrix in `docs/RBAC.md`). A Team Leader no longer holds clients, products, forms, territories, teams, company reports, sales/OSA/merchandising data or payroll; Executives only read results; Field Agents lose master-data views; the System Administrator no longer has `payroll.manage`. Migration `000011` removes the extra grants from existing databases.
- **Review by exception.** A visit at an outlet whose position the office set or confirmed, with a clean GPS reading, is **approved automatically** (event `auto_approved`; the client can see it straight away). Only visits that carry a doubt wait for a person: the first visit to a new/unconfirmed outlet, a flagged GPS reading, or every visit if the project is set to review everything (`review` setting). Each waiting visit says why. Approving the first visit at a new outlet also confirms the outlet position (one decision, not two), and approving a visit accepts its photos (no photo-by-photo approval).
- **Outlet positions set by the office.** Outlets get their coordinates when the project is created: the wizard's Outlets step accepts a CSV with latitude/longitude or one outlet at a time with a map picker (click the map, or paste coordinates / a Google Maps link). The same picker is in the outlet form. A new project setting `capture_geo` (`office_only` default, or `first_visit`) decides whether an agent's check-in may create a position; with `office_only`, an unpositioned outlet cannot be checked in until the office sets it.
- **Sales are optional per project.** The Sales task is off by default and on only for sales projects (new *Sales rep / Foot soldier* project type). Where no project sells, the Sales menu, sales dashboard figures, sales reports and visit tab are hidden, and the server refuses sales on a project that does not record them. Existing projects keep their previous behaviour (migration backfills their settings).
- **Stock accountability** for sales projects (`/stock`): issue stock to an agent, record returns and corrections, and see per agent and product what was issued, sold, returned and what they should still hold. New permissions `stock.view`, `stock.manage`.
- **Calmer dashboard.** Four headline numbers, today's progress, what needs attention, one chart and one project table; everything else is folded under "More detail". More spacing throughout.
- Session payload now carries `features` (currently `sales`). Cache-bust `?v=14`.

## 1.4.0 - Executive, live agents, JP requests, payroll
- **Map fix:** OpenStreetMap tiles were blocked ("Access blocked, 403") because the site sent no referrer. The referrer policy is now `strict-origin-when-cross-origin` (nginx and page) and tiles are reused while panning instead of re-requested.
- **Executive summary** (`/executive`): outlet coverage for the last 3 months, team counts and users active today, journey-plan status with a chart, on-shelf availability by brand (channel, week/month, must-sell-only filters), share of shelf actual vs expected by category, and the must-sell list.
- **Field agents** (`/field-agents`): live list (status, last seen, battery, last action), map, and a per-agent day view (selfie, device, distance travelled, outlet timeline with task completion, time on site, distance from outlet).
- **Performance score** per agent (0-100): completion, attendance (first outlet check-in), approval rate and proof-photo compliance, shown with its parts. Weights in `config/field.php`.
- **Journey plan change requests** (`/jp-requests`): agent asks to add / remove / move an outlet with GPS and a photo; supervisor or team leader approves or rejects; approval updates the route and is audited.
- **Payroll** (`/payroll`): runs from attendance (days, hours, late days), daily / hourly / monthly rates set on the user, adjustments, approve, mark paid, CSV export.
- **New roles:** Executive (read-only portfolio view) and HR / Payroll. "Campaign Manager" is now labelled **Project Manager**.
- **Devices:** every sign-in registers the device; a device never seen before for that person raises a security event and notifies their supervisor and team leader. New `POST /agent/ping` (location, battery, device) feeds the live view.
- **Products:** must-sell flag and expected share-of-shelf %.
- Migration `000010`. Run `php artisan migrate --force`.

## 1.3.4
- `php artisan demo:repair` (local only): resets/unlocks the demo accounts and adds the two demo Team Leaders if they are missing.

## 1.3.3
- **Roles:** "My work" and the field-work permissions (accept assignments, clock in, create/submit visits, upload evidence, capture outlets) now belong only to Field Agents and Team Leaders. The System Admin no longer has them. Team Leaders can now do outlet work themselves (they still never review their own visits). Migration `000009` applies this to existing databases.
- **Web:** new **New client & project** wizard (client, project type, outlets CSV, products, staff, launch) that creates and activates a project in one go.


## 1.3.2
- **Mobile:** an expired sign-in now sends the user to the sign-in screen straight away (and on returning to the app) instead of only failing at sync/submit. Unsent work stays on the phone and syncs after signing in.
- **Web:** *Locations > Import outlets (CSV)* adds up to 500 outlets at once, with a template, a "check first" step, per-row problems, reuse of outlets that already exist, and an optional campaign to link them all to.
- **API:** `POST /locations/import` (`rows[]`, `campaign_id?`, `client_id?`, `dry_run?`), permission `locations.manage`.

## 1.3.0 - MetaIntel Field Operation System
* Renamed to **MetaIntel Field Operation System** (short: *MetaIntel FOS* / *MetaIntel*); web and Android app are branded "MetaIntel".
* New role **Team Leader** (one region / smaller team) between Supervisor and Field Agent; `agent_profiles.team_leader_id`; users form, lookups, scopes, notifications.
* **Team attendance** screen for Team Leaders, Supervisors, Operations Managers and Admins (who is at an outlet, work start, time worked, every check-in with photos).
* Clock-in no longer counts as time worked; **work starts at the first outlet check-in** (`work_started_at`, `worked_minutes`).
* **200 m** check-in limit and **fake-GPS blocking** (server + phone), with alerts to the team.
* Mandatory **check-in and check-out photos** (selfie, camera only, stamped); required before a visit can be submitted (per-project switch).
* **Scheduled reports** emailed as CSV (daily/weekly/monthly), plus new **Outlet check-ins** and **Share of shelf (SOS)** reports; SOS capture on the phone.
* Docs: step-by-step new-project guide (PROJECT_SETUP.md), FIELD_RULES.md.
* Upgrade: rebuild, `php artisan migrate --force` (migration 000008 creates the role/tables), restart `app queue scheduler`, set `MAIL_*`, rebuild the APK.

## 1.2.x
Project templates, permanent/reliever agents, fixed journey plans, time-stamped camera-only photos, refusal banners.
