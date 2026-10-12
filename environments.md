# Staging and production

Two complete, separate copies of 10X Engage run on the same Oracle server. They share only the
machine and the HTTPS front door; code, branch, database, cache, queues, files, images, secrets,
cookies and third-party accounts are separate.

```
                               Internet (HTTPS)
                                      │
              ┌───────────────────────▼────────────────────────┐
              │ Caddy — production stack, the only thing on    │
              │ ports 80/443. Certificates for every domain.   │
              └───────┬───────────────────────────────┬────────┘
     app. / engage-admin. / engage.                st-app. / st-engage-admin. / st-engage.
                      │                               │  network "engage-edge" (no databases on it)
   ┌──────────────────▼─────────────────┐   ┌─────────▼───────────────────────────────┐
   │ PRODUCTION   project "engage"      │   │ STAGING   project "engage-staging"      │
   │ /opt/engage  · branch main         │   │ /opt/engage-staging · branch staging    │
   │ web · app · horizon · scheduler    │   │ gateway (routes, noindex) → web · app · │
   │ reverb · site                      │   │ horizon · scheduler · reverb · site     │
   │ postgres (engage_pgdata)           │   │ postgres (engage-staging_pgdata)        │
   │ valkey   (engage_valkeydata)       │   │ valkey   (engage-staging_valkeydata)    │
   └────────────────────────────────────┘   └─────────────────────────────────────────┘
```

Staging's containers are not on production's network and production's are not on staging's, so
a staging process cannot even resolve production's database, Valkey or app by name. The only
shared network carries two containers: production's Caddy and staging's gateway.

## Map of everything

| | Production | Staging |
|---|---|---|
| Client portal (engage-web) | https://app.10xdigital.ae | https://st-app.10xdigital.ae |
| Super Admin (engage-backend, /admin) | https://engage-admin.10xdigital.ae | https://st-engage-admin.10xdigital.ae |
| Website (engage-site) | https://engage.10xdigital.ae | https://st-engage.10xdigital.ae |
| API base | https://app.10xdigital.ae/api/v1 · public API /api/public/v1 | https://st-app.10xdigital.ae/api/v1 · /api/public/v1 |
| Branch (all three repos) | `main` | `staging` |
| Server folder | `/opt/engage/{backend,web,site}` | `/opt/engage-staging/{backend,web,site}` |
| Compose project / images | `engage` / `engage-*:latest` | `engage-staging` / `engage-staging-*:latest` |
| Laravel settings | `/opt/engage/backend/.env` | `/opt/engage-staging/backend/.env` |
| Compose settings (domains, DB passwords) | `/opt/engage/backend/deploy/.env` | `/opt/engage-staging/backend/deploy/.env` |
| Database | `postgres` container, volume `engage_pgdata` | `postgres` container, volume `engage-staging_pgdata` |
| Cache / queues / sessions | `valkey`, volume `engage_valkeydata` | `valkey`, volume `engage-staging_valkeydata`, prefixes `engage_staging_*` |
| Files (media) | volume `engage_storage` | volume `engage-staging_storage` |
| Session cookie | host-only on app.10xdigital.ae | `engage_staging_session`, host-only on st-app.10xdigital.ae |
| Queue workers | production pool (up to 45) | 1–2 per lane (`config/horizon.php` → staging) |
| Meta webhook | https://app.10xdigital.ae/api/webhooks/meta | https://st-app.10xdigital.ae/api/webhooks/meta |
| Stripe webhook | https://app.10xdigital.ae/api/webhooks/stripe (live mode) | https://st-app.10xdigital.ae/api/webhooks/stripe (test mode) |
| Shopify callback / webhooks | https://app.10xdigital.ae/api/integrations/shopify/{callback,webhook} | https://st-app.10xdigital.ae/api/integrations/shopify/{callback,webhook} |
| Search engines | indexed | `X-Robots-Tag: noindex` and `robots.txt: Disallow: /` on every staging address |
| Release history | `/opt/engage/releases.log` | `/opt/engage-staging/releases.log` |
| Backups | nightly 03:15, 14 days + before every migration | nightly 03:45, 3 days |
| Shell shortcut on the server | `dc ps`, `dc logs -f app` | `dcs ps`, `dcs logs -f app` |
| One command | `npm run deploy:production` | `npm run deploy:staging` |

Cookies cannot mix: every cookie is host-only (no shared `.10xdigital.ae` domain), so a login on
st-app is invisible to app and the other way round.

---

## How code moves

```
feature/xyz ──merge──▶ staging ──(tested on st-*)──▶ promote ──▶ main ──▶ production
                          ▲                                        │
                          └──────────── release:sync ◀── hotfix/xyz ┘
```

| Branch | What it is | Deploys to |
|---|---|---|
| `main` | Exactly what production runs. Changed only by `release:promote` or a hotfix. | production |
| `staging` | What is being tested. Feature branches are merged here. | staging, automatically |
| `feature/<name>` | One feature or improvement, from `staging`. | nowhere |
| `hotfix/<name>` | An urgent production fix, from `main`. | production (with `--hotfix`) |

The branch decides the environment in three independent places, so one mistake cannot cross over:
the GitHub workflow (branch → folder), the server folder (`/opt/engage-staging` can only check out
`staging`) and `deploy.sh --env` (refuses if it does not match the folder).

### A feature, start to finish
```bash
git checkout staging && git pull
git checkout -b feature/inbox-filter          # work, commit as usual
git push -u origin feature/inbox-filter       # optional: open a pull request into staging (checks run)

git checkout staging && git pull
git merge --no-ff feature/inbox-filter
git push                                      # GitHub Actions tests it and deploys staging by itself
npm run deploy:staging                        # or deploy all three apps to staging right away
```
Test on st-app / st-engage-admin / st-engage. When everything on staging is approved:
```bash
npm run release:promote                       # main becomes exactly what staging ran
npm run deploy:production                     # shows the changes, asks you to type "production"
```
Production only accepts commits that staging is running now: if something on `main` has not been
deployed to staging, `deploy:production` stops and says so.

### An emergency fix
```bash
git checkout main && git pull
git checkout -b hotfix/login-crash            # fix, commit
git checkout main && git merge --no-ff hotfix/login-crash && git push
npm run deploy:production -- --hotfix         # asks for "production" and then "hotfix"
npm run release:sync                          # merges main back into staging: the fix is never lost
npm run deploy:staging
```
`release:promote` refuses to run while main has a commit that staging lacks, so a hotfix cannot be
overwritten by the next release.

### Rollback
```bash
npm run rollback:production                   # or rollback:staging
```
Goes back to the previous successful release (code of all three apps), rebuilds and runs the
health checks. Running it again goes one more release back. Then fix or `git revert` the faulty
commit on the branch, otherwise the next deploy brings it back.

The database is **not** rolled back automatically. Migrations must therefore be additive
(see Migrations below). If a migration itself damaged data, restore the backup taken right before
it (Backups and restore).

### All commands (run in the engage-backend folder on your computer)
| Command | What it does |
|---|---|
| `npm run deploy:staging` | Deploys `staging` of backend, web and site to staging; waits for the health checks. |
| `npm run deploy:production` | Deploys `main` to production after checking staging ran it; lists the changes; asks to type `production`; tags the release `prod-YYYYMMDD-HHMMSS` in all repos. |
| `npm run release:promote` | Moves `main` to what is on `staging` (fast-forward only). Deploys nothing. |
| `npm run release:sync` | Merges `main` into `staging` (after a hotfix). |
| `npm run release:status` | What each environment runs, and its last 10 releases. |
| `npm run rollback:staging` / `rollback:production` | Previous successful release. |
| `npm run release:init` | One time: creates the `staging` branch from `main` in all three repos. |

A deploy uses what is on GitHub, never files on your computer. If your local `staging` or `main`
has commits you have not pushed, the command stops and tells you.

On the server the same is available directly:
`bash /opt/engage[-staging]/backend/deploy/deploy.sh [--env production|staging] all|backend|web|site|restart|check|status|rollback`.

### What a deployment does
1. Checks the environment's settings before touching anything: `APP_ENV`, `APP_URL` matches the
   domain, database and Valkey are the environment's own containers, `APP_DEBUG` is off; on
   staging also no live Stripe keys and no key copied from production (`APP_KEY`,
   `ENGAGE_SECRETS_KEYS`, DB and Reverb passwords, Stripe, Shopify).
2. Takes the latest commit of the environment's branch.
3. Builds the new images while the old containers keep serving.
4. Production only: if migrations are pending, backs up the database first.
5. Runs migrations, reference data (roles, plans), partitions; restarts app, workers, scheduler,
   websockets, portal and website.
6. Health checks through the real HTTPS addresses: `/up`, an API call that reads the database,
   the portal, the Super Admin, the website, and Horizon running. Only then is it reported
   successful.
7. Writes the result to `releases.log` (time, environment, commits of all three apps, who) and,
   if `DEPLOY_NOTIFY_WEBHOOK` is set, posts it to Slack/Discord/Teams. Failures stop at the failing
   step, are logged as `failed`, and GitHub e-mails the person who pushed.

No secrets are ever printed: the checks name keys, never values.

Downtime: builds and migrations happen while the previous version is still serving; the restart of
the app containers takes a few seconds. Migrations run before the new code starts, which is why
they must keep working with the previous code (next section).

---

## Migrations

- **Expand, then contract.** Add columns/tables in one release; remove or rename old ones in a
  later release, once no running code uses them. Then the previous version still works after the
  migration, and a code rollback is always safe.
- New `NOT NULL` columns get a default, or are filled in by the migration before the constraint.
- Data backfills on tenant tables run inside `app(TenantContext::class)->bypass(...)` (row-level
  security applies to migrations too).
- Every migration runs on staging first, against staging's data.
- Production automatically gets a database backup right before any deploy that has pending
  migrations (`/opt/engage/backups/pre-deploy-*.dump`, last 10 kept).
- `php artisan migrate:rollback` is never run on production as part of a rollback; if a schema
  change must be undone, write a new migration that undoes it and release it, or restore the backup.

## Data isolation

- Staging starts empty and gets its data from testing: test workspaces, test numbers, Stripe test
  cards. Staging's first Super Admin is created with `dcs exec app php artisan engage:admin:create`.
- **Production data is never copied to staging.** There is deliberately no tool for it. If it is
  ever needed, it requires a written, approved anonymisation step (names, phone numbers, e-mails,
  message bodies, media, tokens) and must not include `secrets`/tokens at all.
- Staging can't reach production's database: different network, different host name, different
  password, and the deploy refuses a staging `.env` whose `DB_HOST` is not its own `postgres`.

---

## Secrets checklist

Secrets live only in the two `.env` files of each environment on the server (mode 600, created by
`deploy/init-env.sh`), plus a copy in the company password manager ("10X Engage — production" /
"10X Engage — staging"), plus the three GitHub secrets below. Never in git, never in chat.

| Setting | Production | Staging | Where it comes from |
|---|---|---|---|
| `APP_KEY`, `ENGAGE_SECRETS_KEYS` | own | own (generated) | `init-env.sh`; never copy between environments |
| `DB_PASSWORD`, `POSTGRES_PASSWORD` | own | own (generated) | `init-env.sh` |
| `REVERB_APP_KEY/SECRET` | own | own (generated) | `init-env.sh` |
| `META_APP_ID/SECRET`, `META_ES_CONFIG_ID`, `META_WEBHOOK_VERIFY_TOKEN` | production Meta app | a separate Meta **test/dev app** | Meta App Dashboard. One Meta app sends webhooks to one URL only, so staging needs its own app to receive messages. |
| `META_COEXISTENCE_CONFIG_ID` | 1117494534308456 | 2159163541380544 | pre-filled by `init-env.sh` |
| `STRIPE_KEY/SECRET/WEBHOOK_SECRET` | live (`pk_live`/`sk_live`) | **test** (`pk_test`/`sk_test`) | Stripe Dashboard; staging refuses live keys |
| `MAIL_*` | AWS SES SMTP user for production | a sandbox inbox (Mailtrap or similar) or `MAIL_MAILER=log` | separate SMTP credentials |
| `SHOPIFY_CLIENT_ID/SECRET` | production Shopify app | a separate development app | Shopify Dev Dashboard |
| AI provider keys (when added) | production key | separate key with a low spending limit | provider dashboard |
| `DEPLOY_NOTIFY_WEBHOOK` (compose `.env`) | optional | optional | Slack/Discord incoming webhook |
| GitHub: `SERVER_HOST`, `SERVER_USER`, `SERVER_SSH_KEY` | repository secrets, shared | — | already set |

After editing a `.env`: `bash /opt/engage[-staging]/backend/deploy/deploy.sh restart`.

**Rotation** (do it on staging first):
- Third-party keys (Meta, Stripe, SES, Shopify): create the new key in the provider's dashboard,
  put it in `.env`, `deploy.sh restart`, check, then revoke the old one.
- `DB_PASSWORD`: `dc exec postgres psql -U postgres -c "ALTER ROLE engage PASSWORD '<new>'"`, put
  the same value in both `.env` files (`DB_PASSWORD` and `ENGAGE_DB_PASSWORD`), `deploy.sh restart`.
- `ENGAGE_SECRETS_KEYS`: `dc exec app php artisan engage:secrets:key --id=k2` prints a new key; put
  it in front (`k2:base64:…,k1:…`), restart, run `dc exec app php artisan engage:secrets:key --rotate`,
  then remove the old key and restart again.
- `APP_KEY`: move the current value to `APP_PREVIOUS_KEYS`, set a new one, restart (users stay
  logged in; old encrypted values still decrypt).
- SSH deploy key: add the new public key to `~/.ssh/authorized_keys`, update the GitHub secret,
  remove the old key.

---

## One-time setup (in this order)

### 1. GitHub
1. **First, on the server** (once, before pushing): `docker network create engage-edge`.
   The production deploy that runs on the push already expects this network.
   Then merge these changes into `main` of all three repos and push. Production deploys itself as
   always; the only production change is that Caddy joins that network and knows the staging
   names. Run `bash /opt/engage/backend/deploy/deploy.sh check` afterwards.
2. On your computer, in engage-backend: `cp .deploy.local.example .deploy.local` (check
   `ENGAGE_SSH`, `WEB_DIR`, `SITE_DIR`), then `npm run release:init` — creates `staging` in all
   three repos from `main`.
3. Each repo → **Settings → Environments**: create **staging** (no rules) and open **production**
   → *Required reviewers*: add yourself. GitHub then waits for your approval before deploying
   `main`. (`npm run deploy:production` does not need it: it asks you directly.)
4. Each repo → **Settings → Branches → Add rule** for `main` and for `staging`: *Require status
   checks to pass*, *Do not allow force pushes*, *Do not allow deletions*.

### 2. DNS (Cloudflare, "DNS only" grey cloud)
A records → `92.5.91.81`: `st-app`, `st-engage-admin`, `st-engage`, and `engage` once the website
moves there. Production: `app`, `engage-admin` already exist.

> **Check first:** `engage.10xdigital.ae` used to serve the old Hostinger version of the app.
> Pointing it to this server retires whatever runs there (including any Meta webhook or Stripe
> endpoint still configured on that address).

### 3. Production settings (`/opt/engage/backend/deploy/.env`)
```
DOMAIN=app.10xdigital.ae
EXTRA_DOMAINS=", engage-web.10xdigital.ae"          # keep while old links/webhooks still use it
ADMIN_DOMAIN=engage-admin.10xdigital.ae
SITE_DOMAIN=engage.10xdigital.ae
SITE_REDIRECT_DOMAINS="engage-site.10xdigital.ae"   # old website address → 301 to engage.
```
Then `bash /opt/engage/backend/deploy/deploy.sh all` and check `deploy.sh check`.

### 4. Staging on the server
```bash
bash /opt/engage/backend/deploy/staging-setup.sh
```
It checks out the `staging` branches into `/opt/engage-staging`, creates staging's own secrets,
adds the staging domains to production's Caddy, installs the nightly staging backup and the `dcs`
shortcut. Then fill in staging's third-party credentials (table above) in
`/opt/engage-staging/backend/.env` and:
```bash
bash /opt/engage-staging/backend/deploy/deploy.sh --env staging all
dcs exec app php artisan engage:admin:create
```

### 5. Third parties for staging
- **Meta:** create a development app (or use the existing staging configuration's app): webhook
  `https://st-app.10xdigital.ae/api/webhooks/meta` with staging's `META_WEBHOOK_VERIFY_TOKEN`;
  Embedded Signup allowed domains `st-app.10xdigital.ae`; add test numbers.
- **Stripe (test mode):** webhook endpoint `https://st-app.10xdigital.ae/api/webhooks/stripe`, same
  events as production; put the test keys and the endpoint's signing secret in staging's `.env`.
- **Shopify:** a development app with redirect `https://st-app.10xdigital.ae/api/integrations/shopify/callback`.
- **Mail:** sandbox credentials, or leave `MAIL_MAILER=log` (e-mails then appear in `dcs logs app`).

---

## Backups and restore

- Nightly: `/opt/engage/backups/db-*.dump` + `media-*.tar.gz` (14 days);
  `/opt/engage-staging/backups/` (3 days). Before migrations: `/opt/engage/backups/pre-deploy-*.dump`.
- Copy production backups off the server regularly (Oracle Object Storage or your computer):
  `scp ubuntu@92.5.91.81:/opt/engage/backups/db-<date>.dump .`

Restore production's database (stops the app while restoring):
```bash
dc stop app horizon scheduler reverb
dc exec -T postgres pg_restore -U postgres --clean --if-exists -d engage < /opt/engage/backups/<file>.dump
dc start app horizon scheduler reverb
bash /opt/engage/backend/deploy/deploy.sh check
```
Practise this on staging first (`dcs …` and a staging dump). Do it at least once before relying on it.

---

## Verification checklist

Run `bash …/deploy.sh check` for each environment (it covers the ✓ items automatically), then
check the rest by hand once after setup and after any change to domains or credentials.

**Each environment**
- [ ] ✓ `https://<portal>/up` 200, `/api/v1/public/plans` 200, `/login` loads
- [ ] ✓ `https://<admin>/admin/login` loads; ✓ website loads; ✓ Horizon running
- [ ] Register → OTP e-mail (staging: in the sandbox / log) → log in
- [ ] Super Admin login with 2FA
- [ ] Live inbox updates without reload (Reverb websocket through `/app/*`)
- [ ] Embedded Signup connects a test number; Meta webhook delivers an inbound message
- [ ] Send a template and a free-form reply; statuses update
- [ ] Stripe: subscribe with a card (staging: `4242 4242 4242 4242`), webhook marks the invoice paid
- [ ] Website lead form arrives in Super Admin → Inquiries; pricing shows the API's plans
- [ ] Public API: create a key, `GET /api/public/v1/me` with it

**Isolation**
- [ ] Logged in on st-app, open app: not logged in (and the other way round)
- [ ] A workspace created on staging does not exist on production
- [ ] `curl -sI https://st-engage.10xdigital.ae | grep -i x-robots` shows `noindex`
- [ ] `dcs exec app php artisan tinker --execute="echo config('app.env'), ' ', config('database.connections.pgsql.host');"` prints `staging postgres`
- [ ] `docker network inspect engage-edge` lists only `engage-caddy-1` and `engage-staging-gateway-1`
- [ ] Stripe dashboard: staging events appear only in test mode

**Release flow**
- [ ] A push to `staging` deploys staging automatically (Actions tab)
- [ ] A push to `main` waits for approval (production environment rule)
- [ ] `npm run deploy:production` with an untested commit on `main` is refused
- [ ] `npm run release:status` shows both environments
- [ ] `npm run rollback:staging` works once (then `npm run deploy:staging` to return)