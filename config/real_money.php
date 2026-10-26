<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Real-Money License Gate Configuration
|--------------------------------------------------------------------------
|
| REAL-MONEY FEATURES (deposit, withdrawal, bet placement, payout) are
| DISABLED by default. They become active ONLY when every field below is
| present and valid in the environment:
|
|   REAL_MONEY_LICENSE_ISSUER   — legal entity that issued the licence
|   REAL_MONEY_LICENSE_SCOPE    — what the licence covers (e.g. "thai-lottery-betting")
|   REAL_MONEY_LICENSE_EXPIRY   — ISO-8601 expiry date (e.g. "2027-12-31")
|   REAL_MONEY_LICENSE_COUNTRIES — comma-separated authorised country codes (e.g. "TH,BD")
|   REAL_MONEY_LICENSE_SIGNATURE — operator-provided signature hash (audit only)
|
| If ANY field is missing, empty, or expired, real-money routes return
| 403 REAL_MONEY_NOT_LICENSED and no money can move.
|
| SECRETS: never write real keys here; read from environment only.
*/

return [

    /*
    |----------------------------------------------------------------------
    | Master switch (kept for backwards compatibility; the licence gate
    | below is the authoritative control).
    |----------------------------------------------------------------------
    */
    'enabled' => filter_var(env('REAL_MONEY_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |----------------------------------------------------------------------
    | Licence fields — all must be non-empty for real money to be live.
    |----------------------------------------------------------------------
    */
    'license' => [
        'issuer'     => (string) env('REAL_MONEY_LICENSE_ISSUER', ''),
        'scope'      => (string) env('REAL_MONEY_LICENSE_SCOPE', ''),
        'expiry'     => (string) env('REAL_MONEY_LICENSE_EXPIRY', ''),
        'countries'  => (string) env('REAL_MONEY_LICENSE_COUNTRIES', ''),
        'signature'  => (string) env('REAL_MONEY_LICENSE_SIGNATURE', ''),
    ],

    /*
    |----------------------------------------------------------------------
    | Authorised countries derived from the comma-separated env string.
    | Empty array = no country authorised = real money blocked.
    |----------------------------------------------------------------------
    */
    'authorized_countries' => function (): array {
        $raw = (string) env('REAL_MONEY_LICENSE_COUNTRIES', '');
        if ($raw === '') {
            return [];
        }
        return array_unique(array_map('strtoupper', array_filter(array_map('trim', explode(',', $raw)))));
    },

    /*
    |----------------------------------------------------------------------
    | Routes protected by the licence gate.
    |----------------------------------------------------------------------
    */
    'guarded_routes' => [
        'player.deposit.store',
        'player.withdraw.store',
        'player.bets.purchase',
        'player.bets.purchase.alias',
        'api.deposit.store',
        'api.withdrawal.store',
        'api.bet.store',
        'api.bulk-bet.store',
    ],

    /*
    |----------------------------------------------------------------------
    | Error response when real money is not licensed.
    |----------------------------------------------------------------------
    */
    'not_licensed' => [
        'code'    => 'real_money_not_licensed',
        'message' => 'Real-money features are not licensed.',
        'status'  => 403,
    ],

];
