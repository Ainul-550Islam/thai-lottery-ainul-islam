<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [

    'domain' => env('HORIZON_DOMAIN'),

    'path' => env('HORIZON_PATH', 'horizon'),

    'use' => 'default',

    'prefix' => env('HORIZON_PREFIX', 'horizon:fintech:'),

    'middleware' => ['web', 'auth', 'can:access-horizon'],

    'waits' => [
        'redis:settlement-high' => 30,
        'redis:payouts' => 30,
        'redis:commissions' => 60,
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

    'silenced' => [
        //
    ],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => true,

    'memory_limit' => 256,

    'defaults' => [
        'supervisor-payout' => [
            'connection' => 'redis',
            'queue' => ['payouts', 'settlement-high'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 64,
            'minProcesses' => 8,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 5,
            'timeout' => 90,
            'nice' => 0,
        ],
        'supervisor-settlement' => [
            'connection' => 'redis',
            'queue' => ['settlement'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'size',
            'maxProcesses' => 32,
            'minProcesses' => 4,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 512,
            'tries' => 3,
            'timeout' => 180,
            'nice' => 0,
        ],
        'supervisor-commissions' => [
            'connection' => 'redis',
            'queue' => ['commissions'],
            'balance' => 'simple',
            'maxProcesses' => 16,
            'minProcesses' => 2,
            'memory' => 128,
            'tries' => 5,
            'timeout' => 60,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-payout' => [
                'maxProcesses' => 64,
                'minProcesses' => 8,
            ],
            'supervisor-settlement' => [
                'maxProcesses' => 32,
                'minProcesses' => 4,
            ],
            'supervisor-commissions' => [
                'maxProcesses' => 16,
                'minProcesses' => 2,
            ],
        ],
    ],
];
