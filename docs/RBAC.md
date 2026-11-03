# RBAC

Ten roles, 66 permission slugs, granted on a **least-privilege** basis (v1.5). Routes are guarded by `permission:` middleware (any-of), objects by Policies,
and **rows** by `AccessScope`: a record outside your scope returns **404**, indistinguishable from a missing one.
Roles and grants are seeded from `App\Enums\Permissions::forRole`, the single source of truth. Migration `000011` syncs an existing database to exactly that list (it removes grants a role no longer has, as well as adding new ones). The menu follows the grants: a role never sees a screen it has no permission for.

## Row scopes

| Role | Scope | Sees |
|---|---|---|
| system_admin, operations_manager | all | everything |
| campaign_manager, analyst | campaigns | campaigns they are attached to (`campaign_users`) |
| supervisor | team | every agent they supervise **in all regions**, the Team Leaders who report to them, and those people's work |
| team_leader | team | only the agents in **their own region / smaller team** (`agent_profiles.team_leader_id`) and those agents' work |
| field_agent | own | only their own assignments, visits, attendance, issues |
| client_user | client | only their client's approved data, via `/portal`; agent identities hidden |

## Who reports to whom

```
Operations Manager / System Admin   (all projects, clients, teams and users)
        └─ Supervisor               (the whole field team: every region, including the Team Leaders)
              └─ Team Leader        (one region or smaller team, e.g. Nairobi)
                    └─ Field Agent  (permanent merchandiser or reliever)
```

* A **Team Leader** and a **Supervisor** both use the `team` scope; the difference is how many people it reaches (see `AccessScope::supervisedAgentIds`).
* When an agent is given a Team Leader and no Supervisor, the Supervisor and region are copied from the Team Leader.
* **Attendance and outlet check-ins** (web: *Team attendance*) are visible to Team Leaders, Supervisors, Operations Managers and System Admins, each within their own scope. Field agents and clients cannot open it.
* **Scheduled reports** can be created by System Admin, Operations Manager, Campaign Manager and Supervisor (`reports.schedule`). Team Leaders and Analysts can run and export reports but not schedule them.

## Least privilege: what each role is for

| Role | Holds only what it needs to... |
|---|---|
| System Administrator | configure and supervise (users, master data, settings, audit). No field work, and no payroll (HR's job). |
| Operations Manager | run all projects and teams. |
| Project Manager | set up and run the projects they are attached to. |
| Supervisor | look after their agents and team leaders: assignments, review of exceptions, issues, live map, reports for their area. |
| Team Leader | run today's work for their own agents: assignments, review of exceptions, issues, live map, JP requests, stock. Also does outlet work. Nothing else: no master data, no company reports, no administration. |
| Analyst | read the data and export. |
| Field Agent | their own work only: assignments, attendance, visits, photos, issues, journey-plan requests. |
| Executive | read results and see where people are. |
| HR / Payroll | attendance and payroll. |
| Client user | the approved results of their own client in the portal. |

## Permission matrix (● granted)

Generated from `Permissions::forRole`.

| Permission | System Administrator | Agency / Operations Manager | Project Manager | Supervisor | Team Leader | Analyst | Field Agent | Authorized Client User | Executive | HR / Payroll |
|---|---|---|---|---|---|---|---|---|---|---|
| `dashboard.view` | ● | ● | ● | ● | ● | ● | ● | · | ● | ● |
| `clients.view` | ● | ● | ● | · | · | ● | · | · | ● | · |
| `clients.create` | ● | ● | · | · | · | · | · | · | · | · |
| `clients.edit` | ● | ● | · | · | · | · | · | · | · | · |
| `clients.delete` | ● | · | · | · | · | · | · | · | · | · |
| `campaigns.view` | ● | ● | ● | ● | ● | ● | · | · | ● | · |
| `campaigns.create` | ● | ● | · | · | · | · | · | · | · | · |
| `campaigns.edit` | ● | ● | ● | · | · | · | · | · | · | · |
| `campaigns.assign` | ● | ● | ● | · | · | · | · | · | · | · |
| `campaigns.archive` | ● | ● | · | · | · | · | · | · | · | · |
| `activities.view` | ● | ● | ● | ● | ● | ● | · | · | · | · |
| `activities.create` | ● | ● | ● | · | · | · | · | · | · | · |
| `activities.edit` | ● | ● | ● | · | · | · | · | · | · | · |
| `users.view` | ● | ● | ● | ● | ● | · | · | · | · | ● |
| `users.create` | ● | · | · | · | · | · | · | · | · | · |
| `users.edit` | ● | · | · | · | · | · | · | · | · | · |
| `users.disable` | ● | · | · | · | · | · | · | · | · | · |
| `roles.view` | ● | ● | · | · | · | · | · | · | · | · |
| `teams.view` | ● | ● | ● | ● | · | ● | · | · | · | · |
| `teams.manage` | ● | ● | ● | · | · | · | · | · | · | · |
| `territories.view` | ● | ● | · | · | · | ● | · | · | · | · |
| `territories.manage` | ● | ● | · | · | · | · | · | · | · | · |
| `locations.view` | ● | ● | ● | ● | ● | ● | · | · | · | · |
| `locations.manage` | ● | ● | ● | · | · | · | · | · | · | · |
| `locations.capture` | · | · | · | · | ● | · | ● | · | · | · |
| `products.view` | ● | ● | ● | · | · | ● | · | · | · | · |
| `products.manage` | ● | ● | ● | · | · | · | · | · | · | · |
| `forms.view` | ● | ● | ● | · | · | ● | · | · | · | · |
| `forms.manage` | ● | ● | ● | · | · | · | · | · | · | · |
| `assignments.view` | ● | ● | ● | ● | ● | ● | ● | · | · | · |
| `assignments.create` | ● | ● | ● | ● | ● | · | · | · | · | · |
| `assignments.edit` | ● | ● | ● | ● | ● | · | · | · | · | · |
| `assignments.respond` | · | · | · | · | ● | · | ● | · | · | · |
| `attendance.view` | ● | ● | ● | ● | ● | ● | ● | · | · | ● |
| `attendance.create` | · | · | · | · | ● | · | ● | · | · | · |
| `visits.view` | ● | ● | ● | ● | ● | ● | ● | · | · | · |
| `visits.create` | · | · | · | · | ● | · | ● | · | · | · |
| `visits.edit` | · | · | · | · | ● | · | ● | · | · | · |
| `visits.submit` | · | · | · | · | ● | · | ● | · | · | · |
| `visits.review` | ● | ● | ● | ● | ● | · | · | · | · | · |
| `visits.approve` | ● | ● | ● | ● | ● | · | · | · | · | · |
| `visits.reject` | ● | ● | ● | ● | ● | · | · | · | · | · |
| `sales.view` | ● | ● | ● | · | · | ● | · | · | · | · |
| `osa.view` | ● | ● | ● | · | · | ● | · | · | · | · |
| `merchandising.view` | ● | ● | ● | · | · | ● | · | · | · | · |
| `evidence.view` | ● | ● | ● | ● | ● | ● | ● | · | · | · |
| `evidence.upload` | · | · | · | · | ● | · | ● | · | · | · |
| `evidence.review` | ● | ● | ● | ● | ● | · | · | · | · | · |
| `issues.view` | ● | ● | ● | ● | ● | ● | ● | · | · | · |
| `issues.create` | ● | ● | ● | ● | ● | · | ● | · | · | · |
| `issues.assign` | ● | ● | ● | ● | ● | · | · | · | · | · |
| `issues.resolve` | ● | ● | ● | ● | ● | · | · | · | · | · |
| `reports.view` | ● | ● | ● | ● | · | ● | · | · | ● | ● |
| `reports.export` | ● | ● | ● | ● | · | ● | · | · | ● | ● |
| `reports.schedule` | ● | ● | ● | ● | · | · | · | · | · | · |
| `notifications.view` | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● |
| `audit.view` | ● | ● | · | · | · | · | · | · | · | · |
| `system.manage` | ● | · | · | · | · | · | · | · | · | · |
| `portal.view` | ● | · | · | · | · | · | · | ● | · | · |
| `journey.request` | · | · | · | · | ● | · | ● | · | · | · |
| `journey.review` | ● | ● | ● | ● | ● | · | · | · | · | · |
| `payroll.view` | ● | · | · | · | · | · | · | · | · | ● |
| `payroll.manage` | · | · | · | · | · | · | · | · | · | ● |
| `agents.track` | ● | ● | ● | ● | ● | · | · | · | ● | ● |
| `stock.view` | ● | ● | ● | ● | ● | ● | · | · | · | · |
| `stock.manage` | ● | ● | ● | ● | ● | · | · | · | · | · |

Totals: System Administrator 57, Agency / Operations Manager 50, Project Manager 42, Supervisor 28, Team Leader 32, Analyst 21, Field Agent 16, Authorized Client User 2, Executive 7, HR / Payroll 9 of 66
