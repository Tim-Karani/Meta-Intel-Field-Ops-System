# RBAC

Seven roles, 58 permission slugs. Routes are guarded by `permission:` middleware (any-of), objects by Policies,
and **rows** by `AccessScope`: a record outside your scope returns **404**, indistinguishable from a missing one.
Roles and grants are seeded by `RolePermissionSeeder`; the matrix below is generated from it.

## Row scopes

| Role | Scope | Sees |
|---|---|---|
| system_admin, operations_manager | all | everything |
| campaign_manager, analyst | campaigns | campaigns they are attached to (`campaign_users`) |
| supervisor | team | their teams' agents and those agents' work |
| field_agent | own | only their own assignments, visits, attendance, issues |
| client_user | client | only their client's approved data, via `/portal`; agent identities hidden |

## Permission matrix (● granted)

| Permission | SystemAdmin | OperationsManager | CampaignManager | Supervisor | Analyst | FieldAgent | ClientUser |
|---|---|---|---|---|---|---|---|
| `dashboard.view` | ● | ● | ● | ● | ● | ● | · |
| `clients.view` | ● | ● | ● | ● | ● | · | · |
| `clients.create` | ● | ● | · | · | · | · | · |
| `clients.edit` | ● | ● | · | · | · | · | · |
| `clients.delete` | ● | ● | · | · | · | · | · |
| `campaigns.view` | ● | ● | ● | ● | ● | · | · |
| `campaigns.create` | ● | ● | · | · | · | · | · |
| `campaigns.edit` | ● | ● | ● | · | · | · | · |
| `campaigns.assign` | ● | ● | ● | · | · | · | · |
| `campaigns.archive` | ● | ● | · | · | · | · | · |
| `activities.view` | ● | ● | ● | ● | ● | · | · |
| `activities.create` | ● | ● | ● | · | · | · | · |
| `activities.edit` | ● | ● | ● | · | · | · | · |
| `users.view` | ● | ● | · | · | · | · | · |
| `users.create` | ● | · | · | · | · | · | · |
| `users.edit` | ● | · | · | · | · | · | · |
| `users.disable` | ● | · | · | · | · | · | · |
| `roles.view` | ● | ● | · | · | · | · | · |
| `teams.view` | ● | ● | ● | ● | ● | · | · |
| `teams.manage` | ● | ● | ● | ● | · | · | · |
| `territories.view` | ● | ● | ● | ● | ● | · | · |
| `territories.manage` | ● | ● | · | · | · | · | · |
| `locations.view` | ● | ● | ● | ● | ● | ● | · |
| `locations.manage` | ● | ● | ● | · | · | · | · |
| `products.view` | ● | ● | ● | ● | ● | ● | · |
| `products.manage` | ● | ● | ● | · | · | · | · |
| `forms.view` | ● | ● | ● | ● | ● | ● | · |
| `forms.manage` | ● | ● | ● | · | · | · | · |
| `assignments.view` | ● | ● | ● | ● | ● | ● | · |
| `assignments.create` | ● | ● | ● | ● | · | · | · |
| `assignments.edit` | ● | ● | ● | ● | · | · | · |
| `assignments.respond` | ● | · | · | · | · | ● | · |
| `attendance.view` | ● | ● | ● | ● | ● | ● | · |
| `attendance.create` | ● | · | · | · | · | ● | · |
| `visits.view` | ● | ● | ● | ● | ● | ● | · |
| `visits.create` | ● | · | · | · | · | ● | · |
| `visits.edit` | ● | · | · | · | · | ● | · |
| `visits.submit` | ● | · | · | · | · | ● | · |
| `visits.review` | ● | ● | ● | ● | · | · | · |
| `visits.approve` | ● | ● | ● | ● | · | · | · |
| `visits.reject` | ● | ● | ● | ● | · | · | · |
| `sales.view` | ● | ● | ● | ● | ● | · | · |
| `osa.view` | ● | ● | ● | ● | ● | · | · |
| `merchandising.view` | ● | ● | ● | ● | ● | · | · |
| `evidence.view` | ● | ● | ● | ● | ● | ● | · |
| `evidence.upload` | ● | · | · | · | · | ● | · |
| `evidence.review` | ● | ● | ● | ● | · | · | · |
| `issues.view` | ● | ● | ● | ● | ● | ● | · |
| `issues.create` | ● | ● | ● | ● | · | ● | · |
| `issues.assign` | ● | ● | ● | ● | · | · | · |
| `issues.resolve` | ● | ● | ● | ● | · | · | · |
| `reports.view` | ● | ● | ● | ● | ● | · | · |
| `reports.export` | ● | ● | ● | ● | ● | · | · |
| `notifications.view` | ● | ● | ● | ● | ● | ● | ● |
| `audit.view` | ● | ● | · | · | · | · | · |
| `system.manage` | ● | · | · | · | · | · | · |
| `portal.view` | ● | · | · | · | · | · | ● |

