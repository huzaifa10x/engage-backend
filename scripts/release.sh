#!/usr/bin/env bash
# Release tooling for 10X Engage, run on a developer's computer from the engage-backend folder.
# It never deploys local files: the server deploys what is on GitHub, so push first.
#
#   npm run deploy:staging        deploy branch `staging` of all three repos to staging
#   npm run deploy:production     deploy branch `main` to production (only what staging has run; asks to confirm)
#   npm run release:promote       move what is on staging to main (fast-forward), ready for production
#   npm run release:sync          bring main back into staging (after a hotfix)
#   npm run release:status        what each environment runs, and its last releases
#   npm run rollback:staging      back to the previous successful staging release
#   npm run rollback:production   back to the previous successful production release (asks to confirm)
#   npm run release:init          one-time: create the `staging` branch in all three repos
#
# Settings (in .deploy.local next to package.json, never committed, or as environment variables):
#   ENGAGE_SSH=ubuntu@92.5.91.81    how to reach the server (any `ssh` destination, e.g. a Host alias)
#   WEB_DIR=../engage-web           local clone of engage-web
#   SITE_DIR=../engage-site         local clone of engage-site
set -euo pipefail

cd "$(dirname "$0")/.."
[[ -f .deploy.local ]] && source .deploy.local
WEB_DIR="${WEB_DIR:-../engage-web}"
SITE_DIR="${SITE_DIR:-../engage-site}"

say() { printf '\n\033[1;34m▸ %s\033[0m\n' "$*"; }
ok() { printf '  \033[32m✓\033[0m %s\n' "$*"; }
die() { printf '\n\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }
confirm() { # confirm "word" "question"
    local answer
    [[ "${YES:-}" == "$1" ]] && return 0 # non-interactive: YES=production npm run deploy:production
    read -rp "$2 Type \"$1\" to continue: " answer
    [[ "$answer" == "$1" ]] || die "Cancelled. Nothing was changed."
}

# repo name → local folder; the website is optional.
repos=("engage-backend:.")
[[ -d "$WEB_DIR/.git" ]] && repos+=("engage-web:$WEB_DIR") || die "engage-web not found at $WEB_DIR (set WEB_DIR in .deploy.local)."
[[ -d "$SITE_DIR/.git" ]] && repos+=("engage-site:$SITE_DIR")
server_dir() { [[ "$1" == production ]] && echo /opt/engage || echo /opt/engage-staging; }
short() { case "$1" in engage-backend) echo backend ;; engage-web) echo web ;; engage-site) echo site ;; esac; }

need_ssh() { [[ -n "${ENGAGE_SSH:-}" ]] || die "Set ENGAGE_SSH (e.g. ENGAGE_SSH=ubuntu@92.5.91.81) in .deploy.local."; }
remote() { ssh -o BatchMode=yes -o ConnectTimeout=15 "$ENGAGE_SSH" "$@"; }

fetch_all() {
    local entry name dir
    for entry in "${repos[@]}"; do
        name="${entry%%:*}" dir="${entry#*:}"
        git -C "$dir" fetch --quiet --prune --tags origin || die "Could not reach GitHub for $name."
    done
}

has_branch() { git -C "$1" show-ref --verify --quiet "refs/remotes/origin/$2"; }

# Refuse when the developer's own copy of the branch has commits that are not on GitHub:
# they would expect them to be deployed, and they would not be.
check_pushed() {
    local branch="$1" entry name dir ahead
    for entry in "${repos[@]}"; do
        name="${entry%%:*}" dir="${entry#*:}"
        has_branch "$dir" "$branch" || die "$name has no \`$branch\` branch on GitHub. Run: npm run release:init"
        if git -C "$dir" show-ref --verify --quiet "refs/heads/$branch"; then
            ahead="$(git -C "$dir" rev-list --count "origin/$branch..$branch")"
            [[ "$ahead" -eq 0 ]] || die "$name: your local \`$branch\` has $ahead commit(s) that are not pushed. Push them first (they would not be deployed)."
        fi
    done
}

deploy() {
    local env="$1" hotfix="${2:-}" branch entry name dir deployed
    [[ "$env" == staging || "$env" == production ]] || die "Use: deploy staging | deploy production"
    branch=$([[ "$env" == production ]] && echo main || echo staging)
    need_ssh
    say "Checking what will be deployed to $env (branch $branch)"
    fetch_all
    check_pushed "$branch"

    if [[ "$env" == production ]]; then
        local blocked=()
        for entry in "${repos[@]}"; do
            name="${entry%%:*}" dir="${entry#*:}"
            # What staging runs right now, according to the server.
            deployed="$(remote "git -C /opt/engage-staging/$(short "$name") rev-parse HEAD 2>/dev/null" || true)"
            if [[ -z "$deployed" ]] || ! git -C "$dir" merge-base --is-ancestor "origin/main" "$deployed" 2>/dev/null; then
                blocked+=("$name")
            fi
        done
        if [[ ${#blocked[@]} -gt 0 && "$hotfix" != --hotfix ]]; then
            die "Not everything on main has run on staging yet: ${blocked[*]}.
   Deploy it to staging and test it first (npm run release:promote moves staging → main),
   or, for an emergency fix only: npm run deploy:production -- --hotfix"
        fi
        [[ ${#blocked[@]} -gt 0 ]] && printf '  \033[33m!\033[0m HOTFIX: %s has commits that have not run on staging.\n' "${blocked[*]}"

        say "Changes going live"
        for entry in "${repos[@]}"; do
            name="${entry%%:*}" dir="${entry#*:}"
            deployed="$(remote "git -C /opt/engage/$(short "$name") rev-parse HEAD 2>/dev/null" || true)"
            printf '  %s\n' "$name"
            if [[ -n "$deployed" ]] && git -C "$dir" cat-file -e "$deployed" 2>/dev/null; then
                git -C "$dir" log --oneline --no-decorate "$deployed..origin/main" | sed 's/^/      /' | head -40
                [[ -n "$(git -C "$dir" log --oneline "$deployed..origin/main")" ]] || echo "      (no changes)"
            else
                git -C "$dir" log --oneline --no-decorate -5 origin/main | sed 's/^/      /'
            fi
        done
        confirm production "This deploys to PRODUCTION (live customers)."
        [[ "$hotfix" == --hotfix && ${#blocked[@]} -gt 0 ]] && confirm hotfix "These commits skip staging."
    fi

    say "Deploying $env on the server"
    local actor
    actor="$(git config user.name || whoami)"
    remote "DEPLOY_ACTOR=$(printf %q "$actor") bash $(server_dir "$env")/backend/deploy/deploy.sh --env $env all" \
        || die "The $env deployment FAILED (see above). The server keeps the last good containers for anything not yet restarted.
   To go back to the previous release: npm run rollback:$env"

    if [[ "$env" == production ]]; then
        local tag
        tag="prod-$(date -u +%Y%m%d-%H%M%S)"
        for entry in "${repos[@]}"; do
            name="${entry%%:*}" dir="${entry#*:}"
            git -C "$dir" tag -a "$tag" origin/main -m "Production release $tag by $actor" 2>/dev/null \
                && git -C "$dir" push --quiet origin "$tag" && ok "$name tagged $tag" || true
        done
    fi
    say "✅ $env deployed and healthy"
}

# staging → main, fast-forward only: main becomes exactly what staging tested. If main has commits
# staging does not (a hotfix that was not synced), this stops instead of dropping them.
promote() {
    say "Promoting staging → main"
    fetch_all
    local entry name dir
    for entry in "${repos[@]}"; do
        name="${entry%%:*}" dir="${entry#*:}"
        has_branch "$dir" staging || die "$name has no staging branch. Run: npm run release:init"
        if ! git -C "$dir" merge-base --is-ancestor origin/main origin/staging; then
            die "$name: main has commits that staging does not (a hotfix?). Run npm run release:sync first, test on staging, then promote."
        fi
    done
    for entry in "${repos[@]}"; do
        name="${entry%%:*}" dir="${entry#*:}"
        printf '  %s:\n' "$name"
        git -C "$dir" log --oneline --no-decorate origin/main..origin/staging | sed 's/^/      /' | head -40
    done
    confirm promote "main will be moved to what is on staging now (nothing is deployed yet)."
    for entry in "${repos[@]}"; do
        name="${entry%%:*}" dir="${entry#*:}"
        git -C "$dir" push --quiet origin origin/staging:refs/heads/main && ok "$name: main = staging ($(git -C "$dir" rev-parse --short origin/staging))"
    done
    echo
    echo "Next: npm run deploy:production"
}

# main → staging (merge), so a hotfix made on main is never lost when staging is promoted later.
sync() {
    say "Bringing main into staging"
    fetch_all
    local entry name dir tmp
    for entry in "${repos[@]}"; do
        name="${entry%%:*}" dir="${entry#*:}"
        if git -C "$dir" merge-base --is-ancestor origin/main origin/staging; then
            ok "$name: staging already contains main"
            continue
        fi
        tmp="$(mktemp -d)"
        git -C "$dir" worktree add --quiet --detach "$tmp" origin/staging
        if git -C "$tmp" merge --no-edit --quiet -m "Merge main into staging (hotfix sync)" origin/main; then
            git -C "$tmp" push --quiet origin HEAD:refs/heads/staging && ok "$name: main merged into staging"
        else
            git -C "$tmp" merge --abort || true
            git -C "$dir" worktree remove --force "$tmp"
            die "$name: main and staging conflict. Merge them by hand: git checkout staging && git merge origin/main"
        fi
        git -C "$dir" worktree remove --force "$tmp"
    done
    echo
    echo "Next: npm run deploy:staging (and test the hotfix there too)"
}

init() {
    say "Creating the staging branch from main"
    fetch_all
    local entry name dir
    for entry in "${repos[@]}"; do
        name="${entry%%:*}" dir="${entry#*:}"
        if has_branch "$dir" staging; then
            ok "$name: staging already exists"
        else
            git -C "$dir" push --quiet origin origin/main:refs/heads/staging && ok "$name: staging created from main"
        fi
    done
    echo
    echo "Then protect the branches on GitHub (docs/environments.md → GitHub settings)."
}

status() {
    need_ssh
    for env in production staging; do
        say "$env"
        remote "test -d $(server_dir "$env")/backend && bash $(server_dir "$env")/backend/deploy/deploy.sh status || echo '  not set up yet'"
    done
}

rollback() {
    local env="$1"
    need_ssh
    [[ "$env" == staging || "$env" == production ]] || die "Use: rollback staging | rollback production"
    [[ "$env" == production ]] && confirm production "This rolls PRODUCTION back to its previous release."
    remote "DEPLOY_ACTOR=$(printf %q "$(git config user.name || whoami)") bash $(server_dir "$env")/backend/deploy/deploy.sh --env $env rollback" \
        || die "The rollback failed (see above)."
    echo
    echo "The branch still has the faulty commit: fix it (or git revert it) on the branch, or the next deploy brings it back."
}

case "${1:-}" in
    deploy) deploy "${2:-}" "${3:-}" ;;
    promote) promote ;;
    sync) sync ;;
    init) init ;;
    status) status ;;
    rollback) rollback "${2:-}" ;;
    *) sed -n '2,20p' "$0"; exit 1 ;;
esac
