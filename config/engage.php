<?php

declare(strict_types=1);

return [

    'frontend_url' => rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/'),

    'admin_domain' => env('ADMIN_DOMAIN'),

    'tenancy' => [
        // Session key holding the active tenant for the Next.js SPA.
        'session_key' => 'active_tenant_id',
        'default_timezone' => 'Asia/Dubai',
        'default_currency' => 'USD',
    ],

    /*
    | Stripe: subscriptions, invoices, payments and refunds. Prices stay in USD (plan catalog).
    | UAE customers are charged VAT on top (tax rate created in Stripe on first use, or set
    | STRIPE_UAE_TAX_RATE_ID to use one you created yourself).
    */
    'stripe' => [
        'key' => env('STRIPE_KEY'),                 // publishable key (pk_…): used by the card form in the browser
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'api_version' => '2024-06-20',
        'uae_vat_percent' => (float) env('STRIPE_UAE_VAT_PERCENT', 5),
        'uae_tax_rate_id' => env('STRIPE_UAE_TAX_RATE_ID'),
    ],

    /*
    | Super Admin panel security. With two_factor_required on (default), every platform admin
    | must use 2FA (authenticator app or email code) and cannot switch it off.
    */
    'admin' => [
        'two_factor_required' => (bool) env('ADMIN_2FA_REQUIRED', true),
    ],

    /*
    | Sign-in security for workspace users: after the password, a one-time code is emailed.
    | A browser that has entered a code is trusted for `window_hours`; after that the next
    | sign-in asks for a code again and any session still open is signed out.
    | LOGIN_OTP_ENABLED=false switches the code step off (emergency use: mail outage).
    */
    'login_otp' => [
        'enabled' => (bool) env('LOGIN_OTP_ENABLED', true),
        'window_hours' => (int) env('LOGIN_OTP_WINDOW_HOURS', 24),
    ],

    // Demo and contact requests from the public website are emailed here.
    'sales_email' => env('ENGAGE_SALES_EMAIL') ?: 'info@10xdigital.ae', // an empty value in .env falls back too

    'plans' => [
        // Tenants without a live subscription resolve entitlements from this plan.
        'fallback' => 'free',
        // New workspaces start a trial on this plan (blueprint: 14 days, full Pro).
        'trial_plan' => 'pro',
        'trial_days' => 14,
    ],

    'entitlements' => [
        'cache_ttl' => 300,
        'lock_seconds' => 10,
    ],

    'invitations' => [
        'ttl_hours' => 168,
    ],

    'partitions' => [
        'months_ahead' => 3,
        // Monthly RANGE partitioned tables maintained by `engage:partitions`.
        'monthly' => ['audit_log', 'webhook_inbound_log'],
    ],

    /*
    | Meta / WhatsApp Cloud API (Tech Provider). All Graph calls are server-side with business tokens.
    */
    'meta' => [
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'graph_version' => env('META_GRAPH_VERSION', 'v26.0'),
        'graph_url' => rtrim((string) env('META_GRAPH_URL', 'https://graph.facebook.com'), '/'),
        // Facebook Login for Business configuration used by Embedded Signup v4.
        'embedded_signup_config_id' => env('META_ES_CONFIG_ID'),
        'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),
        // Coexistence: connect a number that stays on the WhatsApp Business app (Embedded Signup v4,
        // featureType whatsapp_business_app_onboarding). It has its own Facebook Login for Business
        // configuration; when META_COEXISTENCE_CONFIG_ID is empty the standard one is used.
        // On as soon as that ID is set; META_COEXISTENCE_ENABLED=false is the platform-wide kill
        // switch. One workspace is switched off with an entitlement override in Super Admin.
        'coexistence_config_id' => env('META_COEXISTENCE_CONFIG_ID'),
        'coexistence_enabled' => (bool) env('META_COEXISTENCE_ENABLED', (string) env('META_COEXISTENCE_CONFIG_ID', '') !== ''),
        // Hours after which an import that stopped sending data is closed, so the inbox does not
        // show "syncing" forever (Meta gives no explicit "finished" signal when a phone goes offline).
        'coexistence_sync_stale_hours' => (int) env('META_COEXISTENCE_SYNC_STALE_HOURS', 72),
        // Load control for the import of WhatsApp Business app data: records are imported in batches
        // of this size with this pause between batches, one batch at a time per workspace.
        // 100 every 5 seconds ≈ 1,200 records a minute per workspace. Raise for speed, lower for calm.
        'coexistence_import_batch' => (int) env('META_COEXISTENCE_IMPORT_BATCH', 100),
        'coexistence_import_pause_seconds' => (int) env('META_COEXISTENCE_IMPORT_PAUSE_SECONDS', 5),
        // Onboarding stops when the WABA is still subscribed to another provider's app
        // (GET /<WABA_ID>/subscribed_apps). Comma-separated Meta app IDs listed in
        // META_ALLOWED_OTHER_APP_IDS are tolerated (e.g. a second app of your own).
        'block_other_subscribed_apps' => (bool) env('META_BLOCK_OTHER_SUBSCRIBED_APPS', true),
        // Same Meta app, different installation (production vs staging): refuse a WABA our app is
        // already subscribed to when this installation has never connected it.
        'block_other_environments' => (bool) env('META_BLOCK_OTHER_ENVIRONMENTS', true),
        'allowed_other_app_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('META_ALLOWED_OTHER_APP_IDS', ''))))),
        // Marketing Messages API (MM API). Campaign marketing templates of an onboarded account go
        // through /marketing_messages; everything else, and every account not onboarded, uses the
        // Cloud API. Switching `enabled` off sends everything through the Cloud API again.
        'marketing_messages' => [
            'enabled' => (bool) env('META_MM_ENABLED', true),
            // Ask for the Marketing Messages terms inside Embedded Signup (extras.features).
            'in_signup' => (bool) env('META_MM_IN_SIGNUP', true),
            // Share delivery/read signals with Meta for delivery optimisation (message_activity_sharing).
            'share_activity' => (bool) env('META_MM_SHARE_ACTIVITY', true),
            // Optional: the partner's own system-user token for Meta's Intent API (asks a business's
            // admins to accept the terms). Without it the business's own token is tried.
            'partner_token' => env('META_PARTNER_SYSTEM_TOKEN'),
            // A request nobody answered can be sent again after this many days.
            'request_retry_days' => (int) env('META_MM_REQUEST_RETRY_DAYS', 7),
        ],
        // LOCAL ONLY: answer Graph calls in-process (sending works offline, no Meta account).
        // Ignored outside APP_ENV=local. See App\Infrastructure\Meta\Fake\FakeMeta.
        'fake' => (bool) env('META_FAKE', false),
        'timeout' => (int) env('META_HTTP_TIMEOUT', 20),
        // Coexistence history chunks can describe thousands of messages.
        // Coexistence history webhooks carry thousands of messages each and can be tens of MB. A body
        // refused here (413) is a chunk of the customer's history Meta keeps retrying, so the cap
        // matches what PHP (post_max_size 110M) and Caddy (110MB) accept.
        'webhook_max_body_kb' => (int) env('META_WEBHOOK_MAX_BODY_KB', 102400),
        'webhook_retention_days' => (int) env('META_WEBHOOK_RETENTION_DAYS', 90),
        'deletion_status_url' => env('META_DELETION_STATUS_URL'), // default: APP_URL/deletion-status
        // Fixed throughput for numbers shared with the WhatsApp Business app (Meta: 20 mps).
        'coexistence_max_mps' => 20,
        // Used when a plan has no messages-per-second value (never higher than this: fail safe).
        // Our own ceilings (35 API, 15 coexistence) are hard-coded in SendThroughput.
        'fallback_max_mps' => 20,
        'default_max_mps' => 80,
    ],

    /*
    | Messaging (Phase 3)
    */
    'messaging' => [
        // Customer service window opened by an inbound message (Meta: 24 hours).
        'window_hours' => 24,
        // Case-insensitive, whole-message match (after trimming punctuation). Blueprint: STOP/START.
        'stop_keywords' => ['stop', 'unsubscribe', 'stopall', 'cancel', 'end', 'quit', 'opt out', 'optout', 'إيقاف', 'الغاء', 'إلغاء'],
        'start_keywords' => ['start', 'subscribe', 'unstop', 'opt in', 'optin', 'اشتراك'],
        'media_disk' => env('ENGAGE_MEDIA_DISK', 'local'),
        // Cloud API limits (bytes) per media type — enforced before upload.
        'media_limits' => [
            'image' => ['max' => 5 * 1024 * 1024, 'mimes' => ['image/jpeg', 'image/png']],
            'video' => ['max' => 16 * 1024 * 1024, 'mimes' => ['video/mp4', 'video/3gpp']],
            'audio' => ['max' => 16 * 1024 * 1024, 'mimes' => ['audio/aac', 'audio/amr', 'audio/mpeg', 'audio/mp4', 'audio/ogg']],
            'document' => ['max' => 100 * 1024 * 1024, 'mimes' => [
                'text/plain', 'application/pdf', 'application/vnd.ms-powerpoint', 'application/msword', 'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]],
            'sticker' => ['max' => 500 * 1024, 'mimes' => ['image/webp']],
        ],
        // Uploaded media IDs stay valid on Meta for 30 days; re-upload after this.
        'meta_media_ttl_days' => 29,
    ],

    /*
    | Secrets (business tokens, registration PINs). Encrypted with a dedicated keyring, not APP_KEY,
    | so the app key can rotate independently. Format: "k1:base64:...,k2:base64:..." — the first
    | key encrypts, all keys decrypt. `php artisan engage:secrets:rotate` re-encrypts.
    */
    'secrets' => [
        'keys' => env('ENGAGE_SECRETS_KEYS'),
    ],

    'api' => [
        'rate_limit_per_minute' => (int) env('API_RATE_LIMIT', 300),
        'login_attempts_per_minute' => 10,
    ],

    /*
    | Integrations → Shopify. The 10X Engage app is created once in Shopify's Dev Dashboard; its
    | Client ID and secret go here. Redirect URL to allow there: <APP_URL>/api/integrations/shopify/callback
    | Webhooks (and the three compliance webhooks) go to: <APP_URL>/api/integrations/shopify/webhook
    */
    'shopify' => [
        'client_id' => env('SHOPIFY_CLIENT_ID'),
        'client_secret' => env('SHOPIFY_CLIENT_SECRET'),
        'scopes' => env('SHOPIFY_SCOPES', 'read_orders'),
        'api_version' => env('SHOPIFY_API_VERSION', '2026-10'),
    ],

    'auth' => [
        // Two-factor sign-in is optional for everyone (Settings › Two-factor sign-in). Set
        // OWNER_TWO_FACTOR_REQUIRED=true to make workspace Owners turn it on before using the workspace.
        'owner_two_factor_required' => (bool) env('OWNER_TWO_FACTOR_REQUIRED', false),
    ],

    'growth' => [
        // Change Notice CN-01: internal alert threshold (accepted taps per workspace per calendar month). Never shown to clients, never blocks.
        'accepted_taps_alert_threshold' => (int) env('GROWTH_TAPS_ALERT_THRESHOLD', 100000),
        // Website tracker (R1): the public collector the tracker script and its beacons use, and the
        // host its routes answer on. Kill switch: GROWTH_TRACKER_ENABLED=false turns every site's
        // tracking off within 5 minutes (links open untracked).
        'collector_url' => env('GROWTH_COLLECTOR_URL', 'https://e.10xdigital.ae'),
        'collector_host' => env('GROWTH_COLLECTOR_HOST'),
        'tracker_enabled' => (bool) env('GROWTH_TRACKER_ENABLED', true),
        // Collector abuse limits (Collector::limits). A refused tap loses its attribution silently, so
        // these protect against abuse only: per site, per visitor (IP + the tracker's tab key) and a
        // generous per-IP backstop for carrier-grade NAT, offices and malls.
        'collector' => [
            'site_per_5s' => (int) env('GROWTH_SITE_TAPS_PER_5S', 30),
            'site_per_minute' => (int) env('GROWTH_SITE_TAPS_PER_MINUTE', 600),
            'visitor_per_4s' => 12,
            'visitor_per_hour' => 300,
            'ip_per_4s' => (int) env('GROWTH_IP_TAPS_PER_4S', 60),
            'ip_per_hour' => (int) env('GROWTH_IP_TAPS_PER_HOUR', 3000),
        ],
    ],

    'alerts' => [
        // Internal alerts for the 10X Engage team (e.g. a WhatsApp number that needs attention). Empty = server log only.
        'email' => env('ENGAGE_ALERTS_EMAIL'),
    ],
];
