<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Horizon Configuration — Thai Lottery
|--------------------------------------------------------------------------
|
| WHY THIS FILE EXISTS
| Horizon was not installed. With `QUEUE_CONNECTION=sync` in .env.example and
| `config/queue.php` defaulting to `database`, every "asynchronous" job —
| webhook processing, prize settlement, payout transfers, notification fan-out —
| ran inline inside the HTTP request or was polled off a database table by a
| single `queue:work` process described in deployment/supervisor. There was:
|
|   - no queue metrics, so "is the settlement backlog growing?" was unanswerable
|   - no supervisor balancing, so one slow payout worker starved notification
|     delivery
|   - no auto-scaling, so a draw-night spike queued behind a fixed worker count
|   - no visibility into failed jobs beyond a `failed_jobs` table
|
| The supervisor config that exists (`deployment/supervisor/thai-lottery-worker.conf`)
| is a reasonable single-process starting point and remains valid for small
| deployments. Horizon replaces it for anything with real throughput.
|
| QUEUE TOPOLOGY — the separation is the design, not an accident:
|
|   settlement  Money-moving draw settlement. Never shares a worker with
|               anything slow. Small worker count, high timeout, low tries.
|   payouts     Withdrawal/payout disbursement against external providers.
|               Its own pool, because an external API's latency must not
|               consume settlement capacity.
|   webhooks    Inbound provider callbacks. High concurrency, very short
|               timeout — a webhook that takes 30s is a provider bug, and
|               holding the connection open helps nobody.
|   notifications  Email/SMS/LINE fan-out. Slow, high volume, completely
|               expendable under load — this is the pool that absorbs a spike.
|   default     Everything else, plus scheduled jobs.
|
| RETRY SEMANTICS
| `tries` is deliberately LOW on money lanes. A payout job that fails five times
| against a provider is not a job that needs a sixth attempt; it is a job that
| needs an operator. Failed money jobs land in `failed_jobs`, are alerted on by
| QueueServiceProvider, and are replayed deliberately via
| `queue:dlq:replay` after a human has read the reason.
|
*/

return [

    'domain' => env('HORIZON_DOMAIN'),

    'path' => env('HORIZON_PATH', 'horizon'),

    'use' => 'default',

    'prefix' => env('HORIZON_PREFIX', Str::slug(env('APP_NAME', 'thai-lottery'), '_').'_horizon:'),

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Waits
    |--------------------------------------------------------------------------
    | A job waiting longer than the threshold fires a
    | LongWaitDetected event. QueueServiceProvider routes those to
    | OperationalAlertService. These numbers are alerts, not limits.
    */
    'waits' => [
        'redis:default' => 60,
        'redis:webhooks' => 10,
        'redis:settlement' => 120,
        'redis:payouts' => 300,
        'redis:notifications' => 600,
    ],

    'trim' => [
        'recent' => intdiv(env('HORIZON_TRIM_RECENT_MINUTES', 60), 1),
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,

    'memory_limit' => (int) env('HORIZON_MEMORY_LIMIT', 512),

    'defaults' => [
        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => (int) env('HORIZON_DEFAULT_MAX_PROCESSES', 6),
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 512,
            'tries' => 3,
            'timeout' => 90,
            'nice' => 0,
        ],

        // ── MONEY LANE: draw settlement ───────────────────────────────────
        'supervisor-settlement' => [
            'connection' => 'redis',
            'queue' => ['settlement'],
            'balance' => 'simple',
            'processes' => (int) env('HORIZON_SETTLEMENT_PROCESSES', 4),
            'maxTime' => 0,
            'maxJobs' => 500,
            'memory' => 1024,
            // Low tries on purpose: a chunk that cannot settle after 3 attempts
            // is an operator problem, not a retry problem.
            'tries' => 3,
            'timeout' => 120,
            'nice' => -5,
        ],

        // ── MONEY LANE: payouts ───────────────────────────────────────────
        'supervisor-payouts' => [
            'connection' => 'redis',
            'queue' => ['payouts'],
            'balance' => 'simple',
            'processes' => (int) env('HORIZON_PAYOUT_PROCESSES', 3),
            'maxTime' => 0,
            'maxJobs' => 200,
            'memory' => 512,
            'tries' => 2,
            'timeout' => 180,
            'nice' => -5,
        ],

        // ── Webhooks: short, concurrent, disposable ───────────────────────
        'supervisor-webhooks' => [
            'connection' => 'redis',
            'queue' => ['webhooks'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'size',
            'maxProcesses' => (int) env('HORIZON_WEBHOOK_MAX_PROCESSES', 12),
            'minProcesses' => 2,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 5,
            // A provider callback that needs 30s is a provider bug. Fail fast
            // and let the reconciliation job pick the status up from the API.
            'timeout' => 30,
            'nice' => 0,
        ],

        // ── Notifications: the pool that absorbs a spike ──────────────────
        'supervisor-notifications' => [
            'connection' => 'redis',
            'queue' => ['notifications'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => (int) env('HORIZON_NOTIFICATION_MAX_PROCESSES', 10),
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 3,
            'timeout' => 60,
            'nice' => 5,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-default' => [
                'maxProcesses' => 10,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
            'supervisor-settlement' => [
                'processes' => 8,
            ],
            'supervisor-payouts' => [
                'processes' => 4,
            ],
            'supervisor-webhooks' => [
                'maxProcesses' => 20,
            ],
            'supervisor-notifications' => [
                'maxProcesses' => 20,
            ],
        ],

        'local' => [
            'supervisor-default' => [
                'maxProcesses' => 3,
            ],
            'supervisor-settlement' => [
                'processes' => 1,
            ],
            'supervisor-payouts' => [
                'processes' => 1,
            ],
            'supervisor-notifications' => [
                'maxProcesses' => 2,
            ],
        ],
    ],
];
