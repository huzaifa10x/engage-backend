#!/usr/bin/env bash
# One-time per environment: creates the two secret files the server needs, with strong random
# values that are NEW for this environment (staging never reuses production's keys):
#   <root>/backend/.env          → Laravel
#   <root>/backend/deploy/.env   → docker compose (domains, DB passwords, Reverb key)
#
#   production:  bash /opt/engage/backend/deploy/init-env.sh
#   staging:     bash /opt/engage-staging/backend/deploy/init-env.sh --env staging
#
# The environment follows the checkout's location, like deploy.sh. Re-running never overwrites
# existing files. Third-party credentials (Meta, Stripe, mail, Shopify) are filled in afterwards;
# see docs/environments.md → Secrets checklist.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
LARAVEL_ENV="$ROOT/.env"
COMPOSE_ENV="$ROOT/deploy/.env"
case "$(basename "$(dirname "$ROOT")")" in
    *-staging) ENVIRONMENT=staging ;;
    *) ENVIRONMENT=production ;;
esac
if [[ "${1:-}" == "--env" && -n "${2:-}" && "$2" != "$ENVIRONMENT" ]]; then
    echo "This checkout ($ROOT) is the $ENVIRONMENT environment, not $2. Nothing was created."
    exit 1
fi

if [[ -f "$LARAVEL_ENV" || -f "$COMPOSE_ENV" ]]; then
    echo "Env files already exist — not overwriting:"
    ls -l "$LARAVEL_ENV" "$COMPOSE_ENV" 2>/dev/null || true
    exit 0
fi

ask() { # ask VAR "Question" default
    local answer
    read -rp "$2${3:+ [$3]}: " answer
    printf -v "$1" '%s' "${answer:-$3}"
}

if [[ "$ENVIRONMENT" == staging ]]; then
    echo "Creating STAGING configuration in $ROOT"
    ask DOMAIN "Staging client portal domain" "st-app.10xdigital.ae"
    ask ADMIN_DOMAIN "Staging Super Admin domain" "st-engage-admin.10xdigital.ae"
    ask SITE_DOMAIN "Staging website domain" "st-engage.10xdigital.ae"
    ask ACME_EMAIL "Email for HTTPS certificate notices" "info@10xdigital.ae"
    APP_NAME="10X Engage (Staging)"
    COEXISTENCE_ID=2159163541380544 # the staging Coexistence configuration
else
    echo "Creating PRODUCTION configuration in $ROOT"
    ask DOMAIN "Client portal domain" "app.10xdigital.ae"
    ask ADMIN_DOMAIN "Super Admin domain" "engage-admin.10xdigital.ae"
    ask SITE_DOMAIN "Website domain" "engage.10xdigital.ae"
    ask ACME_EMAIL "Email for HTTPS certificate notices" "info@10xdigital.ae"
    APP_NAME="10X Engage"
    COEXISTENCE_ID=1117494534308456 # the production Coexistence configuration
fi
[[ -n "$DOMAIN" && -n "$ADMIN_DOMAIN" && -n "$ACME_EMAIL" ]] || { echo "The domains and the email are required."; exit 1; }

rand() { openssl rand -hex "$1"; }
APP_KEY="base64:$(openssl rand -base64 32)"
SECRETS_KEY="k1:base64:$(openssl rand -base64 32)"
POSTGRES_PASSWORD="$(rand 24)"
ENGAGE_DB_PASSWORD="$(rand 24)"
REVERB_APP_KEY="$(rand 16)"
REVERB_APP_SECRET="$(rand 24)"
VERIFY_TOKEN="$(rand 20)"

umask 077

if [[ "$ENVIRONMENT" == staging ]]; then
    PROJECT_LINES="COMPOSE_PROJECT_NAME=engage-staging
IMAGE_PREFIX=engage-staging
POSTGRES_MAX_CONNECTIONS=60"
    # Own cookie and key names as well, on top of the separate hosts and Valkey.
    NAMES="SESSION_COOKIE=engage_staging_session
REDIS_PREFIX=engage_staging_
HORIZON_PREFIX=engage_staging_horizon:"
else
    PROJECT_LINES="COMPOSE_PROJECT_NAME=engage"
    NAMES=""
fi

cat > "$COMPOSE_ENV" <<ENV
$PROJECT_LINES
DOMAIN=$DOMAIN
ADMIN_DOMAIN=$ADMIN_DOMAIN
SITE_DOMAIN=$SITE_DOMAIN
ACME_EMAIL=$ACME_EMAIL
POSTGRES_PASSWORD=$POSTGRES_PASSWORD
ENGAGE_DB_PASSWORD=$ENGAGE_DB_PASSWORD
REVERB_APP_KEY=$REVERB_APP_KEY
# Optional: a Slack/Discord/Teams incoming-webhook URL; deploy results are posted there.
DEPLOY_NOTIFY_WEBHOOK=
ENV

cat > "$LARAVEL_ENV" <<ENV
APP_NAME="$APP_NAME"
APP_ENV=$ENVIRONMENT
APP_KEY=$APP_KEY
APP_DEBUG=false
APP_URL=https://$DOMAIN
APP_TIMEZONE=UTC
APP_LOCALE=en
APP_FALLBACK_LOCALE=en

FRONTEND_URL=https://$DOMAIN
ADMIN_DOMAIN=$ADMIN_DOMAIN
SANCTUM_STATEFUL_DOMAINS=$DOMAIN
# Host-only cookies (no Domain attribute): staging and production cookies never mix.
SESSION_DOMAIN=null
CORS_ALLOWED_ORIGINS=https://$DOMAIN

LOG_CHANNEL=stderr
LOG_LEVEL=info

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=engage
DB_USERNAME=engage
DB_PASSWORD=$ENGAGE_DB_PASSWORD

REDIS_CLIENT=phpredis
REDIS_HOST=valkey
REDIS_PORT=6379
REDIS_PASSWORD=null
$NAMES

CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=redis
BROADCAST_CONNECTION=reverb
FILESYSTEM_DISK=local

# Mail: emails are only logged until a provider is configured. Staging: use a sandbox inbox
# (Mailtrap, Resend test domain …), never the production sender account.
MAIL_MAILER=log
MAIL_FROM_ADDRESS=info@10xdigital.ae
MAIL_FROM_NAME="$APP_NAME"

# Meta — from this environment's own Meta app (staging: a separate test app whose webhook is
# https://$DOMAIN/api/webhooks/meta). Then run: deploy/deploy.sh restart
META_APP_ID=
META_APP_SECRET=
META_GRAPH_VERSION=v26.0
META_ES_CONFIG_ID=
META_WEBHOOK_VERIFY_TOKEN=$VERIFY_TOKEN
META_COEXISTENCE_CONFIG_ID=$COEXISTENCE_ID
META_COEXISTENCE_ENABLED=
META_DELETION_STATUS_URL=https://$DOMAIN/deletion-status

# Stripe — staging: TEST keys only (pk_test_/sk_test_); deploy.sh refuses live keys on staging.
# Webhook endpoint: https://$DOMAIN/api/webhooks/stripe
STRIPE_KEY=
STRIPE_SECRET=
STRIPE_WEBHOOK_SECRET=

# Shopify (Integrations) — staging: a separate development app.
SHOPIFY_CLIENT_ID=
SHOPIFY_CLIENT_SECRET=

ENGAGE_SECRETS_KEYS=$SECRETS_KEY
ENGAGE_MEDIA_DISK=local

REVERB_APP_ID=engage
REVERB_APP_KEY=$REVERB_APP_KEY
REVERB_APP_SECRET=$REVERB_APP_SECRET
REVERB_HOST=reverb
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_ALLOWED_ORIGINS=$DOMAIN
ENV

echo
echo "Created ($ENVIRONMENT):"
echo "  $LARAVEL_ENV"
echo "  $COMPOSE_ENV"
echo
echo "Back these two files up in the password manager under \"10X Engage — $ENVIRONMENT\"."
echo "Losing ENGAGE_SECRETS_KEYS means stored WhatsApp tokens can no longer be decrypted."
echo "Meta webhook verify token for this environment: $VERIFY_TOKEN"
