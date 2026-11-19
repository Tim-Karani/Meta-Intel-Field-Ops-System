# Where the system stands (update at every release)

Last updated: 19 November 2026, version 1.11.1. "Built" means the code exists; only what is marked **Verified** has been run on a real machine.

| Area | State | Verified |
|---|---|---|
| Web admin and client portal (all pages in the menu) | Built | Used on the owner's laptop and through the tunnel |
| API, permissions, 10 roles, audit, security events | Built | Health, sign-in and phone sync work on a real phone; the PHPUnit suite has 9 old failures not yet fixed |
| Photos in Cloudflare R2 | Built | **Verified** (System health green) |
| Phone: visits, offline outbox, check-in/out, photos, issues, attendance | Built | **Verified** on a real phone through the tunnel |
| Phone: new Home, plan-change request, location/battery ping | Built | Compiled and tested (26 tests); real-phone use partly checked |
| Phone: barcode scan, background sync, phone notifications, release signing (1.11.0) | Built | **Not yet compiled or run** |
| Instant push notifications (Firebase) | Not built (15-minute notifications instead) | n/a |
| Show/hide password, editable profile, new dashboard | Built | Used |
| Email delivery (SMTP), test-email button (1.11.1) | Built | Not yet run; needs a mail provider (docs/EMAIL_SETUP.md) |
| Live server kit: Caddy padlock, backups, setup guide | Built | Not yet run (no server yet) |
| Trusted proxy setting | Built (`TRUSTED_PROXIES=*`) | Set it on the live server |

## Still open (none blocks a small pilot except the first two)
1. **Fix the nine failing backend tests** (needs `test-results.txt` from `scripts/run-tests.ps1`).
2. **Build and try the 1.11.0 phone app** on a real phone.
3. Reports performance at about 20,000 visits (work package 3): not measured. Needed before about 50 agents, not for 10.
4. Automatic data deletion (retention): not built; the rules depend on the client contract.
5. Two-step sign-in for administrators: decided for later.
6. Instant push, dark mode, design-system document, the older docs refresh.
7. `composer.lock` is not in the repository (a first `composer install` on a machine with internet creates it; commit it).
