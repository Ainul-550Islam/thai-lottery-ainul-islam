<?php

declare(strict_types=1);

use App\Support\Config\StrictBoolean;

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
    // The explicit `false` / `true` fallbacks are kept even though orClosed()
    // and orDefault() supply their own: the shipped default belongs in the line
    // a reviewer reads, and an existing test asserts it is spelled out here.
    'enabled' => StrictBoolean::orClosed(env('REAL_MONEY_ENABLED', false)),
    'require_readiness_pass' => StrictBoolean::orDefault(env('REAL_MONEY_REQUIRE_READINESS_PASS', true), true),

    /*
    |----------------------------------------------------------------------
    | Switch provenance (diagnostics only — never a second switch).
    |----------------------------------------------------------------------
    |
    | `enabled` above is now a strict boolean: REAL_MONEY_ENABLED must be
    | written as exactly `true` or exactly `false`. Anything else — a
    | missing line, a blank value, `1`, `yes`, `on`, a stray quote — is
    | CLOSED, because a money gate must never open on a value somebody
    | did not deliberately write as a boolean.
    |
    | That is the safe direction, but on its own it is silent: an operator
    | who typed `REAL_MONEY_ENABLED=yes` would see a closed gate and no
    | explanation. These two keys carry the explanation. They classify the
    | raw environment value WITHOUT echoing it (a line that was meant to
    | hold a boolean may have been pasted with a secret in it), so the
    | readiness report can say "malformed:string" and name the fix.
    |
    | Nothing may read `enabled_source` to decide whether money moves.
    | RealMoneyGate::isOpen() reads `enabled` and only `enabled`.
    */
    'enabled_source' => StrictBoolean::describe(env('REAL_MONEY_ENABLED')),
    'require_readiness_pass_source' => StrictBoolean::describe(env('REAL_MONEY_REQUIRE_READINESS_PASS')),

    /*
    |----------------------------------------------------------------------
    | Licence fields — all must be non-empty for real money to be live.
    |----------------------------------------------------------------------
    */
    'license' => [
        'issuer' => (string) env('REAL_MONEY_LICENSE_ISSUER', ''),
        'scope' => (string) env('REAL_MONEY_LICENSE_SCOPE', ''),
        'expiry' => (string) env('REAL_MONEY_LICENSE_EXPIRY', ''),
        'countries' => (string) env('REAL_MONEY_LICENSE_COUNTRIES', ''),
        'signature' => (string) env('REAL_MONEY_LICENSE_SIGNATURE', ''),
    ],

    /*
    |----------------------------------------------------------------------
    | Authorised countries derived from the comma-separated env string.
    | Empty array = no country authorised = real money blocked.
    |----------------------------------------------------------------------
    */
    // Resolve during configuration loading: consumers need an array and Laravel
    // cannot serialize a Closure when `config:cache` builds a release artifact.
    'authorized_countries' => array_values(array_unique(array_map(
        'strtoupper',
        array_filter(array_map('trim', explode(',', (string) env('REAL_MONEY_LICENSE_COUNTRIES', ''))),
            static fn (string $country): bool => $country !== ''),
    ))),

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
        'code' => 'real_money_not_licensed',
        'message' => 'Real-money features are not licensed.',
        'status' => 403,
    ],

    /*
    |----------------------------------------------------------------------
    | Operation-level controls (MoneyOperationControlService)
    |----------------------------------------------------------------------
    |
    | The master switch above decides whether this DEPLOYMENT may move money.
    | These controls decide whether one OPERATION may, and they are read by
    | App\Services\Compliance\MoneyOperationControlService and reported by
    | `php artisan money:technical-readiness`.
    |
    | MODES
    |   enforce  a failing control REFUSES the operation
    |   report   a failing control is recorded in the decision as a warning
    |            and does not refuse
    |
    | BOTH NEW CONTROLS DEFAULT TO `report`, AND THAT IS DELIBERATE.
    | The master switch is enforced unconditionally and always has been, so
    | wiring this service into an existing money path can never widen what that
    | path accepts. The two controls below are new, and a new readiness verdict
    | that starts refusing live deposits the moment it is deployed is a change
    | to money behaviour made by a config file nobody reviewed. An operator must
    | be able to READ the answer on a staging box — `money:technical-readiness`
    | — before it is allowed to decide anything. Flip one key to `enforce` and
    | the same verdict refuses.
    |
    | An unrecognised value falls back to `report`: a typo in an env var must
    | not silently start refusing live money movements.
    |
    */
    'control' => [
        // RealMoneyTechnicalReadinessService: chart of accounts, currency
        // contract, ledger invariants, money tables, key material, custody
        // disks, queue and session drivers.
        'technical_readiness_mode' => strtolower(trim((string) env('REAL_MONEY_TECHNICAL_READINESS_MODE', 'report'))),

        // ProviderReadinessService: is there an enabled AND ready gateway lane
        // for the operation's currency and direction. Only consulted for the
        // operations that actually dispatch a provider (deposit, withdrawal,
        // payout) — a bet or a conversion moves money between the player and the
        // platform ledger, so a dark gateway lane cannot refuse it.
        'lane_readiness_mode' => strtolower(trim((string) env('REAL_MONEY_LANE_READINESS_MODE', 'report'))),

        /*
        |------------------------------------------------------------------
        | Amount bounds, as CONFIG KEY NAMES — not values.
        |------------------------------------------------------------------
        | MoneyOperationControlService owns no number of its own. Each entry
        | names the existing key that already bounds the operation, so display,
        | execution and admission all read one value and cannot drift:
        |
        |   deposit     finance.deposit.min / .max        (FINANCE_MIN_DEPOSIT / FINANCE_MAX_DEPOSIT)
        |   withdrawal  finance.withdrawal.min / .max     (FINANCE_MIN_WITHDRAWAL / FINANCE_MAX_WITHDRAWAL)
        |   bet         risk.user_limits.max_stake_per_bet (LOTTERY_MAX_BET_AMOUNT)
        |   conversion  conversions.limits.min_amount / .max_amount
        |   payout      unbounded — the four-eyes threshold at
        |               finance.prize_payout.require_approval_at_or_above is an
        |               APPROVAL rule owned by PayoutApprovalService, not a
        |               ceiling, and duplicating it here would create a second
        |               opinion about the same money.
        |
        | A null bound means "unbounded on this side". Nothing is defaulted: a
        | silently invented real-money ceiling would look configured while
        | expressing nobody's risk appetite — the same reason
        | risk.exposure.max_stake_per_number ships without a default.
        */
        'bounds' => [
            'deposit' => [
                'min' => 'finance.deposit.min',
                'max' => 'finance.deposit.max',
            ],
            'withdrawal' => [
                'min' => 'finance.withdrawal.min',
                'max' => 'finance.withdrawal.max',
            ],
            'bet' => [
                'min' => null,
                'max' => 'risk.user_limits.max_stake_per_bet',
            ],
            'payout' => [
                'min' => null,
                'max' => null,
            ],
            'conversion' => [
                'min' => 'conversions.limits.min_amount',
                'max' => 'conversions.limits.max_amount',
            ],
        ],
    ],

];
