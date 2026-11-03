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
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS` | **needed for scheduled reports and password resets.** The default `MAIL_MAILER=log` only writes emails to the log. Use `smtp` with your provider (for Gmail: host `smtp.gmail.com`, port `587`, `tls`, an *app password*). After changing `.env` run `docker compose restart app queue scheduler`. |
| `CHECKIN_MAX_DISTANCE_M` | farthest an agent may be from a mapped outlet and still check in (default `200`) |
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
