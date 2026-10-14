# Architecture (as built)

```
Browser (vanilla JS SPA)  ──HTTPS──►  nginx  ──/api──►  PHP-FPM (Laravel 12)  ──►  PostgreSQL
 hash router, no build                static + CSP        controllers → policies        UUID PKs, CHECKs
                                                          → Domain services              database queue + cache
Flutter app (next phase) ──HTTPS──►  same /api/v1                                       private evidence disk
```

## Backend layers
1. **Routes** (`routes/api.php`, 171 routes) — `auth:sanctum`, `EnsureUserActive`, `permission:` any-of, throttles.
2. **Controllers** (`Http/Controllers/Api/V1`) — thin. Generic CRUD lives in `ResourceController` (scoped query → Policy → validated write → audit). Others: Auth, Visit, Assignment, Campaign, Form, Evidence, Issue, Report, Portal, Dashboard, Lookups, System.
3. **Policies + `AccessScope`** — permission and row scope; hidden = 404.
4. **Domain services** (`app/Domain`) — Visits (`VisitService`, `GpsValidator`), Assignments/Campaigns, Forms (versioning, response validation), Evidence (re-encode, hash, private storage), Reports (13 types, dashboard), Audit, Notifications.
5. **Enums** hold the state machines (campaign, assignment, visit, issue) with legal transitions.
6. **Responses** — envelope `{success,message,data,meta}`; errors `{success:false,message,code,errors,request_id}`; `AssignRequestId` sets the id.

## Key flows
- **Visit**: assignment → `POST /visits` (idempotent on `client_uuid`) → check-in (GPS evaluated server-side) → responses/sales/OSA/merchandising/photos/issues → check-out → submit → review → approve/reject. Submitted visits are immutable; every step appends a `visit_events` row.
- **Forms** are versioned and immutable once published; visits record the version they answered.
- **Overdue**: daily job flips past-due assignments.
- **Client portal**: separate `/portal/*` endpoints that return only approved, client-scoped, agent-anonymised data.

## Offline sync (mobile phase)
The API already provides what the Flutter client needs: `client_uuid` idempotency on all offline-created entities with a `UNIQUE(created_by, client_uuid)` backstop, a stable error taxonomy (401/403/409/422/429), and server-authoritative config. The blueprint's batch `POST /sync` and `GET /sync/changes` cursor endpoints are **not built yet**; the mobile phase will either add them or sync entity-by-entity through the existing endpoints. Design: local SQLite with an ordered outbox, states `PENDING → SYNCING → SYNCED | FAILED | CONFLICT`, exponential backoff with jitter, photos uploaded after their parent visit, local files deleted only after server confirmation.

## Web layer
See [WEB_APP.md](WEB_APP.md). Security controls: [SECURITY.md](SECURITY.md). Access rules: [RBAC.md](RBAC.md).
