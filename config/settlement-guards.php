<?php

declare(strict_types=1);

return [
    /*
    | Master switch for the read-only settlement guards. When false, the guards
    | do nothing and report 'off'. This does not gate draw automation.
    */
    'enabled' => (bool) env('SETTLEMENT_GUARDS_ENABLED', true),

    /*
    | Default mode for every guard: log (record divergences), enforce (the command
    | exits 1 on divergence), or off. Unknown values degrade to log, never to off.
    */
    'mode' => env('SETTLEMENT_GUARDS_MODE', 'log'),

    /*
    | Per-guard override. null means "use the default mode above".
    */
    'guards' => [
        'settlement_agreement' => env('SETTLEMENT_GUARD_AGREEMENT_MODE'),
    ],

    /*
    | The audit operation name written with each observation.
    */
    'operation' => 'settlement_guard_observation',

    /*
    | Completed draws examined per chunk. Read-only, so the cost is only reads.
    */
    'chunk_size' => 200,
];
