# API reference

Base URL `/api/v1`. All responses use the envelope `{ "success", "message", "data", "meta" }`; errors use `{ "success": false, "message", "code", "errors", "request_id" }`. Authenticated routes take `Authorization: Bearer <token>`.

> Generated from `backend/routes/api.php`. "Gate" lists the middleware that guards the route; **any one** permission in a comma list is enough. Row-level access (which records you may see) is applied on top by `AccessScope` and the policies — a record outside your scope behaves as 404.

| Method | Path | Gate | Handler |
|---|---|---|---|
| GET | `/health` | public (minimal) | SystemController@health |
| POST | `/auth/login` | public · throttle: login | AuthController@login |
| POST | `/auth/forgot-password` | public · throttle: password-reset | AuthController@forgotPassword |
| POST | `/auth/reset-password` | public · throttle: password-reset | AuthController@resetPassword |
| GET | `/auth/me` | authenticated | AuthController@me |
| POST | `/auth/refresh` | authenticated | AuthController@refresh |
| POST | `/auth/logout` | authenticated | AuthController@logout |
| POST | `/auth/change-password` | authenticated | AuthController@changePassword |
| GET | `/lookups/enums` | authenticated | LookupController@enums |
| GET | `/lookups/{entity}` | authenticated | LookupController@options |
| GET | `/notifications` | `notifications.view` | NotificationController@index |
| GET | `/notifications/unread-count` | `notifications.view` | NotificationController@unreadCount |
| POST | `/notifications/read-all` | `notifications.view` | NotificationController@readAll |
| POST | `/notifications/{id}/read` | `notifications.view` | NotificationController@read |
| GET | `/sync/bootstrap` | `assignments.view` | SyncController@bootstrap |
| GET | `/sync/changes` | `assignments.view` | SyncController@changes |
| GET | `/dashboard` | `dashboard.view \| portal.view` | DashboardController |
| GET | `/portal/summary` | `portal.view` | PortalController@summary |
| GET | `/portal/campaigns` | `portal.view` | PortalController@campaigns |
| GET | `/portal/visits` | `portal.view` | PortalController@visits |
| GET | `/portal/visits/{id}` | `portal.view` | PortalController@visit |
| GET | `/portal/reports/{type}` | `portal.view` | PortalController@report |
| GET | `/portal/reports/{type}/export` | `portal.view`, throttle: exports | PortalController@exportReport |
| GET | `/users` | `users.view` | UserController@index |
| GET | `/users/{id}` | `users.view` | UserController@show |
| POST | `/users` | `users.create` | UserController@store |
| PATCH | `/users/{id}` | `users.edit` | UserController@update |
| PUT | `/users/{id}` | `users.edit` | UserController@update |
| POST | `/users/{id}/disable` | `users.disable` | UserController@disable |
| POST | `/users/{id}/enable` | `users.disable` | UserController@enable |
| POST | `/users/{id}/send-reset-link` | `users.edit` | UserController@sendResetLink |
| DELETE | `/users/{id}` | `users.disable` | UserController@destroy |
| GET | `/roles` | `roles.view \| users.view` | RoleController@index |
| GET | `/audit-logs` | `audit.view` | AuditLogController@index |
| GET | `/audit-logs/{id}` | `audit.view` | AuditLogController@show |
| GET | `/security-events` | `system.manage` | AuditLogController@securityEvents |
| GET | `/system/health` | `system.manage` | SystemController@details |
| GET | `/system/settings` | `system.manage` | SystemController@settings |
| PUT | `/system/settings` | `system.manage` | SystemController@updateSettings |
| GET | `/system/gps-rules` | `system.manage` | SystemController@gpsRules |
| PUT | `/system/gps-rules` | `system.manage` | SystemController@updateGpsRules |
| GET | `/clients` | `clients.view` | ClientController@index |
| GET | `/clients/{id}` | `clients.view` | ClientController@show |
| GET | `/clients/{client}/contacts` | `clients.view` | ClientContactController@index |
| GET | `/clients/{client}/documents` | `clients.view` | ClientDocumentController@index |
| GET | `/clients/{client}/documents/{id}/download` | `clients.view` | ClientDocumentController@download |
| POST | `/clients` | `clients.create` | ClientController@store |
| PATCH | `/clients/{id}` | `clients.edit` | ClientController@update |
| PUT | `/clients/{id}` | `clients.edit` | ClientController@update |
| POST | `/clients/{client}/contacts` | `clients.edit` | ClientContactController@store |
| PATCH | `/clients/{client}/contacts/{id}` | `clients.edit` | ClientContactController@update |
| DELETE | `/clients/{client}/contacts/{id}` | `clients.edit` | ClientContactController@destroy |
| POST | `/clients/{client}/documents` | `clients.edit`, throttle uploads | ClientDocumentController@store |
| DELETE | `/clients/{client}/documents/{id}` | `clients.edit` | ClientDocumentController@destroy |
| DELETE | `/clients/{id}` | `clients.delete` | ClientController@destroy |
| GET | `/campaigns` | `campaigns.view` | CampaignController@index |
| GET | `/campaigns/{id}` | `campaigns.view` | CampaignController@show |
| GET | `/campaigns/{id}/links/{relation}` | `campaigns.view` | CampaignLinkController@show |
| POST | `/campaigns` | `campaigns.create` | CampaignController@store |
| PATCH | `/campaigns/{id}` | `campaigns.edit` | CampaignController@update |
| PUT | `/campaigns/{id}` | `campaigns.edit` | CampaignController@update |
| POST | `/campaigns/{id}/transition` | `campaigns.edit` | CampaignController@transition |
| DELETE | `/campaigns/{id}` | `campaigns.edit` | CampaignController@destroy |
| PUT | `/campaigns/{id}/links/{relation}` | `campaigns.assign` | CampaignLinkController@sync |
| POST | `/kpis` | `campaigns.edit` | CampaignKpiController@store |
| PATCH | `/kpis/{id}` | `campaigns.edit` | CampaignKpiController@update |
| DELETE | `/kpis/{id}` | `campaigns.edit` | CampaignKpiController@destroy |
| GET | `/activities` | `activities.view` | ActivityController@index |
| GET | `/activities/{id}` | `activities.view` | ActivityController@show |
| POST | `/activities` | `activities.create` | ActivityController@store |
| PATCH | `/activities/{id}` | `activities.edit` | ActivityController@update |
| DELETE | `/activities/{id}` | `activities.edit` | ActivityController@destroy |
| GET | `/forms` | `forms.view` | FormController@index |
| GET | `/forms/{id}` | `forms.view` | FormController@show |
| POST | `/forms` | `forms.manage` | FormController@store |
| PATCH | `/forms/{id}` | `forms.manage` | FormController@update |
| PUT | `/forms/{id}/structure` | `forms.manage` | FormController@saveStructure |
| POST | `/forms/{id}/publish` | `forms.manage` | FormController@publish |
| POST | `/forms/{id}/archive` | `forms.manage` | FormController@archive |
| POST | `/forms/{id}/new-version` | `forms.manage` | FormController@newVersion |
| POST | `/forms/{id}/duplicate` | `forms.manage` | FormController@duplicate |
| DELETE | `/forms/{id}` | `forms.manage` | FormController@destroy |
| GET | `/assignments` | `assignments.view` | AssignmentController@index |
| GET | `/assignments/agents` | `assignments.view`, `assignments.create` | AssignmentController@agents |
| GET | `/assignments/{id}` | `assignments.view` | AssignmentController@show |
| POST | `/assignments` | `assignments.create` | AssignmentController@store |
| POST | `/assignments/bulk` | `assignments.create` | AssignmentController@bulk |
| PATCH | `/assignments/{id}` | `assignments.edit` | AssignmentController@update |
| POST | `/assignments/{id}/cancel` | `assignments.edit` | AssignmentController@cancel |
| POST | `/assignments/{id}/reassign` | `assignments.edit` | AssignmentController@reassign |
| POST | `/assignments/{id}/accept` | `assignments.respond` | AssignmentController@accept |
| GET | `/attendance` | `attendance.view` | AttendanceController@index |
| GET | `/attendance/today` | `attendance.view` | AttendanceController@today |
| GET | `/attendance/{id}` | `attendance.view` | AttendanceController@show |
| POST | `/attendance/clock-in` | `attendance.create` | AttendanceController@clockIn |
| POST | `/attendance/clock-out` | `attendance.create` | AttendanceController@clockOut |
| GET | `/visits` | `visits.view` | VisitController@index |
| GET | `/visits/{id}` | `visits.view` | VisitController@show |
| GET | `/visits/{visit}/sales` | `visits.view` | VisitDataController@sales |
| GET | `/visits/{visit}/osa` | `visits.view` | VisitDataController@osa |
| GET | `/visits/{visit}/merchandising` | `visits.view` | VisitDataController@merchandising |
| POST | `/visits` | `visits.create` | VisitController@store |
| POST | `/visits/{id}/check-in` | `visits.edit` | VisitController@checkIn |
| POST | `/visits/{id}/check-out` | `visits.edit` | VisitController@checkOut |
| PUT | `/visits/{id}/responses` | `visits.edit` | VisitController@responses |
| POST | `/visits/{visit}/sales` | `visits.edit` | VisitDataController@storeSale |
| PUT | `/visits/{visit}/osa` | `visits.edit` | VisitDataController@saveOsa |
| POST | `/visits/{visit}/merchandising` | `visits.edit` | VisitDataController@storeMerchandising |
| POST | `/visits/{id}/submit` | `visits.submit` | VisitController@submit |
| POST | `/visits/{id}/review` | `visits.review` | VisitController@review |
| POST | `/visits/{id}/approve` | `visits.approve` | VisitController@approve |
| POST | `/visits/{id}/reject` | `visits.reject` | VisitController@reject |
| GET | `/sales` | `sales.view` | SaleController@index |
| GET | `/osa` | `osa.view` | OsaController@index |
| GET | `/merchandising` | `merchandising.view` | MerchandisingController@index |
| GET | `/photos` | `evidence.view` | PhotoController@index |
| GET | `/photos/{id}` | `evidence.view` | PhotoController@show |
| GET | `/photos/{id}/file` | `evidence.view \| portal.view` | PhotoController@file |
| POST | `/visits/{visit}/photos` | `evidence.upload`, throttle uploads | PhotoController@store |
| POST | `/photos/{id}/review` | `evidence.review` | PhotoController@review |
| DELETE | `/photos/{id}` | `evidence.upload` | PhotoController@destroy |
| GET | `/issues` | `issues.view` | IssueController@index |
| GET | `/issues/{id}` | `issues.view` | IssueController@show |
| POST | `/issues/{id}/comments` | `issues.view` | IssueController@comment |
| POST | `/issues` | `issues.create` | IssueController@store |
| PATCH | `/issues/{id}` | `issues.create \| issues.assign \| issues.resolve` | IssueController@update |
| POST | `/issues/{id}/assign` | `issues.assign` | IssueController@assign |
| POST | `/issues/{id}/status` | `issues.resolve` | IssueController@transition |
| GET | `/reports` | `reports.view` | ReportController@types |
| GET | `/reports/{type}` | `reports.view` | ReportController@show |
| GET | `/reports/{type}/export` | `reports.export`, throttle: exports | ReportController@export |
| GET | `/teams` | `teams.view` | TeamController@index |
| GET | `/teams/{id}` | `teams.view` | TeamController@show |
| POST | `/teams` | `teams.manage` | TeamController@store |
| PATCH | `/teams/{id}` | `teams.manage` | TeamController@update |
| PUT | `/teams/{id}` | `teams.manage` | TeamController@update |
| DELETE | `/teams/{id}` | `teams.manage` | TeamController@destroy |
| GET | `/territories` | `territories.view` | TerritoryController@index |
| GET | `/territories/{id}` | `territories.view` | TerritoryController@show |
| POST | `/territories` | `territories.manage` | TerritoryController@store |
| PATCH | `/territories/{id}` | `territories.manage` | TerritoryController@update |
| PUT | `/territories/{id}` | `territories.manage` | TerritoryController@update |
| DELETE | `/territories/{id}` | `territories.manage` | TerritoryController@destroy |
| GET | `/routes` | `territories.view` | RouteController@index |
| GET | `/routes/{id}` | `territories.view` | RouteController@show |
| POST | `/routes` | `territories.manage` | RouteController@store |
| PATCH | `/routes/{id}` | `territories.manage` | RouteController@update |
| PUT | `/routes/{id}` | `territories.manage` | RouteController@update |
| DELETE | `/routes/{id}` | `territories.manage` | RouteController@destroy |
| GET | `/locations` | `locations.view` | LocationController@index |
| GET | `/locations/{id}` | `locations.view` | LocationController@show |
| POST | `/locations/import` | `locations.manage` | LocationController@import — bulk add up to 500 outlets (`rows[]`, optional `campaign_id`, `client_id`, `dry_run`); reuses existing outlets (same code, or name+town) |
| POST | `/locations/field` | `locations.capture` | LocationController@storeInField — agent adds an outlet in the field (client UUIDs, idempotent) |
| POST | `/locations/{id}/confirm-geo` | `locations.manage` | LocationController@confirmGeo — accept or correct a captured position |
| POST | `/locations` | `locations.manage` | LocationController@store |
| PATCH | `/locations/{id}` | `locations.manage` | LocationController@update |
| PUT | `/locations/{id}` | `locations.manage` | LocationController@update |
| DELETE | `/locations/{id}` | `locations.manage` | LocationController@destroy |
| GET | `/brands` | `products.view` | BrandController@index |
| GET | `/brands/{id}` | `products.view` | BrandController@show |
| POST | `/brands` | `products.manage` | BrandController@store |
| PATCH | `/brands/{id}` | `products.manage` | BrandController@update |
| PUT | `/brands/{id}` | `products.manage` | BrandController@update |
| DELETE | `/brands/{id}` | `products.manage` | BrandController@destroy |
| GET | `/product-categories` | `products.view` | ProductCategoryController@index |
| GET | `/product-categories/{id}` | `products.view` | ProductCategoryController@show |
| POST | `/product-categories` | `products.manage` | ProductCategoryController@store |
| PATCH | `/product-categories/{id}` | `products.manage` | ProductCategoryController@update |
| PUT | `/product-categories/{id}` | `products.manage` | ProductCategoryController@update |
| DELETE | `/product-categories/{id}` | `products.manage` | ProductCategoryController@destroy |
| GET | `/products` | `products.view` | ProductController@index |
| GET | `/products/{id}` | `products.view` | ProductController@show |
| POST | `/products` | `products.manage` | ProductController@store |
| PATCH | `/products/{id}` | `products.manage` | ProductController@update |
| PUT | `/products/{id}` | `products.manage` | ProductController@update |
| DELETE | `/products/{id}` | `products.manage` | ProductController@destroy |

## Project templates, journey plans (v1.2)

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/project-templates` | campaigns.view | the 5 built-in + custom templates (`settings` always complete) |
| POST/PATCH/DELETE | `/project-templates[/{id}]` | campaigns.edit / archive | built-in ones are read-only (403); in-use templates cannot be deleted (409) |
| POST/PATCH | `/campaigns` | campaigns.create/edit | accepts `template_id` and `settings` (copied from the template when `settings` is omitted) |
| GET/POST | `/journey-plans` | assignments.create | one plan per agent per campaign (409 otherwise) |
| GET | `/journey-plans/{id}` | assignments.create | includes `stops: [{location_id, weekdays[], activity_id, start_time}]` |
| PUT | `/journey-plans/{id}/stops` | assignments.edit | replaces stops; creates the next 14 days of visits |
| POST | `/journey-plans/{id}/generate` | assignments.edit | creates any missing visits now |
| POST | `/journey-plans/{id}/status` | assignments.edit | `active` / `paused` / `ended` (cancels upcoming unstarted visits) |
| GET | `/sync/bootstrap` | assignments.view | now also returns `campaigns` (with `settings`, `can_add_outlet`, `add_outlet_denial`) and `profile.employment_type` |
| POST | `/visits/{id}/photos` | evidence.upload | requires `source=camera` for evidence (422 `camera_required`) |


## v1.3 additions

| Method & path | Permission | Notes |
|---|---|---|
| `GET /attendance/board?date=` | `attendance.view` (not agents/clients) | team attendance: people in scope, state, work start/end, worked minutes, and the day's outlet check-ins with distance, GPS outcome and `checkin_photo_id` / `checkout_photo_id` |
| `POST /visits/{id}/photos` | `evidence.upload` | `kind` is now `evidence`, `signature`, `checkin` or `checkout`; everything except `signature` needs `source=camera` |
| `POST /visits/{id}/check-in` | `visits.create` | new refusals: `outside_radius` (more than 200 m from a mapped outlet) and `fake_gps` (mock location), both 422 |
| `POST /visits/{id}/submit` | `visits.submit` | 422 keys `checkin_photo`, `checkout_photo` when the project requires them |
| `POST /visits/{id}/merchandising` | `visits.edit` | optional `our_facings`, `total_facings` (share of shelf) |
| `GET /reports/checkin`, `GET /reports/sos` | `reports.view` | new report types |
| `GET /report-schedules/options`, `GET/POST /report-schedules`, `PATCH/DELETE /report-schedules/{id}`, `POST /report-schedules/{id}/send`, `GET /report-schedules/{id}/runs` | `reports.schedule` | scheduled reports; non-admins manage only their own |
| `GET /lookups/team_leaders` | staff | Team Leader picker |
| `POST/PATCH /users` | `users.create/edit` | role `team_leader`; `team_leader_id` on agents |


## v1.4 additions
| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/executive/summary` | `reports.view` | `month=YYYY-MM`, `client_id`, `campaign_id`, `period=week|month`, `channel`, `msl_only` |
| GET | `/agents/live` | `agents.track` | `search`, `seen_after`, `status`, `team_leader_id` |
| GET | `/agents/{id}/overview` | `agents.track` | `date` (default today): profile, device, distance, outlet timeline, score |
| GET | `/agents/scores` | `agents.track` | `date_from`, `date_to` |
| POST | `/agent/ping` | `attendance.create` | latitude, longitude, accuracy_m, battery_pct, device_model, os_version, app_version |
| GET | `/jp-requests` | `journey.request` or `journey.review` | `status` |
| POST | `/jp-requests` | `journey.request` | journey_plan_id, type add/remove/move, location_id, weekday, to_weekday, reason, latitude, longitude, photo, client_uuid |
| POST | `/jp-requests/{id}/approve` / `reject` | `journey.review` | reject needs `note`; cannot decide your own |
| GET | `/jp-requests/{id}/proof` | same as list | the photo |
| GET/POST | `/payroll-runs` | `payroll.view` / `payroll.manage` | name, period_start, period_end |
| GET | `/payroll-runs/{id}` , `/export` | `payroll.view` | lines; CSV |
| POST | `/payroll-runs/{id}/recalculate` , `approve` , `paid` | `payroll.manage` | draft -> approved -> paid |
| PATCH | `/payroll-runs/{id}/lines/{line}` | `payroll.manage` | adjustment, adjustment_note (draft only) |

## v1.5 additions
| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/auth/me` | any | now includes `features: { sales: bool }` (true when a project the user can see records sales) |
| GET | `/stock?campaign_id=` | `stock.view` | rows per agent and product: issued, sold, returned, balance. `agent_id` optional |
| GET | `/stock/movements?campaign_id=` | `stock.view` | hand-over log (latest 200) |
| POST | `/stock` | `stock.manage` | campaign_id, agent_id, product_id, kind `issue`/`return`/`adjust`, quantity, note (required for `adjust`). 422 `sales_not_enabled` if the project does not sell |
| POST | `/visits/{id}/submit` | `visits.submit` | response `data.status` is `approved` and `data.auto_approved` true when nothing needed review; otherwise `submitted` with `data.review_reason` (`new_outlet`, `gps_warning`, `review_all`) |
| POST | `/visits/{id}/check-in` | `visits.edit` | 422 `outlet_not_mapped` when the project is `capture_geo: office_only` and the outlet has no position |
| POST | `/visits/{id}/sales` | `visits.edit` | 422 `sales_not_enabled` when the project's Sales task is off |

Project settings gained `review` (`exceptions` | `all`) and `capture_geo` (`office_only` | `first_visit`); `tasks.sales` now defaults to off.

## v1.6 additions

| Method & path | Purpose |
|---|---|
| `GET/POST /channels`, `GET/PATCH/DELETE /channels/{id}` | Chains / trade groups. Delete is refused (`409 channel_in_use`) while outlets belong to it. |
| `GET /locations?channel_id=` | Outlets of one chain. Locations also accept `channel_id`, `region`, `locality`, `distributor`. |
| `POST /locations/import` | Rows may carry `channel`, `channel_group`, `region`, `locality`, `distributor`. |
| `GET /product-categories` | Returns `parent_id` / `parent`; `parent_id` on write makes a sub-category (one level). |
| `GET /reports/{type}` | New types: `osa_by_channel`, `osa_by_category`, `osa_by_outlet`, `osa_by_product`, `sos_by_channel`, `sos_by_category`, `sos_by_outlet`, `sos_by_product`, `outlets_not_visited`. New filters: `channel_id`, `category_id`, `brand_id`, `product_id`. |
| `GET /lookups/{channels,categories,brands}` | Picker lists. |


## v1.7 additions (outlets)
All use existing permissions: `locations.view` to read, `locations.manage` to change.

| Method & path | Purpose |
|---|---|
| `GET/POST/PATCH/DELETE /outlet-types` | Outlet types. Slug is generated and fixed. Delete blocked while outlets use it (`outlet_type_in_use`). |
| `GET/POST/PATCH/DELETE /outlet-subtypes` | Sub-types, each under one type (`outlet_type_id` filter). |
| `GET/POST/PATCH/DELETE /outlet-statuses` | Statuses (`tone`, `counts_as_active`, `is_default`). One default, which must count as active (409 `default_must_be_active`, `default_required`). |
| `GET/POST/PATCH/DELETE /distributors` | Distributors with `outlets_count`. |
| `GET/POST/PATCH/DELETE /geo-areas` | Places. `level` country, region, locality, area; parent must be one level up (`level`, `parent_id` filters). Delete blocked (`geo_has_children`, `geo_in_use`). |
| `POST /locations/batch` | `{ids (<=500), changes}`; changes limited to channel_id, type, outlet_subtype_id, outlet_status_id, distributor_id, geo_area_id, territory_id, setting. |
| `GET /locations/summary?group_by=channel\|type\|status\|region\|distributor` | Counts per group: total, mapped, needs_review, active. |
| `POST /locations/import` | Now accepts country, region, locality, area, subtype, distributor, status, phone, email, shop_no, market_name, setting. |

## v1.8 additions
- `users` / `PATCH /users/{id}`: `can_add_outlets` (agent switch).
- `GET /journey-plans/owners`: other agents covering the given outlets. Journey plan stops carry `days` detail; list returns `outlets_count`.
- Project settings: `targets` (osa, sos, visits_per_outlet, coverage); `outlet_adders` and review "all" removed.
- Reports `run` response: `targets`, `osa_gap`.
- Assignments outside campaign dates return 422.
