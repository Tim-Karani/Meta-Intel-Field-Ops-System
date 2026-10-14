# Security

| Area | Implementation |
|---|---|
| Authentication | Sanctum bearer tokens; password min 10 chars with mixed case, letters and numbers; `must_change_password` flag; password reset tokens expire and are cleared daily |
| Brute force | Login throttle 5/min per login+IP and 30/min per IP; account lockout after 5 failures in 15 min; every failure writes a `security_events` row |
| Other throttles | API 240/min, password reset 5/min, uploads 60/min, exports 10/min |
| Authorization | Route permissions, Policies, then `AccessScope` row scoping. Foreign keys in request bodies use the `Accessible` rule so you cannot attach records you cannot see. Out-of-scope = 404 |
| Mass assignment | Only validated keys are written; sorting/filtering use allow-lists; UUID filters validated (422, never a DB error) |
| GPS | Server recomputes distance; device clock vs server clock, accuracy, impossible speed and mock-location flags produce an outcome and reasons that reviewers see |
| Idempotency | `client_uuid` + `UNIQUE(created_by, client_uuid)`; replays return the original result |
| Uploads | Size cap (10 MB), MIME sniffed from content, decoded and **re-encoded to JPEG** (strips EXIF/polyglots), max 2048 px, stored on a private disk with random names, SHA-256 recorded to detect reused photos; served only through an authenticated endpoint |
| Audit | Append-only `audit_logs` (actor, action, before/after, IP, request id) and `security_events`; exports are audited |
| CSV export | Cells starting with `= + - @` or tab/CR are neutralised |
| Headers | `SecurityHeaders` middleware (API) and nginx: CSP `script-src 'self'; style-src 'self'` with no inline code, `frame-ancestors 'none'`, `nosniff`, `Referrer-Policy: no-referrer`; HSTS line provided, enable once HTTPS-only |
| Web | DOM built with `h()`; no `innerHTML` (CI greps for it); evidence images fetched with the token as blobs |
| Secrets | None in the repo. `DB_PASSWORD`, `APP_KEY`, `ADMIN_PASSWORD` come from the environment; the seeder refuses production without them |
| Containers | Database port not published; app runs as non-root in a multi-stage image |

Known gaps: no 2FA, no email delivery for password reset (token flow exists), tile images load from openstreetmap.org (change `mapTileUrl` and the CSP to self-host), and no dependency/vulnerability scanning in CI yet.
