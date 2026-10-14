# Testing: what was actually run

| Layer | Written | Executed in the build sandbox |
|---|---|---|
| PHP syntax (all backend files) | – | **Yes**, `php -l`, clean |
| Unit tests (CsvSafety, GpsValidator, Permissions, StateMachine; 33 tests) | Yes | **Yes**, through a small shim runner, because PHPUnit could not be installed |
| Feature tests (Auth, FormBuilder, Reports, ResourceCrud, VisitWorkflow) | Yes | **No** (needs Composer deps and PostgreSQL) |
| Security tests (Idor, PrivilegeEscalation, ClientIsolation, BruteForce, Replay, GpsManipulation, UploadSecurity, InjectionAndHeaders) | Yes | **No** |
| Web app: every page loaded in headless Chromium under the strict CSP, with CRUD/filter/modal flows | Yes | **Yes**, against a **mock API I wrote** from the controller code |
| Web app against the real API | – | **No** |
| Docker build, compose, nginx config, CI workflow | Yes | **No** |

## Consequences
Expect some first-run failures in the un-run suites (typical: factory/column mismatches, a policy edge case) and some response-shape mismatches between the real API and the web app. They should be quick to fix, but they are not yet found.

## Run it
```bash
cd backend && composer install && cp .env.example .env && php artisan key:generate
php artisan test                      # needs PostgreSQL (see phpunit.xml / CI)
php artisan test --testsuite=Security
```
CI (`.github/workflows/ci.yml`) runs Unit, Feature and Security against a Postgres service, `node --check` on the web app, and a no-`innerHTML` grep. First thing to do after unpacking: get CI green.
