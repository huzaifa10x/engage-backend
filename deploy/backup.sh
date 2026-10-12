#!/usr/bin/env bash
# Nightly database + media backup (cron, installed by server-setup.sh / staging-setup.sh).
#   backup.sh              production: /opt/engage/backups, kept 14 days
#   backup.sh staging      staging:    /opt/engage-staging/backups, kept 3 days
# Restore: docs/environments.md → Backups and restore.
set -euo pipefail

env="${1:-production}"
case "$env" in
    production) root=/opt/engage project=engage files=(-f /opt/engage/backend/deploy/docker-compose.prod.yml) keep=14 ;;
    staging) root=/opt/engage-staging project=engage-staging files=(-f /opt/engage-staging/backend/deploy/docker-compose.prod.yml -f /opt/engage-staging/backend/deploy/docker-compose.staging.yml) keep=3 ;;
    *) echo "Usage: backup.sh [production|staging]"; exit 1 ;;
esac
dir="$root/backups"
stamp="$(date +%F-%H%M)"
compose=(docker compose --project-directory "$root/backend/deploy" -p "$project" "${files[@]}")
mkdir -p "$dir"

"${compose[@]}" exec -T postgres pg_dump -U postgres --format=custom engage > "$dir/db-$stamp.dump"
"${compose[@]}" exec -T app tar -czf - -C /app/storage app > "$dir/media-$stamp.tar.gz"
[[ -s "$dir/db-$stamp.dump" ]] || { echo "Backup FAILED: empty dump"; exit 1; }

find "$dir" -type f \( -name 'db-*' -o -name 'media-*' \) -mtime +"$keep" -delete
echo "Backup written ($env): $dir/db-$stamp.dump"
