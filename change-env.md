On the staging server, the backend's settings are in /opt/engage-staging/backend/.env. You change three lines there, then restart the backend so it picks them up. Run these over the SSH session you already have open.

1. Go to the backend folder and back up the current settings

cd /opt/engage-staging/backend
cp .env .env.bak-$(date +%F)

2. Open the settings file

nano .env

Find the three lines that start with STRIPE_ (Ctrl+W searches, then type STRIPE_). Set them like this, with no spaces and no quotes:

STRIPE_KEY=pk_test_...            (Publishable key)
STRIPE_SECRET=sk_test_...         (Secret key)
STRIPE_WEBHOOK_SECRET=whsec_...   (Signing secret)

If a line is missing, add it. Save with Ctrl+O, then Enter, then exit with Ctrl+X.

3. Check the lines without printing the secrets

grep -E '^STRIPE_(KEY|SECRET|WEBHOOK_SECRET)=' .env | cut -c1-30

You should see each name followed by the start of its value: pk_test_, sk_test_ and whsec_. On staging the keys must start with _test_; the deploy script rejects live keys there.

4. Restart the backend

bash deploy/deploy.sh --env staging restart

5. Run the deploy script's checks

bash deploy/deploy.sh --env staging check

This flags common problems, such as a live Stripe key on staging or a secret copied from production.