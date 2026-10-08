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
        // A deep sync queue during an import is expected (that is the buffer doing its job): only alert when it is very old.
        'redis:sync' => 1800,
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
        // The heavy lane: imports of WhatsApp Business app history. A fixed, small pool ("simple"
        // balancing, no auto-scaling) is the governor that protects the database: however many
        // records arrive, at most this many workers ever write them.
        'supervisor-sync' => ['balance' => 'simple'] + $supervisor(['sync'], 1, 3, 180, 1),
    ],

    /*
    | Every supervisor that must run in an environment has to be LISTED for it: one that only
    | appears in "defaults" is never started. (tests/Feature/QueueLanesTest guards this.)
    |
    | Production worker budget. Each worker holds one database connection, so the sum of the
    | maximums below (default 45) plus the web server must stay under Postgres max_connections
    | (200, set in deploy/docker-compose.prod.yml). Raise a lane with its HORIZON_* variable.
    */
    'environments' => [
        'production' => [
            'supervisor-critical' => ['minProcesses' => 2, 'maxProcesses' => (int) env('HORIZON_CRITICAL_PROCESSES', 4)],
            'supervisor-webhooks' => ['minProcesses' => 2, 'maxProcesses' => (int) env('HORIZON_WEBHOOKS_PROCESSES', 12)],
            'supervisor-messaging' => ['minProcesses' => 3, 'maxProcesses' => (int) env('HORIZON_MESSAGING_PROCESSES', 16)],
            'supervisor-default' => ['maxProcesses' => (int) env('HORIZON_DEFAULT_PROCESSES', 8)],
            'supervisor-maintenance' => ['maxProcesses' => 2],
            'supervisor-sync' => ['maxProcesses' => (int) env('HORIZON_SYNC_PROCESSES', 3)],
        ],
        'local' => [
            'supervisor-critical' => ['maxProcesses' => 2],
            'supervisor-webhooks' => ['maxProcesses' => 2],
            'supervisor-messaging' => ['maxProcesses' => 2],
            'supervisor-default' => ['maxProcesses' => 2],
            'supervisor-maintenance' => ['maxProcesses' => 1],
            'supervisor-sync' => ['maxProcesses' => 1],
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
