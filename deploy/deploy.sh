#!/usr/bin/env bash
# Deploys one environment on the server. Each environment has its own checkout and its own branch:
#
#   production   /opt/engage/{backend,web,site}           branch main      project "engage"
#   staging      /opt/engage-staging/{backend,web,site}   branch staging   project "engage-staging"
#
# The environment is decided by where this script lives, so the staging checkout can only ever
# deploy staging. --env is a second safety check: it must agree with the location.
#
#   deploy.sh [--env production|staging] backend    pull + build backend, back up, migrate, restart
#   deploy.sh [--env …] web                          pull + build the client portal (engage-web)
#   deploy.sh [--env …] site                         pull + build the marketing website (engage-site)
#   deploy.sh [--env …] all                          everything above
#   deploy.sh [--env …] restart                      restart backend containers (after editing .env)
#   deploy.sh [--env …] check                        health checks and smoke tests only
#   deploy.sh [--env …] status                       what is deployed, and the last releases
#   deploy.sh [--env …] rollback                     back to the previous successful release
#
# Called by GitHub Actions and by `npm run deploy:<env>` (scripts/release.sh). Safe to run by hand.
# A deployment is reported successful only after the health checks pass; every attempt is written
# to <root>/releases.log. Secrets are never printed.
set -euo pipefail

# Everything runs inside main(): bash has parsed the whole script before `git reset`
# replaces this file on disk.
main() {
    local requested_env="" target=""
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --env) requested_env="${2:-}"; shift 2 ;;
            --env=*) requested_env="${1#--env=}"; shift ;;
            -h | --help) sed -n '2,22p' "$0"; return 0 ;;
            *) target="$1"; shift ;;
        esac
    done
    target="${target:-all}"

    # ── Which environment is this? ──────────────────────────────────────────────────────────
    local here root env
    here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    root="$(cd "$here/../.." && pwd)"
    case "$(basename "$root")" in
        *-staging) env=staging ;;
        *) env=production ;;
    esac
    if [[ -n "$requested_env" && "$requested_env" != "$env" ]]; then
        fail_early "This checkout ($root) is the $env environment, but --env $requested_env was given. Nothing was changed."
        return 1
    fi

    local branch project files prod_root=/opt/engage
    if [[ "$env" == production ]]; then
        branch=main project=engage files=(-f "$here/docker-compose.prod.yml")
    else
        branch=staging project=engage-staging files=(-f "$here/docker-compose.prod.yml" -f "$here/docker-compose.staging.yml")
    fi
    local compose=(docker compose --project-directory "$here" -p "$project" "${files[@]}")
    local laravel_env="$root/backend/.env" compose_env="$here/.env" releases="$root/releases.log"
    local backend_services=(app horizon scheduler reverb)

    log() { printf '\n\033[1;34m▸ %s\033[0m\n' "$*"; }
    ok() { printf '  \033[32m✓\033[0m %s\n' "$*"; }
    warn() { printf '  \033[33m!\033[0m %s\n' "$*"; }
    value() { # value FILE KEY → the value of KEY in a dotenv file (quotes removed), empty if absent
        [[ -f "$1" ]] || return 0
        { grep -E "^$2=" "$1" || true; } | tail -1 | cut -d= -f2- | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/"
    }
    sha() { git -C "$1" rev-parse --short=12 HEAD 2>/dev/null || echo "-"; }
    has_site() { [[ -d "$root/site/.git" ]]; }
    release_line() { # release_line STATUS ACTION
        printf '%s env=%s action=%s status=%s backend=%s web=%s site=%s by=%s\n' \
            "$(date -u +%FT%TZ)" "$env" "$2" "$1" "$(sha "$root/backend")" "$(sha "$root/web")" "$(sha "$root/site")" \
            "${DEPLOY_ACTOR:-${SUDO_USER:-$(id -un)}}" >> "$releases"
    }
    notify() { # optional: DEPLOY_NOTIFY_WEBHOOK in deploy/.env (Slack/Discord/Teams-compatible JSON {"text": …})
        local hook
        hook="$(value "$compose_env" DEPLOY_NOTIFY_WEBHOOK)"
        [[ -n "$hook" ]] || return 0
        curl -fsS -m 10 -H 'Content-Type: application/json' -d "{\"text\": \"$1\"}" "$hook" >/dev/null 2>&1 || true
    }

    # ── Read-only commands ──────────────────────────────────────────────────────────────────
    if [[ "$target" == status ]]; then
        printf 'Environment: %s (%s, branch %s)\n' "$env" "$root" "$branch"
        for repo in backend web site; do
            [[ -d "$root/$repo/.git" ]] && printf '  %-8s %s\n' "$repo" "$(git -C "$root/$repo" log -1 --format='%h %s (%an, %cr)')"
        done
        echo "Last releases:"
        tail -n 10 "$releases" 2>/dev/null | sed 's/^/  /' || echo "  (none yet)"
        return 0
    fi

    # ── Safety checks before anything changes ───────────────────────────────────────────────
    preflight || return 1

    exec 9>"/tmp/engage-deploy-$env.lock"
    if ! flock -w 1800 9; then # one deploy per environment at a time
        fail_early "Another $env deployment is still running."
        return 1
    fi

    local action="$target" rollback_to=""
    trap 'on_failure' ERR
    set -E

    if [[ "$target" == rollback ]]; then
        rollback_to="$(previous_release)" || { fail_early "There is no earlier successful $env release to go back to."; trap - ERR; return 1; }
        log "Rolling back $env to the release of ${rollback_to%% *}"
        local repo want
        for repo in backend web site; do
            want="$(sed -n "s/.* $repo=\([^ ]*\).*/\1/p" <<<"$rollback_to")"
            [[ -d "$root/$repo/.git" && -n "$want" && "$want" != "-" ]] || continue
            if [[ "$repo" == backend ]] && [[ -n "$(git -C "$root/backend" diff --name-only "$want" HEAD -- database/migrations)" ]]; then
                warn "Migrations changed since that release. They stay applied: the database is not rolled back."
                warn "If the schema itself must go back, restore the pre-deploy backup (docs/environments.md → Rollback)."
            fi
            git -C "$root/$repo" fetch --quiet origin "$branch"
            git -C "$root/$repo" reset --hard --quiet "$want"
            ok "$repo → $(git -C "$root/$repo" log -1 --format='%h %s')"
        done
        target=all
    else
        case "$target" in
            backend | web | site | all | restart | check) ;;
            *) fail_early "Unknown command \"$target\". Use backend, web, site, all, restart, check, status or rollback."; trap - ERR; return 1 ;;
        esac
        case "$target" in backend | all) update "$root/backend" ;; esac
        case "$target" in web | all) update "$root/web" ;; esac
        if [[ "$target" == site ]] || { [[ "$target" == all ]] && has_site; }; then update "$root/site"; fi
    fi

    docker network inspect engage-edge >/dev/null 2>&1 || docker network create engage-edge >/dev/null

    if [[ "$target" == backend || "$target" == all ]]; then
        log "Building backend image"
        "${compose[@]}" build app
        "${compose[@]}" up -d --wait postgres valkey   # waits until both healthchecks pass
        backup_before_migrations
        log "Running migrations ($env database)"
        "${compose[@]}" run --rm --no-deps app php artisan migrate --force
        # Reference data (system roles, plan catalog). Idempotent; demo accounts are local-only.
        "${compose[@]}" run --rm --no-deps app php artisan db:seed --force
        "${compose[@]}" run --rm --no-deps app php artisan engage:partitions
        log "Starting backend services"
        # Always recreate: .env is a mounted file, so editing it does not change the container
        # definition, and Laravel only re-reads it (php artisan optimize) when a container starts.
        "${compose[@]}" up -d --force-recreate "${backend_services[@]}"
    fi

    if [[ "$target" == web || "$target" == all ]]; then
        log "Building web image"
        "${compose[@]}" build web
        "${compose[@]}" up -d web
    fi

    if [[ "$target" == site ]] || { [[ "$target" == all ]] && has_site; }; then
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
        "${compose[@]}" up -d --force-recreate "${backend_services[@]}"
    fi

    if [[ "$env" == production ]]; then
        refresh_proxy caddy "$here/Caddyfile"
    else
        refresh_proxy gateway "$here/Caddyfile.staging"
    fi

    health_checks
    trap - ERR

    if [[ "$action" == check ]]; then
        log "✅ $env passes every check"
        return 0
    fi
    release_line ok "$action"
    log "✅ $env is live: https://$(value "$compose_env" DOMAIN)"
    notify "✅ 10X Engage $env: $action succeeded (backend $(sha "$root/backend"), web $(sha "$root/web"), site $(sha "$root/site"))"
    docker image prune -f >/dev/null 2>&1 || true
    return 0
}

fail_early() { printf '\n\033[1;31m✗ %s\033[0m\n' "$*" >&2; }

# Runs on any failing command after the checks: the attempt is recorded and reported, and the
# deployment stops there. Containers that were not recreated yet keep running the old version.
on_failure() {
    local code=$?
    trap - ERR
    printf '\n\033[1;31m✗ The %s deployment stopped: a step failed (exit %s). Nothing after it was run.\033[0m\n' "$env" "$code" >&2
    printf '  See the output above. To return to the previous release: deploy.sh --env %s rollback\n' "$env" >&2
    release_line failed "$action" || true
    notify "❌ 10X Engage $env: $action FAILED. Check the deployment log." || true
    exit "$code"
}

# Starts the edge proxy, and restarts it when its Caddyfile changed: the file is mounted into the
# container, and a running Caddy keeps serving the version it started with.
refresh_proxy() {
    local service="$1" file="$2" running
    running="$("${compose[@]}" exec -T "$service" sha256sum /etc/caddy/Caddyfile 2>/dev/null | cut -d' ' -f1 || true)"
    if [[ -n "$running" && "$running" != "$(sha256sum "$file" | cut -d' ' -f1)" ]]; then
        log "Proxy configuration changed: restarting $service"
        "${compose[@]}" up -d --force-recreate "$service"
    else
        "${compose[@]}" up -d "$service"
    fi
}

update() {
    log "Updating $1 from origin/$branch"
    if ! git -C "$1" fetch --quiet origin "$branch"; then
        fail_early "Branch \"$branch\" does not exist on GitHub for $(basename "$1"). Create it first (docs/environments.md)."
        return 1
    fi
    git -C "$1" checkout --quiet -B "$branch" "origin/$branch"
    git -C "$1" reset --hard --quiet "origin/$branch"
    git -C "$1" log -1 --format='  %h %s (%an)'
}

# Stops the deployment before it touches anything if this environment is not set up as itself.
preflight() {
    local problems=()
    [[ -f "$laravel_env" ]] || problems+=("$laravel_env is missing (run deploy/init-env.sh --env $env).")
    [[ -f "$compose_env" ]] || problems+=("$compose_env is missing (run deploy/init-env.sh --env $env).")
    if [[ ${#problems[@]} -eq 0 ]]; then
        local app_env app_url domain
        app_env="$(value "$laravel_env" APP_ENV)"
        app_url="$(value "$laravel_env" APP_URL)"
        domain="$(value "$compose_env" DOMAIN)"
        [[ "$app_env" == "$env" ]] || problems+=("APP_ENV in $laravel_env is \"$app_env\", expected \"$env\".")
        [[ "$app_url" == "https://$domain" ]] || problems+=("APP_URL ($app_url) does not match this environment's domain ($domain).")
        [[ "$(value "$laravel_env" DB_HOST)" == postgres ]] || problems+=("DB_HOST must be \"postgres\" (this environment's own database container).")
        [[ "$(value "$laravel_env" REDIS_HOST)" == valkey ]] || problems+=("REDIS_HOST must be \"valkey\" (this environment's own Valkey container).")
        [[ "$(value "$laravel_env" APP_DEBUG)" == true ]] && problems+=("APP_DEBUG=true would show error details to visitors. Set it to false.")

        if [[ "$env" == staging ]]; then
            [[ "$(value "$compose_env" COMPOSE_PROJECT_NAME)" == engage-staging ]] || problems+=("COMPOSE_PROJECT_NAME=engage-staging is missing from $compose_env.")
            [[ "$(value "$compose_env" IMAGE_PREFIX)" == engage-staging ]] || problems+=("IMAGE_PREFIX=engage-staging is missing from $compose_env (images would overwrite production's).")
            [[ "$(value "$laravel_env" STRIPE_SECRET)" == sk_live_* ]] && problems+=("Staging has a LIVE Stripe key. Use test keys (sk_test_…) on staging.")
            [[ "$(value "$laravel_env" STRIPE_KEY)" == pk_live_* ]] && problems+=("Staging has a LIVE Stripe publishable key. Use pk_test_… on staging.")
            # Credentials that must never be shared with production (a copied .env is the usual cause).
            local prod_env="$prod_root/backend/.env" key mine theirs
            if [[ -f "$prod_env" ]]; then
                for key in APP_KEY ENGAGE_SECRETS_KEYS DB_PASSWORD REVERB_APP_KEY REVERB_APP_SECRET STRIPE_SECRET STRIPE_WEBHOOK_SECRET SHOPIFY_CLIENT_SECRET; do
                    mine="$(value "$laravel_env" "$key")"
                    theirs="$(value "$prod_env" "$key")"
                    if [[ -n "$mine" && "$mine" == "$theirs" ]]; then
                        problems+=("$key is the same as production's. Staging must have its own (docs/environments.md → Secrets).")
                    fi
                done
                # Allowed, but worth knowing: one Meta app can only send its webhooks to one environment.
                for key in META_APP_SECRET MAIL_PASSWORD; do
                    mine="$(value "$laravel_env" "$key")"
                    [[ -n "$mine" && "$mine" == "$(value "$prod_env" "$key")" ]] && warn "$key is shared with production (docs/environments.md → Secrets explains why a separate one is better)."
                done
                [[ "$(value "$prod_env" APP_URL)" == "$app_url" ]] && problems+=("Staging's APP_URL is production's URL.")
            fi
        else
            [[ "$(value "$compose_env" IMAGE_PREFIX)" =~ ^(|engage)$ ]] || problems+=("IMAGE_PREFIX in production's $compose_env must be empty or \"engage\".")
            [[ "$domain" == st-* ]] && problems+=("Production's DOMAIN ($domain) is a staging domain.")
        fi
    fi
    if [[ ${#problems[@]} -gt 0 ]]; then
        fail_early "The $env environment is not configured safely. Nothing was deployed:"
        printf '   • %s\n' "${problems[@]}" >&2
        return 1
    fi
    ok "$env configuration checked ($(value "$compose_env" DOMAIN), branch $branch, project $project)"
}

# Production: a database backup right before migrations that change something, so a release can
# be undone even if a migration goes wrong. Kept: the last 10. Staging: not needed.
backup_before_migrations() {
    [[ "$env" == production ]] || return 0
    local pending
    pending="$("${compose[@]}" run --rm --no-deps app php artisan migrate:status --pending 2>/dev/null | grep -c Pending || true)"
    if [[ "${pending:-0}" -eq 0 ]]; then
        ok "No pending migrations: no pre-deploy backup needed"
        return 0
    fi
    local dir=/opt/engage/backups stamp file
    stamp="$(date -u +%Y%m%d-%H%M%S)"
    file="$dir/pre-deploy-$stamp-$(sha "$root/backend").dump"
    mkdir -p "$dir"
    log "Backing up the production database before $pending pending migration(s)"
    "${compose[@]}" exec -T postgres pg_dump -U postgres --format=custom engage > "$file"
    [[ -s "$file" ]] || { fail_early "The backup is empty. Stopping before migrations."; return 1; }
    ok "Backup: $file ($(du -h "$file" | cut -f1))"
    ls -1t "$dir"/pre-deploy-*.dump 2>/dev/null | tail -n +11 | xargs -r rm -f
}

# The successful release that came before the one now running. Rollbacks themselves are not
# releases, so rolling back twice goes two releases back (never "forward" again).
previous_release() {
    [[ -f "$releases" ]] || return 1
    local now line found=0 first_other=""
    now="backend=$(sha "$root/backend") web=$(sha "$root/web") site=$(sha "$root/site")"
    while IFS= read -r line; do
        [[ "$line" == *"status=ok"* && "$line" != *"action=rollback"* ]] || continue
        if [[ "$line" == *"$now"* ]]; then
            found=1
            continue
        fi
        if [[ $found -eq 1 ]]; then
            printf '%s\n' "$line"
            return 0
        fi
        [[ -z "$first_other" ]] && first_other="$line"
    done < <(tac "$releases")
    # The running commits were never released through this script (e.g. changed by hand): use the latest release.
    [[ $found -eq 0 && -n "$first_other" ]] && { printf '%s\n' "$first_other"; return 0; }
    return 1
}

health_checks() {
    log "Health checks ($env)"
    local domain admin_domain site_domain
    domain="$(value "$compose_env" DOMAIN)"
    admin_domain="$(value "$compose_env" ADMIN_DOMAIN)"
    site_domain="$(value "$compose_env" SITE_DOMAIN)"

    # 1. Laravel answers inside Docker. A freshly started container first builds its caches
    #    (php artisan optimize), so allow up to 2 minutes before calling it a failure.
    local up=0
    for _ in $(seq 1 24); do
        if "${compose[@]}" exec -T app php -r "exit(@file_get_contents('http://127.0.0.1:8000/up') === false ? 1 : 0);" 2>/dev/null; then
            up=1
            break
        fi
        sleep 5
    done
    if [[ $up -eq 0 ]]; then
        echo "  ✗ Laravel is not answering after 2 minutes. Recent logs:"
        "${compose[@]}" logs --tail=60 app
        return 1
    fi
    ok "Laravel is up"

    # 2. Every public address, through the edge proxy and HTTPS, resolved to this machine (an Oracle
    #    VM usually cannot reach its own public IP). Certificates can take a minute on first use.
    local checks=(
        "$domain|/up|200|API health"
        "$domain|/api/v1/public/plans|200|API answers from the database"
        "$domain|/login|200,307,308|Client portal"
    )
    [[ -n "$admin_domain" ]] && checks+=("$admin_domain|/admin/login|200|Super Admin")
    [[ -n "$site_domain" ]] && has_site && checks+=("$site_domain|/|200,307,308|Website")
    local check host path want label got
    for check in "${checks[@]}"; do
        IFS='|' read -r host path want label <<<"$check"
        got=000
        for _ in $(seq 1 24); do
            got="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 10 --resolve "$host:443:127.0.0.1" "https://$host$path" 2>/dev/null || true)"
            [[ ",$want," == *",$got,"* ]] && break
            sleep 5
        done
        if [[ ",$want," != *",$got,"* ]]; then
            echo "  ✗ $label: https://$host$path answered $got (expected $want)."
            if [[ "$got" == 000 ]]; then
                echo "    No HTTPS connection. Usually: DNS for $host does not point to this server yet, or ports"
                echo "    80/443 are closed. Certificate errors from the edge proxy:"
                docker compose --project-directory "$prod_root/backend/deploy" -p engage -f "$prod_root/backend/deploy/docker-compose.prod.yml" logs --tail=200 caddy 2>&1 \
                    | grep -iE "error|challenge|acme|timeout|refused" | grep -i "${host%%.*}" | tail -8 || true
            fi
            return 1
        fi
        ok "$label: https://$host$path"
    done

    # 3. Workers: Horizon must be running, or queued messages and webhooks would wait forever.
    if "${compose[@]}" exec -T horizon php artisan horizon:status 2>/dev/null | grep -qi running; then
        ok "Queue workers (Horizon) running"
    else
        sleep 15
        "${compose[@]}" exec -T horizon php artisan horizon:status 2>/dev/null | grep -qi running || { echo "  ✗ Horizon is not running."; "${compose[@]}" logs --tail=40 horizon; return 1; }
        ok "Queue workers (Horizon) running"
    fi
}

main "$@"
