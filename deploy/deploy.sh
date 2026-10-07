#!/usr/bin/env bash
# Deploys the latest `main` of one or both repos on the server.
#   deploy.sh backend   pull + build backend, migrate, restart backend services
#   deploy.sh web       pull + build the Next.js client
#   deploy.sh site      pull + build the public marketing website (repo engage-site in /opt/engage/site)
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
    # The website is optional: `all` includes it only once its repository has been cloned.
    local with_site=0
    if [[ "$target" == site || ( "$target" == all && -d "$root/site/.git" ) ]]; then
        with_site=1
        update "$root/site"
    fi

    if [[ "$target" == backend || "$target" == all ]]; then
        log "Building backend image"
        "${compose[@]}" build app
        "${compose[@]}" up -d --wait postgres valkey   # waits until both healthchecks pass
        log "Running migrations"
        "${compose[@]}" run --rm --no-deps app php artisan migrate --force
        # Reference data (system roles, plan catalog). Idempotent; demo accounts are local-only.
        "${compose[@]}" run --rm --no-deps app php artisan db:seed --force
        "${compose[@]}" run --rm --no-deps app php artisan engage:partitions
        log "Starting backend services"
        # Always recreate: .env is a mounted file, so editing it does not change the container
        # definition, and Laravel only re-reads it (php artisan optimize) when a container starts.
        "${compose[@]}" up -d --force-recreate app horizon scheduler reverb
    fi

    if [[ "$target" == web || "$target" == all ]]; then
        log "Building web image"
        "${compose[@]}" build web
        "${compose[@]}" up -d web
    fi

    if [[ "$with_site" == 1 ]]; then
        log "Building website image"
        "${compose[@]}" build site
        "${compose[@]}" up -d site
        # The image is built without access to the API, so its pricing pages start empty and
        # refresh from the API within a minute. Do that refresh now instead of on a visitor.
        ( sleep 65
          for page in / /pricing; do
              "${compose[@]}" exec -T site wget -q -O /dev/null "http://127.0.0.1:3100$page" || true
              sleep 3
              "${compose[@]}" exec -T site wget -q -O /dev/null "http://127.0.0.1:3100$page" || true
          done ) 9>&- >/dev/null 2>&1 &   # 9>&-: do not hold the deploy lock while waiting
    fi

    if [[ "$target" == restart ]]; then
        log "Restarting backend services"
        "${compose[@]}" up -d --force-recreate app horizon scheduler reverb
    fi

    "${compose[@]}" up -d caddy

    log "Health check"
    local domain admin_domain
    domain="$(grep -E '^DOMAIN=' "$root/backend/deploy/.env" | cut -d= -f2-)"
    admin_domain="$(grep -E '^ADMIN_DOMAIN=' "$root/backend/deploy/.env" | cut -d= -f2-)"

    # 1. Laravel answers inside Docker. A freshly started container first builds its caches
    #    (php artisan optimize), so allow up to 2 minutes before calling it a failure.
    local laravel_up=0
    for _ in $(seq 1 24); do
        if "${compose[@]}" exec -T app php -r "exit(@file_get_contents('http://127.0.0.1:8000/up') === false ? 1 : 0);" 2>/dev/null; then
            laravel_up=1
            break
        fi
        sleep 5
    done
    if [[ $laravel_up -eq 0 ]]; then
        echo "❌ Laravel is not answering after 2 minutes. Recent logs:"
        "${compose[@]}" logs --tail=60 app
        return 1
    fi
    echo "  ✓ Laravel is up"

    # 2. Full path through Caddy + HTTPS certificate, resolved to this machine (an Oracle VM
    #    usually cannot reach its own public IP, so we do not go out to the internet and back).
    for _ in $(seq 1 24); do
        if curl -fsS -o /dev/null --max-time 5 --resolve "$domain:443:127.0.0.1" "https://$domain/up" \
            && { [[ -z "$admin_domain" ]] || curl -fsS -o /dev/null --max-time 5 --resolve "$admin_domain:443:127.0.0.1" "https://$admin_domain/up"; }; then
            echo "  ✓ HTTPS certificates issued and proxy working"
            log "✅ Live: https://$domain${admin_domain:+  ·  Super Admin: https://$admin_domain}"
            docker image prune -f >/dev/null
            return 0
        fi
        sleep 5
    done

    echo "❌ HTTPS is not ready yet. Laravel runs, but no certificate could be issued for $domain${admin_domain:+ / $admin_domain}."
    echo "   Almost always: ports 80/443 are not open in the Oracle Security List, or DNS does not"
    echo "   point to this server. Certificate errors from Caddy:"
    "${compose[@]}" logs --tail=200 caddy 2>&1 | grep -iE "error|challenge|acme|timeout|refused" | tail -15 || true
    return 1
}

main "$@"
