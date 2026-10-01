# Deploying 10X Engage on Oracle Cloud (Frankfurt)

One Oracle Cloud VM runs everything with Docker:

```
                 https://engage.10xdigital.ae
                            │
                  ┌─────────▼─────────┐
                  │ Caddy (HTTPS, auto │  /api /sanctum /admin /horizon → Laravel
                  │ Let's Encrypt)     │  /app/*                          → Reverb (live inbox)
                  └─────────┬─────────┘  everything else                 → Next.js
      ┌──────────┬──────────┼──────────┬──────────┐
     web        app      horizon    scheduler   reverb      postgres 17 · valkey
  (Next.js)  (Laravel)   (queues)    (cron)   (websockets)   (data on volumes)
```

Every push to `main` (GitHub Desktop → **Push origin**) runs the tests in GitHub Actions and, only if
they pass, deploys to the server automatically.

---

## Part A — Oracle Cloud console (≈15 min)

### A1. Create the server
1. Console → **☰ → Compute → Instances → Create instance**.
2. **Name:** `engage-prod`.
3. **Image and shape → Edit**
   - **Image:** Change image → **Ubuntu** → **Canonical Ubuntu 24.04** (not "Minimal").
   - **Shape:** Change shape → **Ampere** → **VM.Standard.A1.Flex** → **4 OCPUs, 24 GB memory**
     (this is the Always Free allowance).
4. **Networking:** *Create new virtual cloud network* + *Create new public subnet*,
   **Assign a public IPv4 address = Yes**.
5. **Add SSH keys:** *Generate a key pair for me* → **Download private key**. Keep it safe.
6. **Boot volume:** *Specify a custom size* → **100 GB**.
7. **Create**. When it shows **Running**, copy the **Public IP address**.

> **"Out of capacity"?** Ampere servers are popular in Frankfurt. Pick another *Availability domain*
> (AD-2 / AD-3) and retry, or try later. Upgrading the account to **Pay As You Go**
> (Billing → Upgrade) keeps Always Free resources free, makes capacity easier to get, and stops
> Oracle from reclaiming "idle" free instances.

### A2. Open web ports 80 and 443
1. On the instance page → **Networking** tab → click the **Subnet** link.
2. **Security** tab → open the **Default Security List**.
3. **Security rules → Add Ingress Rules**:
   - Source CIDR `0.0.0.0/0`, IP Protocol **TCP**, Destination port range **80,443** → **Add**.

### A3. Point the domain at the server
At your DNS provider for `10xdigital.ae`, add an **A record**: name **`engage`** → value **the public IP**.
Check from your Mac after a few minutes: `ping engage.10xdigital.ae` should show the server IP.

> No DNS access yet? Use `<ip-with-dashes>.sslip.io` as the domain (e.g. `152-70-1-2.sslip.io`);
> HTTPS works with it too. You can switch to the real domain later.

---

## Part B — Connect to the server from your Mac (Terminal)

```bash
mv ~/Downloads/ssh-key-*.key ~/.ssh/engage-prod.key
chmod 600 ~/.ssh/engage-prod.key
ssh -i ~/.ssh/engage-prod.key ubuntu@YOUR_SERVER_IP
```
Type `yes` the first time. Everything below runs **on the server** unless marked *(Mac)* or *(GitHub)*.

---

## Part C — Let the server read your GitHub repos (deploy keys)

GitHub allows a key on one repo only, so create two read-only keys:

```bash
ssh-keygen -t ed25519 -N "" -C "engage-prod backend" -f ~/.ssh/engage_backend
ssh-keygen -t ed25519 -N "" -C "engage-prod web"     -f ~/.ssh/engage_web
cat >> ~/.ssh/config <<'CFG'
Host github-backend
  HostName github.com
  User git
  IdentityFile ~/.ssh/engage_backend
  IdentitiesOnly yes
Host github-web
  HostName github.com
  User git
  IdentityFile ~/.ssh/engage_web
  IdentitiesOnly yes
CFG
cat ~/.ssh/engage_backend.pub; echo; cat ~/.ssh/engage_web.pub
```

*(GitHub)* For each repo → **Settings → Deploy keys → Add deploy key**:
- `engage-backend` → paste the first line, title `engage-prod`, **leave "Allow write access" off**.
- `engage-web` → paste the second line, same settings.

---

## Part D — Install and start (≈20 min, mostly waiting)

```bash
sudo mkdir -p /opt/engage && sudo chown ubuntu:ubuntu /opt/engage
git clone git@github-backend:huzaifa10x/engage-backend.git /opt/engage/backend
git clone git@github-web:huzaifa10x/engage-web.git /opt/engage/web     # type "yes" if asked

bash /opt/engage/backend/deploy/server-setup.sh
exit
```

Reconnect *(Mac)* `ssh -i ~/.ssh/engage-prod.key ubuntu@YOUR_SERVER_IP`, then:

```bash
bash /opt/engage/backend/deploy/init-env.sh   # asks for domain + email, generates all secrets
bash /opt/engage/backend/deploy/deploy.sh all # first build takes ~10 minutes
```

It finishes with **✅ Live: https://engage.10xdigital.ae**.

**Create your Super Admin login:**
```bash
dc exec app php artisan engage:admin:create waqar@10xdigital.ae --name="Waqar"
```
Open `https://engage.10xdigital.ae/admin` (you will set up the authenticator app on first login).
The customer app is `https://engage.10xdigital.ae`.

**Back up the secrets** — copy these two files into your password manager:
```bash
cat /opt/engage/backend/.env
cat /opt/engage/backend/deploy/.env
```

---

## Part E — Automatic deploys on every push

### E1. A key GitHub Actions uses to log in to the server
```bash
ssh-keygen -t ed25519 -N "" -C "github-actions" -f ~/.ssh/github_actions
cat ~/.ssh/github_actions.pub >> ~/.ssh/authorized_keys
cat ~/.ssh/github_actions        # copy ALL of it, including the BEGIN/END lines
```

### E2. *(GitHub)* Add three secrets to **both** repos
Repo → **Settings → Secrets and variables → Actions → New repository secret**:

| Name | Value |
|---|---|
| `SERVER_HOST` | your server's public IP |
| `SERVER_USER` | `ubuntu` |
| `SERVER_SSH_KEY` | the private key from E1 |

### E3. Use it
In **GitHub Desktop**: commit → **Push origin**. Then on github.com → repo → **Actions** tab:
- **engage-backend:** runs code style + all tests against Postgres → deploys → migrates the database.
- **engage-web:** runs typecheck, lint, format and build → deploys.

A red ❌ in *test/check* means nothing was deployed — the live site keeps running the previous version.

---

## Part F — Connect Meta (when ready)

1. Edit the server env: `nano /opt/engage/backend/.env` → fill `META_APP_ID`, `META_APP_SECRET`,
   `META_ES_CONFIG_ID` (Ctrl+O, Enter, Ctrl+X to save), then `bash /opt/engage/backend/deploy/deploy.sh restart`.
2. Meta App Dashboard:
   - **WhatsApp → Configuration → Callback URL:** `https://engage.10xdigital.ae/api/webhooks/meta`
     **Verify token:** the value printed by `init-env.sh` (`grep VERIFY /opt/engage/backend/.env`).
     Subscribe the fields listed in `docs/meta-setup.md`.
   - **Facebook Login for Business → Settings → Allowed domains:** `engage.10xdigital.ae`.
   - **App settings → Basic:** Data deletion URL `https://engage.10xdigital.ae/api/meta/data-deletion`.

---

## Everyday commands (on the server)

| Task | Command |
|---|---|
| What is running | `dc ps` |
| Live logs | `dc logs -f app` (or `web`, `horizon`, `caddy`, `reverb`) |
| Deploy by hand | `bash /opt/engage/backend/deploy/deploy.sh backend` (or `web`, `all`) |
| After editing `.env` | `bash /opt/engage/backend/deploy/deploy.sh restart` |
| Artisan | `dc exec app php artisan <command>` |
| Database shell | `dc exec postgres psql -U postgres engage` |
| Backup now | `bash /opt/engage/backend/deploy/backup.sh` (runs nightly 03:15, keeps 14 days in `/opt/engage/backups`) |

## Troubleshooting

| Symptom | Fix |
|---|---|
| Site doesn't load at all | Check A2 (ports) and A3 (`ping` shows the right IP). `dc logs caddy` shows certificate errors. |
| "Too many certificates" | Let's Encrypt rate limit after many failed attempts — fix DNS first, wait an hour. |
| Actions deploy fails "ssh: handshake failed" | `SERVER_SSH_KEY` must be the **private** key, complete, including BEGIN/END lines. |
| Deploy fails at "Updating" | Deploy key missing or added to the wrong repo (Part C). |
| 500 errors | `dc logs --tail=100 app` |
| Emails (invitations) not arriving | Mail is logged only. Configure SMTP (e.g. OCI Email Delivery or Google Workspace) in `.env` → `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT=587`, `MAIL_USERNAME`, `MAIL_PASSWORD`, then `deploy.sh restart`. |
