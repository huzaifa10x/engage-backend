<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/*
| Queue topology. Each supervisor scales independently; add supervisors (e.g. campaigns)
| only when the corresponding module ships. Job timeouts must stay below the queue
| connection's retry_after (config/queue.php, 90s).
*/

$supervisor = static fn (array $queues, int $min, int $max, int $timeout = 60, int $tries = 3): array => [
    'connection' => 'redis',
    'queue' => $queues,
    'balance' => 'auto',
    'autoScalingStrategy' => 'time',
    'minProcesses' => $min,
    'maxProcesses' => $max,
    'balanceMaxShift' => 2,
    'balanceCooldown' => 3,
    'maxTime' => 3600,
    'maxJobs' => 1000,
    'memory' => 256,
    'tries' => $tries,
    'timeout' => $timeout,
    'nice' => 0,
];

return [

    'name' => env('HORIZON_NAME', '10X Engage'),

    'domain' => env('HORIZON_DOMAIN', env('ADMIN_DOMAIN')),

    'path' => env('HORIZON_PATH', 'horizon'),

    'use' => 'queue',

    'prefix' => env('HORIZON_PREFIX', Str::slug((string) env('APP_NAME', 'engage'), '_').'_horizon:'),

    // Dashboard is Super Admin only (see App\Providers\HorizonServiceProvider).
    'middleware' => ['web', 'auth:admin'],

    'waits' => [
        'redis:critical' => 10,
        'redis:webhooks' => 15,
        'redis:messaging' => 30,
        'redis:default' => 60,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [],

    'silenced_tags' => [],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,

    'memory_limit' => 64,

    'defaults' => [
        'supervisor-critical' => $supervisor(['critical'], 1, 4, 30),
        'supervisor-webhooks' => $supervisor(['webhooks'], 1, 10, 30, 5),
        'supervisor-messaging' => $supervisor(['messaging'], 1, 10, 60, 5),
        'supervisor-default' => $supervisor(['default', 'notifications'], 1, 5),
        'supervisor-maintenance' => $supervisor(['maintenance'], 1, 2, 85, 1),
    ],

    'environments' => [
        'production' => [
            'supervisor-critical' => ['minProcesses' => 2, 'maxProcesses' => 10],
            'supervisor-webhooks' => ['minProcesses' => 3, 'maxProcesses' => 40],
            'supervisor-messaging' => ['minProcesses' => 3, 'maxProcesses' => 40],
            'supervisor-default' => ['maxProcesses' => 10],
        ],
        'local' => [
            'supervisor-critical' => ['maxProcesses' => 2],
            'supervisor-webhooks' => ['maxProcesses' => 2],
            'supervisor-messaging' => ['maxProcesses' => 2],
            'supervisor-default' => ['maxProcesses' => 2],
            'supervisor-maintenance' => ['maxProcesses' => 1],
        ],
    ],

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],

];
