#!/usr/bin/env bash
# One-time: creates the two secret files the server needs, with strong random values.
#   /opt/engage/backend/.env          → Laravel
#   /opt/engage/backend/deploy/.env   → docker compose (domain, DB passwords, Reverb key)
# Re-running never overwrites existing files.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
LARAVEL_ENV="$ROOT/.env"
COMPOSE_ENV="$ROOT/deploy/.env"

if [[ -f "$LARAVEL_ENV" || -f "$COMPOSE_ENV" ]]; then
    echo "Env files already exist — not overwriting:"
    ls -l "$LARAVEL_ENV" "$COMPOSE_ENV" 2>/dev/null || true
    exit 0
fi

read -rp "Client portal domain (e.g. engage.10xdigital.ae): " DOMAIN
read -rp "Super Admin domain (e.g. engage-admin.10xdigital.ae): " ADMIN_DOMAIN
read -rp "Email for HTTPS certificate notices: " ACME_EMAIL
[[ -n "$DOMAIN" && -n "$ADMIN_DOMAIN" && -n "$ACME_EMAIL" ]] || { echo "Both domains and the email are required."; exit 1; }

rand() { openssl rand -hex "$1"; }
APP_KEY="base64:$(openssl rand -base64 32)"
SECRETS_KEY="k1:base64:$(openssl rand -base64 32)"
POSTGRES_PASSWORD="$(rand 24)"
ENGAGE_DB_PASSWORD="$(rand 24)"
REVERB_APP_KEY="$(rand 16)"
REVERB_APP_SECRET="$(rand 24)"
VERIFY_TOKEN="$(rand 20)"

umask 077

cat > "$COMPOSE_ENV" <<ENV
DOMAIN=$DOMAIN
ADMIN_DOMAIN=$ADMIN_DOMAIN
ACME_EMAIL=$ACME_EMAIL
POSTGRES_PASSWORD=$POSTGRES_PASSWORD
ENGAGE_DB_PASSWORD=$ENGAGE_DB_PASSWORD
REVERB_APP_KEY=$REVERB_APP_KEY
ENV

cat > "$LARAVEL_ENV" <<ENV
APP_NAME="10X Engage"
APP_ENV=production
APP_KEY=$APP_KEY
APP_DEBUG=false
APP_URL=https://$DOMAIN
APP_TIMEZONE=UTC
APP_LOCALE=en
APP_FALLBACK_LOCALE=en

FRONTEND_URL=https://$DOMAIN
ADMIN_DOMAIN=$ADMIN_DOMAIN
SANCTUM_STATEFUL_DOMAINS=$DOMAIN
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

CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=redis
BROADCAST_CONNECTION=reverb
FILESYSTEM_DISK=local

# Emails (team invitations) are only logged until SMTP is configured — see docs/deploy-oracle.md.
MAIL_MAILER=log
MAIL_FROM_ADDRESS=no-reply@$DOMAIN
MAIL_FROM_NAME="10X Engage"

# Meta — fill in from the App Dashboard, then run: deploy/deploy.sh restart
META_APP_ID=
META_APP_SECRET=
META_GRAPH_VERSION=v25.0
META_ES_CONFIG_ID=
META_WEBHOOK_VERIFY_TOKEN=$VERIFY_TOKEN
# Coexistence (WhatsApp Business app numbers): the Embedded Signup configuration ID made for it.
# Setting the ID switches the feature on; META_COEXISTENCE_ENABLED=false switches it off again.
META_COEXISTENCE_CONFIG_ID=
META_COEXISTENCE_ENABLED=
META_DELETION_STATUS_URL=https://$DOMAIN/deletion-status

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
echo "Created:"
echo "  $LARAVEL_ENV"
echo "  $COMPOSE_ENV"
echo
echo "Back these two files up somewhere safe (password manager). Losing ENGAGE_SECRETS_KEYS"
echo "means stored WhatsApp tokens can no longer be decrypted."
echo "Meta webhook verify token: $VERIFY_TOKEN"
