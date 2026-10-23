<?php

declare(strict_types=1);

use App\Enums\ConversionFeeBearer;
use App\Enums\WalletConversionDirection;

/*
|--------------------------------------------------------------------------
| Wallet Conversion (Cash <-> Win) Configuration
|--------------------------------------------------------------------------
|
| Configuration only. No fee arithmetic, no policy decision and no database
| access lives in this file: the conversion services read these values and
| apply them. Every value an operator may need to change in production is
| declared here and nowhere else.
|
| THE FEE RATE IS NOT DECLARED HERE
| The rate is owned by config/fees.php (categories `cash_to_win` and
| `win_to_cash`, both 1.00% as published). This file only names WHICH public
| rule governs each direction. That indirection is deliberate: the member-
| facing fee page and the engine resolve the same rule key, which is the only
| way the published price and the charged price can be proven identical.
| Editing a rate here is impossible on purpose — change it in config/fees.php
| and every surface follows.
|
| WHAT A DIRECTION MUST SATISFY
| A conversion is refused unless the direction is enabled, the amount is
| inside the transaction envelope, the rolling windows have head-room, the
| source wallet has available balance, the member passes the identity and
| standing gates, and a fresh quote is presented. Each of those gates has a
| kill switch below so an operator can relax one of them without deploying
| code — and each has a safe default.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Master Switch
    |--------------------------------------------------------------------------
    |
    | When false, every conversion endpoint answers 503 with the stable code
    | `conversion_disabled` and no wallet is ever touched. The public fee page
    | keeps rendering the published schedule either way, because the schedule
    | describes the tariff, not the availability of the feature.
    |
    */

    'enabled' => env('CONVERSION_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | Conversions never cross currencies: both wallets are read and written in
    | the currency declared here, and a wallet whose currency differs is
    | refused with `currency_mismatch` rather than converted at an implicit
    | rate. A real FX lane, if ever added, must be its own product.
    |
    */

    'currency' => env('CONVERSION_CURRENCY', env('FINANCE_DEFAULT_CURRENCY', 'THB')),

    /*
    |--------------------------------------------------------------------------
    | Directions
    |--------------------------------------------------------------------------
    |
    | Each direction carries its own switch because operators routinely need to
    | stop money entering the Win wallet (a promotion mispriced, a settlement
    | incident) while leaving the exit lane open so members can still move
    | winnings back to a withdrawable balance. Freezing both because one is
    | unsafe traps member funds, which is never acceptable.
    |
    */

    'directions' => [

        WalletConversionDirection::CashToWin->value => [
            'enabled' => env('CONVERSION_CASH_TO_WIN_ENABLED', true),
            'label_key' => 'wallet_conversion.direction_cash_to_win',
            'requires_quote' => true,
            'description_key' => 'wallet_conversion.direction_cash_to_win_description',
        ],

        WalletConversionDirection::WinToCash->value => [
            'enabled' => env('CONVERSION_WIN_TO_CASH_ENABLED', true),
            'label_key' => 'wallet_conversion.direction_win_to_cash',
            'requires_quote' => true,
            'description_key' => 'wallet_conversion.direction_win_to_cash_description',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Amount Envelope
    |--------------------------------------------------------------------------
    |
    | Per-transaction bounds. The minimum exists so that a 1% fee on a trivial
    | amount cannot produce a zero-value movement that still writes ledger
    | rows; the maximum bounds the blast radius of a single mistaken entry.
    | The rolling windows are read under the same lock as the movement, so a
    | burst of concurrent requests cannot each see an empty window.
    |
    */

    'limits' => [
        'min_amount' => env('CONVERSION_MIN_AMOUNT', '10.00'),
        'max_amount' => env('CONVERSION_MAX_AMOUNT', '500000.00'),
        'daily_limit' => env('CONVERSION_DAILY_LIMIT', '1000000.00'),
        'monthly_limit' => env('CONVERSION_MONTHLY_LIMIT', '5000000.00'),
        'enforce_daily' => env('CONVERSION_ENFORCE_DAILY', true),
        'enforce_monthly' => env('CONVERSION_ENFORCE_MONTHLY', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fee
    |--------------------------------------------------------------------------
    |
    | The bearer decides which side of the movement the fee reduces. The scale
    | is the number of decimal places the fee is rounded to, ONCE, at the
    | moment the fee becomes an amount — never carried further as a fraction.
    |
    */

    'fee' => [
        'bearer' => env('CONVERSION_FEE_BEARER', ConversionFeeBearer::Source->value),
        'scale' => (int) env('CONVERSION_FEE_SCALE', 2),
        'rate_scale' => (int) env('CONVERSION_FEE_RATE_SCALE', 4),
        'rule_keys' => [
            WalletConversionDirection::CashToWin->value => 'fees.categories.cash_to_win',
            WalletConversionDirection::WinToCash->value => 'fees.categories.win_to_cash',
        ],
        // The public schedule's own version, surfaced on quotes and audit rows
        // so a fee can always be traced back to the tariff version in force.
        'rule_version_fallback' => '2',
    ],

    /*
    |--------------------------------------------------------------------------
    | Quotes
    |--------------------------------------------------------------------------
    |
    | A quote is a promise with an expiry. The TTL must be long enough for a
    | member to read the numbers and short enough that a stale price cannot be
    | executed into a moving market position. The expiry sweep command deletes
    | unexecuted quotes past this window.
    |
    */

    'quotes' => [
        'ttl_seconds' => (int) env('CONVERSION_QUOTE_TTL_SECONDS', 120),
        'require_for_execution' => env('CONVERSION_QUOTE_REQUIRED', true),
        'allow_reissue' => true,
        'max_open_per_user' => (int) env('CONVERSION_MAX_OPEN_QUOTES', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Identity and Standing Gates
    |--------------------------------------------------------------------------
    |
    | Each gate is a refusal the engine performs BEFORE any wallet lock is
    | taken, so a refused request costs nothing and leaves no trace on the
    | money path. `require_kyc` is on by default because a conversion lane is
    | the cheapest path an unverified account has to move value.
    |
    */

    'gates' => [
        'require_kyc' => env('CONVERSION_REQUIRE_KYC', true),
        'require_verified_mobile' => env('CONVERSION_REQUIRE_VERIFIED_MOBILE', false),
        'block_if_suspended' => env('CONVERSION_BLOCK_SUSPENDED', true),
        'block_if_self_excluded' => env('CONVERSION_BLOCK_SELF_EXCLUDED', true),
        'block_if_wallet_locked' => env('CONVERSION_BLOCK_WALLET_LOCKED', true),
        'allowed_account_statuses' => ['active'],
        'refusal_codes' => [
            'kyc' => 'kyc_not_verified',
            'standing' => 'account_suspended',
            'self_exclusion' => 'self_exclusion_active',
            'wallet_lock' => 'wallet_locked',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    |
    | Required for every member-initiated conversion by default: a double-tap
    | on a mobile browser must replay the first movement, never start a second.
    | The key is scoped per user AND per channel, so an operator retry can
    | never collide with a member key of the same value.
    |
    */

    'idempotency' => [
        'required' => env('CONVERSION_IDEMPOTENCY_REQUIRED', true),
        'min_key_length' => (int) env('CONVERSION_IDEMPOTENCY_MIN_LENGTH', 16),
        'max_key_length' => (int) env('CONVERSION_IDEMPOTENCY_MAX_LENGTH', 128),
        'scope' => 'wallet-conversion',
    ],

    /*
    |--------------------------------------------------------------------------
    | Reversal
    |--------------------------------------------------------------------------
    |
    | Reversal is an operator action, never a member one, and it is bounded by
    | a window. It posts the opposite of the original entries and writes a NEW
    | linking conversion row rather than mutating the completed one, so both
    | the original and its reversal remain visible in history.
    |
    */

    'reversal' => [
        'enabled' => env('CONVERSION_REVERSAL_ENABLED', true),
        'window_hours' => (int) env('CONVERSION_REVERSAL_WINDOW_HOURS', 24),
        'requires_reason' => true,
        'min_reason_length' => (int) env('CONVERSION_REVERSAL_MIN_REASON', 10),
        'allowed_channels' => ['admin', 'console'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ledger Accounts
    |--------------------------------------------------------------------------
    |
    | Base codes resolved per currency by LedgerPostingService::accountCodeFor().
    | The wallet side and the transfer counterpart already exist in the chart of
    | accounts (WalletService::ACCOUNT_PLAYER_LIABILITY and
    | WalletService::ACCOUNT_ADJUSTMENT_EQUITY). The fee revenue code is the
    | same account every other fee already posts to, so conversion income is not
    | hidden in a private bucket during reconciliation.
    |
    */

    'ledger' => [
        'wallet_account_code' => '2000',
        'transfer_counterpart_code' => '3000',
        'fee_revenue_account_code' => '4100',
        'reference_type' => 'wallet_conversion',
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Per channel of origin, applied by middleware before the controller runs.
    | The member bucket is per user; operator buckets are separate so a bulk
    | reconciliation can never exhaust a member's allowance.
    |
    */

    'alerts' => [
        // Operator-fault refusals (a switched-off lane, a locked wallet, a
        // currency mismatch) alert on their FIRST occurrence: they cannot be
        // caused by a member's own behaviour, so even one means the platform is
        // in a state somebody should look at now.
        'operator_fault_threshold' => (int) env('CONVERSION_OPERATOR_FAULT_THRESHOLD', 1),
        // Member-fault refusals (no balance, at a limit, an expired price) are
        // ordinary traffic, so only a spike is interesting. Twenty-five inside
        // the metrics window is well above a normal afternoon and well below a
        // promotion's worth of genuine demand.
        'refusal_spike_threshold' => (int) env('CONVERSION_REFUSAL_SPIKE_THRESHOLD', 25),
    ],

    'rate_limits' => [
        'user_per_minute' => (int) env('CONVERSION_USER_PER_MINUTE', 10),
        'user_per_day' => (int) env('CONVERSION_USER_PER_DAY', 50),
        'admin_per_minute' => (int) env('CONVERSION_ADMIN_PER_MINUTE', 60),
        'quote_per_minute' => (int) env('CONVERSION_QUOTE_PER_MINUTE', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | History
    |--------------------------------------------------------------------------
    */

    'history' => [
        'per_page' => (int) env('CONVERSION_HISTORY_PER_PAGE', 20),
        'max_per_page' => (int) env('CONVERSION_HISTORY_MAX_PER_PAGE', 100),
        'member_visible_statuses' => ['quoted', 'pending', 'processing', 'completed', 'failed', 'expired', 'reversed'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reconciliation
    |--------------------------------------------------------------------------
    |
    | `enabled` is the master switch for the scheduled pass. It exists because
    | the pass is the one part of this lane that is safe to stop in an incident:
    | the money has already moved by the time it runs, and an operator dealing
    | with a noisy alert needs a way to silence the alert without touching the
    | ledger, the engine or the queue worker.
    |
    | `tolerance` is the maximum drift a completed conversion may show between
    | its recorded amounts and its ledger postings before the pass reports
    | Mismatched. It exists as an explicit, tiny value rather than an implicit
    | zero so that a legitimate future rounding policy is a configuration
    | change and not a code change.
    |
    */

    'reconciliation' => [
        'enabled' => env('CONVERSION_RECONCILIATION_ENABLED', true),
        'tolerance' => env('CONVERSION_RECONCILIATION_TOLERANCE', '0.00'),
        'stale_after_minutes' => (int) env('CONVERSION_STALE_AFTER_MINUTES', 30),
        'max_mismatch_rows' => (int) env('CONVERSION_MAX_MISMATCH_ROWS', 200),
        'alert_on_mismatch' => env('CONVERSION_ALERT_ON_MISMATCH', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | A completed conversion always writes an in-app notification; email is
    | opt-in per event. Delivery failures are never allowed to roll back a
    | completed money movement — the notification service queues and logs.
    |
    */

    'notifications' => [
        'enabled' => env('CONVERSION_NOTIFICATIONS_ENABLED', true),
        'on_completed' => true,
        'on_failed' => true,
        'on_reversed' => true,
        'channels' => ['database'],
        // How long a stored conversion message stays deliverable. See
        // ConversionNotificationService: the notifications table requires the
        // column, and a month is long enough that a member who was offline for a
        // fortnight still learns what happened to their money.
        'expiry_days' => (int) env('CONVERSION_NOTIFICATION_EXPIRY_DAYS', 30),
        'queue' => env('CONVERSION_NOTIFICATION_QUEUE', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | User Interface
    |--------------------------------------------------------------------------
    */

    'ui' => [
        'route_prefix' => 'wallet/convert',
        'show_fee_breakdown' => true,
        'show_limits' => true,
        'default_direction' => WalletConversionDirection::CashToWin->value,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Retention
    |--------------------------------------------------------------------------
    |
    | Audit rows are financial history and are retained for the same horizon as
    | ledger entries. This number is a documented ceiling, not a cleanup
    | instruction: nothing in this feature deletes audit rows automatically.
    |
    */

    'audit' => [
        'retention_days' => (int) env('CONVERSION_AUDIT_RETENTION_DAYS', 2555),
        'record_ip' => env('CONVERSION_AUDIT_RECORD_IP', true),
        'record_user_agent' => env('CONVERSION_AUDIT_RECORD_USER_AGENT', true),
    ],

];
