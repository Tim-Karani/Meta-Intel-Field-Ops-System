# Field Operations & Retail Execution Platform

Web side (Laravel 12 API + vanilla HTML/CSS/JS admin app) and the Flutter mobile app for field agents (`mobile/`).

| Path | What it is |
|---|---|
| `backend/` | Laravel 12 / PHP 8.4 / PostgreSQL REST API (`/api/v1`) |
| `web/` | Static web app (no build step, strict CSP, no inline script or style) |
| `mobile/` | Flutter app for field agents, offline-first (see `mobile/README.md`) |
| `docker/`, `docker-compose.yml` | PHP-FPM image, nginx (serves `web/`, proxies `/api`), Postgres, queue worker, scheduler |
| `docs/` | Architecture, data model, RBAC, security, deployment, testing, decisions, API reference |
| `legacy/` | The original visual template (React/Supabase), kept for reference only |

## Quick start (Docker)

```bash
export DB_PASSWORD='choose-a-strong-password'
export ADMIN_EMAIL='you@example.com' ADMIN_PASSWORD='a-long-Passw0rd-here'
docker compose up -d --build
docker compose exec app php artisan key:generate --force   # or set APP_KEY in backend/.env
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --force        # roles, permissions, settings, first admin
# open http://localhost:8080
```

Demo data (non-production only): `php artisan db:seed --class=DemoSeeder`.
Details, env vars, backups and TLS: [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

## Read this before relying on it

This was built in a sandbox with no Composer/Packagist, npm registry or PostgreSQL access. See
[docs/TESTING.md](docs/TESTING.md) for exactly what was executed and what was only written.
In short: PHP is lint-checked, the pure unit tests were run, and the web app was exercised in
headless Chromium against a mock API. **The Laravel feature/security test suites have not been run
against a real database yet.** Run `composer install && php artisan test` first (CI does this).

## Docs
[Architecture](docs/ARCHITECTURE.md) · [Data model](docs/DATA_MODEL.md) · [RBAC](docs/RBAC.md) ·
[Security](docs/SECURITY.md) · [Web app](docs/WEB_APP.md) · [Deployment](docs/DEPLOYMENT.md) ·
[Testing](docs/TESTING.md) · [Decisions](docs/DECISIONS.md) · [API reference](docs/API_REFERENCE.md) · [Mobile & sync](docs/MOBILE_APP.md)

- Outlets without coordinates and field-added outlets: [docs/OUTLETS.md](docs/OUTLETS.md)
