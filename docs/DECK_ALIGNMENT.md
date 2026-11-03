# Pitch deck vs the system (v1.4)

An honest map from each slide of "Next-Generation Field Intelligence Platform" to what exists today.

| Deck item | Status | Where / what is missing |
|---|---|---|
| Mobile application (merchandiser) | Built | Flutter app. **Still to add on the phone:** journey-plan change request screen, location/battery ping (`POST /agent/ping`), a stable device id at sign-in. The server side is ready. |
| Web admin portal | Built | Executive summary, live field agents, JP requests, payroll added in 1.4. |
| Automation engine | Built | Journey-plan visit generation, overdue marking, scheduled reports. |
| Scoring & analytics engine | Built (v1) | Agent score 0-100 from four explainable measures; Executive summary; reports. Store/outlet scoring and client scorecards are not built. |
| Payroll engine | Built (v1) | Runs from attendance with daily / hourly / monthly rates, adjustments, approval, CSV. Statutory deductions (PAYE, NSSF, SHA) and payslips are **not** built. |
| AI monitoring layer | Partly | Rule-based risk scoring (GPS distance, fake GPS, impossible travel, clock skew, duplicate coordinates, device change, new device alert). No machine-learning models yet. Photo duplicate detection (the SHA-256 of each photo is already stored) is the natural next rule. |
| Roles: Super Admin, Executive, Client, Project Manager, Supervisor, HR, Merchandiser | Built | Plus Operations Manager, Team Leader and Analyst. |
| Engagement lifecycle | Built | Project setup wizard, execution, monitoring, scoring, payroll, executive reporting. |
| JP automation | Built on the server | Request with GPS + photo, instant notification, approve/reject in the dashboard, route updated, audit trail. The agent's request screen on the phone is the missing piece. |
| HTTPS | Deployment | Terminate TLS at a reverse proxy; see DEPLOYMENT.md. |
| JWT authentication | Equivalent | Laravel Sanctum bearer tokens (hashed, expiring, revocable). |
| Role-based access control | Built | Permissions + row-level scopes. |
| Device fingerprinting | Basic | Devices are registered per person from device name + browser/app signature; a new device alerts the supervisor. A hardware-bound id from the phone would make it stronger. |
| Audit trail immutability | Built | Append-only audit log; payroll and request decisions are recorded. |
| Multi-tenant data isolation | Partly | Client users are isolated to their own client. Several independent agencies in one installation (true multi-tenant) is **not** built. |


## Added in v1.5
* Stock accountability for sales-rep / foot-soldier projects (issue, return, sold, balance) - covers the deck's stock-to-agent reconciliation at a basic level. Not yet: van/warehouse stock, stock-take counts by photo, cash reconciliation.
