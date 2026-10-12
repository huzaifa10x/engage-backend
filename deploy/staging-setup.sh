#!/usr/bin/env bash
# One-time: adds the STAGING environment next to production on the same server, without touching
# production's containers, database or data. Run as the `ubuntu` user, from production's checkout:
#
#   bash /opt/engage/backend/deploy/staging-setup.sh
#
# Before running it:
#   1. Production has been deployed with this version (its Caddy joins the "engage-edge" network).
#   2. The `staging` branch exists on GitHub in engage-backend, engage-web and engage-site
#      (`npm run release -- init` on your computer creates it from main).
#   3. DNS A records for st-app, st-engage-admin and st-engage point to this server.
#
# What it does:
#   • clones the three repositories into /opt/engage-staging on their `staging` branch
#   • creates staging's own secrets (deploy/init-env.sh --env staging), new random keys
#   • tells production's Caddy about the staging domains (STAGING_DOMAINS) and reloads it
#   • adds a nightly staging backup and a `dcs` shortcut, then runs the first staging deployment
set -euo pipefail

PROD=/opt/engage
STAGING=/opt/engage-staging
say() { printf '\n\033[1;34m▸ %s\033[0m\n' "$*"; }

[[ -d "$PROD/backend/.git" ]] || { echo "Production checkout not found in $PROD."; exit 1; }
if ! docker inspect engage-caddy-1 --format '{{json .NetworkSettings.Networks}}' 2>/dev/null | grep -q engage-edge; then
    echo "Production's proxy is not on the engage-edge network yet."
    echo "Deploy production with this version first:  bash $PROD/backend/deploy/deploy.sh all"
    exit 1
fi

say "Checking out the staging branch into $STAGING"
sudo mkdir -p "$STAGING"
sudo chown "$USER":"$USER" "$STAGING"
for repo in backend web site; do
    [[ -d "$PROD/$repo/.git" ]] || continue
    url="$(git -C "$PROD/$repo" remote get-url origin)"
    if [[ ! -d "$STAGING/$repo/.git" ]]; then
        if ! git ls-remote --exit-code --heads "$url" staging >/dev/null 2>&1; then
            echo "  ✗ $repo has no \`staging\` branch on GitHub yet. Create it first (npm run release -- init)."
            exit 1
        fi
        git clone --quiet --branch staging "$url" "$STAGING/$repo"
    fi
    echo "  $repo: $(git -C "$STAGING/$repo" log -1 --format='%h %s')"
done

say "Staging secrets (new values, nothing copied from production)"
bash "$STAGING/backend/deploy/init-env.sh" --env staging

say "Production's proxy: adding the staging domains"
staging_env="$STAGING/backend/deploy/.env"
prod_env="$PROD/backend/deploy/.env"
value() { { grep -E "^$2=" "$1" || true; } | tail -1 | cut -d= -f2-; }
domains="$(value "$staging_env" DOMAIN), $(value "$staging_env" ADMIN_DOMAIN)"
[[ -n "$(value "$staging_env" SITE_DOMAIN)" ]] && domains+=", $(value "$staging_env" SITE_DOMAIN)"
if grep -q '^STAGING_DOMAINS=' "$prod_env"; then
    sed -i "s|^STAGING_DOMAINS=.*|STAGING_DOMAINS=\"$domains\"|" "$prod_env"
else
    printf 'STAGING_DOMAINS="%s"\n' "$domains" >> "$prod_env"
fi
docker compose --project-directory "$PROD/backend/deploy" -p engage -f "$PROD/backend/deploy/docker-compose.prod.yml" up -d caddy
echo "  STAGING_DOMAINS=$domains"

say "Nightly staging backup (03:45, kept 3 days) and the 'dcs' shortcut"
mkdir -p "$STAGING/backups"
{ crontab -l 2>/dev/null | grep -v 'backup.sh staging' || true; echo "45 3 * * * bash $PROD/backend/deploy/backup.sh staging >> $STAGING/backups/backup.log 2>&1"; } | crontab -
grep -q "alias dcs=" ~/.bashrc || echo "alias dcs='docker compose --project-directory $STAGING/backend/deploy -p engage-staging -f $STAGING/backend/deploy/docker-compose.prod.yml -f $STAGING/backend/deploy/docker-compose.staging.yml'" >> ~/.bashrc

cat <<TXT

Next: fill in staging's own third-party credentials in $STAGING/backend/.env
(Meta test app, Stripe TEST keys, mail sandbox, Shopify dev app — docs/environments.md → Secrets),
then deploy staging:

    bash $STAGING/backend/deploy/deploy.sh --env staging all

and create the first Super Admin for staging:

    dcs exec app php artisan engage:admin:create
TXT
