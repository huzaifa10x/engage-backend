cd ~/path/to/engage-backend

# Stop Old Docker Container First Time
docker compose down

# Start New Setup
./dev setup
./dev start



Redeploy
Commands on the server

Both together:

bash /opt/engage/backend/deploy/deploy.sh all

Backend only:

bash /opt/engage/backend/deploy/deploy.sh backend

Frontend only:

bash /opt/engage/backend/deploy/deploy.sh web


Restart on the Server 

bash /opt/engage/backend/deploy/deploy.sh restart

To Update ENV on live server
Open the file on the server:
   nano /opt/engage/backend/.env

bash /opt/engage/backend/deploy/deploy.sh restart


To delete a workspace
Preview (changes nothing):
   docker compose --project-directory /opt/engage/backend/deploy -f /opt/engage/backend/deploy/docker-compose.prod.yml exec app php artisan engage:client:delete teamdubai103@gmail.com

It lists the account, each workspace marked DELETE or KEEP, and the counts of contacts, conversations and messages.

If the preview is what you expect, delete:
   docker compose --project-directory /opt/engage/backend/deploy -f /opt/engage/backend/deploy/docker-compose.prod.yml exec app php artisan engage:client:delete teamdubai103@gmail.com --force


Shopify setup (your side)

Shopify shows as “Coming soon” until these are set. WooCommerce and Zapier/Make work without them.

Create the app in Shopify’s Dev Dashboard.
Set App URL to https://app.10xdigital.ae/api/integrations/shopify/app.
Set the redirect URL to https://app.10xdigital.ae/api/integrations/shopify/callback.
Point the three compliance webhooks at https://app.10xdigital.ae/api/integrations/shopify/webhook.
Set the scope to read_orders.
Add SHOPIFY_CLIENT_ID and SHOPIFY_CLIENT_SECRET to the backend .env and redeploy.
Request protected customer data access (name, phone, email, address). Without it, a public app receives orders with the phone number removed and nothing can be sent.