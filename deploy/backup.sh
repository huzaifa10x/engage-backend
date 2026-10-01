#!/usr/bin/env bash
# Nightly database + media backup (cron, installed by server-setup.sh). Keeps 14 days.
set -euo pipefail

dir=/opt/engage/backups
stamp="$(date +%F-%H%M)"
compose=(docker compose --project-directory /opt/engage/backend/deploy -f /opt/engage/backend/deploy/docker-compose.prod.yml)
mkdir -p "$dir"

"${compose[@]}" exec -T postgres pg_dump -U postgres --format=custom engage > "$dir/db-$stamp.dump"
"${compose[@]}" exec -T app tar -czf - -C /app/storage app > "$dir/media-$stamp.tar.gz"

find "$dir" -type f -mtime +14 -delete
echo "Backup written: $dir/db-$stamp.dump"
