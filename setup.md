## Local development
cd ~/engage-backend

# First time only: stop the old Docker setup
docker compose down

./dev setup        # first time on a machine (safe to re-run)
./dev start        # backend + client portal + website (Ctrl+C stops the frontends)
./dev start --backend   # backend only

## Release (from your Mac, in ~/engage-backend, after pushing to main)
npm run release              # staging → promote → production (asks before production)

# Step by step
npm run deploy:staging       # main → staging, deploy staging
npm run release:promote      # staging → main
npm run deploy:production    # deploy main to production (asks to confirm)

# Emergency fix straight to production (skips staging; push the fix to main first)
npm run deploy:production -- --hotfix

# What is running where / roll back
npm run release:status
npm run rollback:staging
npm run rollback:production

## On the production server
bash /opt/engage/backend/deploy/deploy.sh all       # backend + web + site
bash /opt/engage/backend/deploy/deploy.sh backend   # backend only
bash /opt/engage/backend/deploy/deploy.sh web       # client portal only
bash /opt/engage/backend/deploy/deploy.sh restart   # after editing .env
bash /opt/engage/backend/deploy/deploy.sh status    # what is deployed, last releases
bash /opt/engage/backend/deploy/deploy.sh check     # health checks only

# Change .env on production
nano /opt/engage/backend/.env
bash /opt/engage/backend/deploy/deploy.sh restart

## On the staging server (same commands, staging folder)
bash /opt/engage-staging/backend/deploy/deploy.sh all
bash /opt/engage-staging/backend/deploy/deploy.sh restart
nano /opt/engage-staging/backend/.env

## Delete a user or a workspace
Easiest: Super Admin › Users › (user) › Delete user, or Companies › (company) › Delete company.

Command line (production):
# Preview, changes nothing
docker compose --project-directory /opt/engage/backend/deploy -f /opt/engage/backend/deploy/docker-compose.prod.yml exec app php artisan engage:client:delete EMAIL
# Delete
docker compose --project-directory /opt/engage/backend/deploy -f /opt/engage/backend/deploy/docker-compose.prod.yml exec app php artisan engage:client:delete EMAIL --force
# (add --ignore-billing only if a workspace still has a paid subscription you mean to drop)

## Shopify setup (your side)
Shopify shows "Coming soon" until these are set. WooCommerce and Zapier/Make work without them.
- Create the app in Shopify's Dev Dashboard.
- App URL: https://app.10xdigital.ae/api/integrations/shopify/app
- Redirect URL: https://app.10xdigital.ae/api/integrations/shopify/callback
- Compliance webhooks (all three): https://app.10xdigital.ae/api/integrations/shopify/webhook
- Scope: read_orders
- Add SHOPIFY_CLIENT_ID and SHOPIFY_CLIENT_SECRET to /opt/engage/backend/.env, then deploy.sh restart
- Request protected customer data access (name, phone, email, address); without it orders arrive without the phone number.