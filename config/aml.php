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
|   off      no assessment, no audit row, nothing refused
|   monitor  assess and record the verdict; never refuse; an unavailable
|            assessment is recorded and does not block the money flow
|   enforce  refuse on a CRITICAL verdict; an unavailable assessment also refuses
|
| `monitor` is the operator-facing word. The historical spelling `log` is
| accepted and means exactly the same thing, so an existing environment that
| sets AML_MODE=log keeps its behaviour and needs no migration. Both are read by
| App\Services\Compliance\AmlScreeningService, which is the single authority on
| these three words — no controller may interpret the string itself.
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

    /*
    |--------------------------------------------------------------------------
    | Global mode
    |--------------------------------------------------------------------------
    |
    | A fresh DEVELOPMENT install defaults to `monitor`: the detector runs and
    | writes its verdicts and nothing is refused, which is what you want while
    | tuning thresholds.
    |
    | PRODUCTION DEFAULTS TO `enforce`, AND THAT DEFAULT IS ENFORCED AT RUNTIME.
    | A production deployment that never sets AML_MODE runs every money flow in
    | `enforce`; ProductionSafetyServiceProvider additionally refuses a
    | production request while the global mode is `off`, so screening cannot be
    | switched off by editing a config value under load. That check runs per
    | request, not only at boot, because config can be re-read from a cache that
    | changed.
    |
    */
    'mode' => (string) env('AML_MODE', env('APP_ENV') === 'production' ? 'enforce' : 'monitor'),

    'flows' => [
        'deposit' => env('AML_FLOW_DEPOSIT'),
        'withdrawal' => env('AML_FLOW_WITHDRAWAL'),
        'bet' => env('AML_FLOW_BET'),
    ],

    // Seconds an assessment may be reused. Zero forces a fresh measurement.
    'freshness_seconds' => (int) env('AML_FRESHNESS_SECONDS', 300),
];
