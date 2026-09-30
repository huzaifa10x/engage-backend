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
        // Monthly RANGE(created_at) partitioned tables maintained by `engage:partitions`.
        'monthly' => ['audit_log'],
    ],

    'api' => [
        'rate_limit_per_minute' => (int) env('API_RATE_LIMIT', 300),
        'login_attempts_per_minute' => 10,
    ],

];
