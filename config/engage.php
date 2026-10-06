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
        'graph_version' => env('META_GRAPH_VERSION', 'v25.0'),
        'graph_url' => rtrim((string) env('META_GRAPH_URL', 'https://graph.facebook.com'), '/'),
        // Facebook Login for Business configuration used by Embedded Signup v4.
        'embedded_signup_config_id' => env('META_ES_CONFIG_ID'),
        'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),
        // Coexistence (WhatsApp Business app numbers) stays off until the messaging pipeline can
        // ingest history / smb_message_echoes (the 24-hour sync window cannot be retried).
        'coexistence_enabled' => (bool) env('META_COEXISTENCE_ENABLED', false),
        // Onboarding stops when the WABA is still subscribed to another provider's app
        // (GET /<WABA_ID>/subscribed_apps). Comma-separated Meta app IDs listed in
        // META_ALLOWED_OTHER_APP_IDS are tolerated (e.g. a second app of your own).
        'block_other_subscribed_apps' => (bool) env('META_BLOCK_OTHER_SUBSCRIBED_APPS', true),
        // Same Meta app, different installation (production vs staging): refuse a WABA our app is
        // already subscribed to when this installation has never connected it.
        'block_other_environments' => (bool) env('META_BLOCK_OTHER_ENVIRONMENTS', true),
        'allowed_other_app_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('META_ALLOWED_OTHER_APP_IDS', ''))))),
        // LOCAL ONLY: answer Graph calls in-process (sending works offline, no Meta account).
        // Ignored outside APP_ENV=local. See App\Infrastructure\Meta\Fake\FakeMeta.
        'fake' => (bool) env('META_FAKE', false),
        'timeout' => (int) env('META_HTTP_TIMEOUT', 20),
        // Coexistence history chunks can describe thousands of messages.
        'webhook_max_body_kb' => (int) env('META_WEBHOOK_MAX_BODY_KB', 8192),
        'webhook_retention_days' => (int) env('META_WEBHOOK_RETENTION_DAYS', 90),
        'deletion_status_url' => env('META_DELETION_STATUS_URL'), // default: APP_URL/deletion-status
        // Fixed throughput for numbers shared with the WhatsApp Business app (Meta: 20 mps).
        'coexistence_max_mps' => 20,
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

];
