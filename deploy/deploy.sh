#!/usr/bin/env bash
# Deploys the latest `main` of one or both repos on the server.
#   deploy.sh backend   pull + build backend, migrate, restart backend services
#   deploy.sh web       pull + build the Next.js client
#   deploy.sh all       both (first install)
#   deploy.sh restart   restart backend containers (after editing .env)
# Called by GitHub Actions on every push to main; safe to run by hand.
set -euo pipefail

# Everything runs inside main(): bash has parsed the whole script before `git reset`
# replaces this file on disk.
main() {
    local target="${1:-all}"
    local root=/opt/engage
    local compose=(docker compose --project-directory "$root/backend/deploy" -f "$root/backend/deploy/docker-compose.prod.yml")

    log() { printf '\n\033[1;34m▸ %s\033[0m\n' "$*"; }
    update() {
        log "Updating $1"
        git -C "$1" fetch --quiet origin main
        git -C "$1" reset --hard --quiet origin/main
        git -C "$1" log -1 --format='  %h %s (%an)'
    }

    exec 9>/tmp/engage-deploy.lock
    flock 9 # one deploy at a time (backend and web pushes can arrive together)

    case "$target" in
        backend | all) update "$root/backend" ;;
    esac
    case "$target" in
        web | all) update "$root/web" ;;
    esac

    if [[ "$target" == backend || "$target" == all ]]; then
        log "Building backend image"
        "${compose[@]}" build app
        "${compose[@]}" up -d --wait postgres valkey   # waits until both healthchecks pass
        log "Running migrations"
        "${compose[@]}" run --rm --no-deps app php artisan migrate --force
        "${compose[@]}" run --rm --no-deps app php artisan engage:partitions
        log "Starting backend services"
        "${compose[@]}" up -d app horizon scheduler reverb
    fi

    if [[ "$target" == web || "$target" == all ]]; then
        log "Building web image"
        "${compose[@]}" build web
        "${compose[@]}" up -d web
    fi

    if [[ "$target" == restart ]]; then
        log "Restarting backend services"
        "${compose[@]}" up -d --force-recreate app horizon scheduler reverb
    fi

    "${compose[@]}" up -d caddy

    log "Health check"
    local domain
    domain="$(grep -E '^DOMAIN=' "$root/backend/deploy/.env" | cut -d= -f2-)"
    for i in $(seq 1 30); do
        if curl -fsS -o /dev/null "https://$domain/up"; then
            log "✅ Live: https://$domain"
            docker image prune -f >/dev/null
            return 0
        fi
        sleep 5
    done

    echo "❌ https://$domain/up did not answer. Recent logs:"
    "${compose[@]}" ps
    "${compose[@]}" logs --tail=40 app caddy
    return 1
}

main "$@"
