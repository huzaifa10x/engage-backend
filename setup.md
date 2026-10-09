
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