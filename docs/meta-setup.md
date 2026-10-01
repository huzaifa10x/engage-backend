# Meta / WhatsApp setup (Phase 2)

## Environment
```
META_APP_ID=            # App Dashboard header
META_APP_SECRET=        # App settings › Basic
META_ES_CONFIG_ID=      # Facebook Login for Business › Configurations (WhatsApp Embedded Signup)
META_WEBHOOK_VERIFY_TOKEN=<random string>
ENGAGE_SECRETS_KEYS=    # php artisan engage:secrets:key  (required in production)
META_COEXISTENCE_ENABLED=false   # keep off until the messaging module ships
```

## App Dashboard
| Setting | Value |
|---|---|
| WhatsApp › Configuration › Callback URL | `https://<api-host>/api/webhooks/meta` |
| Verify token | `META_WEBHOOK_VERIFY_TOKEN` |
| Webhook fields | account_update, messages, message_template_status_update, phone_number_quality_update, phone_number_name_update, business_capability_update, account_review_update, account_alerts, user_id_update, user_preferences (+ history, smb_app_state_sync, smb_message_echoes for coexistence) |
| App settings › Basic › Data Deletion Request URL | `https://<api-host>/api/meta/data-deletion` |
| Facebook Login for Business › Settings › Deauthorize callback | `https://<api-host>/api/meta/deauthorize` |
| Client OAuth settings › Allowed domains | the Next.js client domain(s), HTTPS only |

Meta requires HTTPS for webhooks — use a tunnel (e.g. `ngrok http 8000`) locally.
Reverse proxy body limit ≥ 16 MB (coexistence history chunks).

## Client flow (Next.js)
1. `POST /api/v1/whatsapp/signups` `{coexistence?: bool}` → `launch.app_id`, `launch.graph_version`, `launch.login_options`
2. `FB.init({appId, version})`; `FB.login(cb, launch.login_options)`; listen for `message` events from `*.facebook.com` with `type === 'WA_EMBEDDED_SIGNUP'`
3. On `authResponse.code` + FINISH* event → **immediately** `POST /api/v1/whatsapp/signups/{id}/complete`
   `{code, event, waba_id, phone_number_id, business_id, meta_user_id: authResponse.userID}` (code TTL: 30 s)
4. Poll `GET /api/v1/whatsapp/signups/{id}` until `completed` / `failed`
5. CANCEL event → `POST /api/v1/whatsapp/signups/{id}/cancel` `{current_step | error_code, error_message, session_id}`

## Operations
- `php artisan engage:webhooks:replay --status=deferred --field=messages` — once a module starts handling a field
- `php artisan engage:webhooks:prune` — daily 02:30 (raw log 90 days, dedup keys 8 days)
- `php artisan engage:secrets:key --id=k2` → prepend to ENGAGE_SECRETS_KEYS → deploy → `engage:secrets:key --rotate`

## Messaging API (Phase 3)

| Method | Path | Permission |
|---|---|---|
| GET | `/api/v1/conversations?phone_number_id&status&assigned=me\|unassigned&unread&q&cursor` | inbox.view (scoped to granted numbers) |
| GET | `/api/v1/conversations/{id}` · `/conversations/{id}/messages?cursor` | inbox.view |
| PATCH | `/api/v1/conversations/{id}` `{status, assigned_membership_id}` | inbox.view (+ inbox.assign to assign) |
| POST | `/api/v1/conversations/{id}/read` | inbox.view — clears unread, sends blue ticks |
| POST | `/api/v1/conversations/{id}/messages` `{type, body, media_id, template, reply_to, content}` | inbox.reply — `Idempotency-Key` header supported |
| POST | `/api/v1/messages` `{phone_number_id, contact_id \| to, type, …}` | inbox.reply — start a thread (template outside the 24h window) |
| POST | `/api/v1/media` (multipart `file`) · GET `/api/v1/media/{id}` | inbox.reply · inbox.view |
| GET/POST/PATCH/DELETE | `/api/v1/contacts[/{id}]`, POST `/contacts/{id}/consent` | contacts.* |

Rules enforced on every send: number connected · contact not opted out (STOP/START keywords are
automatic) · free-form only inside the 24h customer service window (error `window_closed`) ·
per-number throughput (80 msg/s, 20 for coexistence numbers).

Contacts are identified by phone (`wa_id`) and/or business-scoped user ID (`bsuid`, Meta 2026):
users with WhatsApp usernames may arrive without a phone number and are messaged by BSUID.

Realtime: private channel `tenant.{tenantId}.number.{phoneNumberId}`, events `message.created` /
`message.updated`; auth endpoint `POST /api/broadcasting/auth`. Install Reverb to deliver them
(`composer require laravel/reverb && php artisan reverb:install`, `BROADCAST_CONNECTION=reverb`).
