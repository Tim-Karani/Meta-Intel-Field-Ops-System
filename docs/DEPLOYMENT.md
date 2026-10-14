# Deployment

## Services (`docker-compose.yml`)
`db` (Postgres 16, not published) · `app` (PHP-FPM) · `queue` (`queue:work`) · `scheduler` (`schedule:work`) · `web` (nginx on :8080, serves `web/`, proxies `/api` to `app`).

## Environment (`backend/.env`, see `.env.example`)
| Var | Notes |
|---|---|
| `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` | generate with `php artisan key:generate` |
| `DB_*` | `DB_PASSWORD` is required by compose |
| `QUEUE_CONNECTION=database`, `CACHE_STORE=database` | shared by all containers |
| `ADMIN_EMAIL`, `ADMIN_PASSWORD`, `ADMIN_NAME` | first administrator; forced to change password in production |
| `SANCTUM_TOKEN_TTL_MINUTES` | token lifetime (default 1440) |
| `EVIDENCE_PATH` | private evidence directory (default `storage/app/private/evidence`); mount as a volume and back it up with the database |

## First deploy
```bash
docker compose up -d --build
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --force
docker compose exec app php artisan config:cache route:cache
```

## Operations
- **Scheduler**: heartbeat each minute, `assignments:mark-overdue` daily 00:05, `auth:clear-resets` daily. The heartbeat is stored in the cache for monitoring.
- **Backups**: `docker compose exec db pg_dump -U $DB_USERNAME -Fc $DB_DATABASE > backup.dump` plus the storage volume (photos). Restore: `pg_restore -c -d`. Test a restore before go-live.
- **TLS**: terminate HTTPS in front of nginx (Caddy, Traefik or the host), then enable the HSTS line in `docker/nginx/default.conf`.
- **Web config**: edit `web/config.js` (API base, map tiles, currency). It holds no secrets.
- **Upgrades**: rebuild, `migrate --force`, restart `queue` so workers pick up new code.
