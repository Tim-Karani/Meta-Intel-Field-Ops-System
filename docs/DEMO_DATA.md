# Demo project: Highlands Drinks, Nairobi Modern Trade

Local use only. Both commands refuse to run unless `APP_ENV` is `local` or `testing`.

## Start clean, then load the demo

```powershell
docker compose exec app php artisan demo:wipe --email=timothykarani001@gmail.com --yes --audit
docker compose exec app php artisan demo:highlands --days=21 --admin=timothykarani001@gmail.com
```

- `demo:wipe` removes everything except that System Administrator and the system set-up (roles, permissions, settings, system project templates, outlet types and statuses, GPS rules). `--audit` also clears the audit trail, which is otherwise kept because it is append-only by design. Photos already in storage (such as Cloudflare R2) are not touched.
- `demo:highlands` builds the client, 85 products, 34 Nairobi modern-trade outlets, 15 people, a journey plan per agent, and `--days` days of visits, photos, stock counts, orders, expiries, issues and attendance. Run it again only after a wipe.
- All demo people share one password, printed at the end of the run.

## What is real and what is invented

Real: brands, variants and pack sizes, taken from highlandske.com. Invented: prices, case sizes, branch lists and positions, people, results, issues.
