<?php

declare(strict_types=1);

return [
    'mode' => env('AML_MODE', 'log'),
    'freshness_seconds' => (int) env('AML_FRESHNESS_SECONDS', 300),
    'flows' => [
        'deposit' => env('AML_DEPOSIT_MODE', null),
        'withdrawal' => env('AML_WITHDRAWAL_MODE', null),
        'bet' => env('AML_BET_MODE', null),
    ],
];
