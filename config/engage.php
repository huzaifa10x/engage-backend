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
