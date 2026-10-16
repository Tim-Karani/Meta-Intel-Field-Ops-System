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
