<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Real-money launch gate
    |--------------------------------------------------------------------------
    | Deposits, withdrawals and GLO prize payments are refused unless this is
    | true. It defaults to false, so a fresh deployment cannot move real money
    | until someone sets REAL_MONEY_ENABLED=true deliberately. Real-money
    | features are not launch-ready until licensing is confirmed.
    */
    'enabled' => (bool) env('REAL_MONEY_ENABLED', false),
];
