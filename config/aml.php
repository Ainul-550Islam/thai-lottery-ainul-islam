<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Money-flow AML observation
|--------------------------------------------------------------------------
|
| Every money flow may be OBSERVED by AmlRiskService. Observing writes an audit
| row with the verdict, and it refuses nothing. Refusal is a separate decision
| that an operator makes per flow, by moving that flow to `enforce`.
|
|   off      no assessment, no audit row
|   log      assess and record the verdict; never refuse; an unavailable
|            assessment is recorded and does not block the money flow
|   enforce  refuse on a CRITICAL verdict; an unavailable assessment also refuses
|
| A per-flow value under `flows` overrides `mode`. A null value inherits `mode`.
| An unrecognised value is treated as `enforce`, the strictest mode, so a typo
| in configuration cannot quietly switch an observed flow off.
|
| The thresholds, weights and watchlists are owned by AmlRiskService and are not
| set here. Nothing in this file opens a compliance case.
|
*/

return [
    'mode' => env('AML_MODE', 'log'),

    'flows' => [
        'deposit' => env('AML_FLOW_DEPOSIT'),
        'withdrawal' => env('AML_FLOW_WITHDRAWAL'),
        'bet' => env('AML_FLOW_BET'),
    ],

    // Seconds an assessment may be reused. Zero forces a fresh measurement.
    'freshness_seconds' => (int) env('AML_FRESHNESS_SECONDS', 300),
];
