
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