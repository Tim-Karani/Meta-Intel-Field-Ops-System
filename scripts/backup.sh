#!/usr/bin/env bash
# Daily database copy. Photos live in Cloudflare R2 and are not part of this file.
# Keeps 14 days in ./backups. Add to cron:  0 2 * * * /home/deploy/field-ops/scripts/backup.sh >> /home/deploy/backup.log 2>&1
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p backups
f="backups/field_ops_$(date +%Y%m%d_%H%M).dump"
docker compose exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" -Fc "$POSTGRES_DB"' > "$f"
test -s "$f" || { echo "Backup is empty, something is wrong" >&2; rm -f "$f"; exit 1; }
find backups -name 'field_ops_*.dump' -mtime +14 -delete
echo "$(date) saved $f ($(du -h "$f" | cut -f1))"
