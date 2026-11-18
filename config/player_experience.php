<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Player Experience
|--------------------------------------------------------------------------
|
| Every number in this file is a CONSTANT OF POLICY, never a result. Nothing
| here is a balance, a score, a trend or a rate: those are derived from real
| rows at request time (or returned as null when the rows do not exist). A
| configuration file that carried a plausible-looking default such as
| '+5.2%' or '100% secure' would be an invented number with a config file's
| authority, which is exactly what Batch 3 forbids.
|
| Money convention: every amount below is an exact decimal STRING, parsed by
| bcmath where it is used. No float appears in this file and none may be
| introduced later.
|
| Responsibility split:
|   - this file   : policy (limits, weights, windows, claim vocabulary)
|   - database     : facts (balances, attestations, snapshots, campaigns)
|   - services     : derivation (score, trend, badge, feed projection)
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Bet Slip
    |--------------------------------------------------------------------------
    |
    | One active slip per member (enforced by a UNIQUE index in the schema, not
    | by this file). The slip is a DRAFT: it holds no money, reserves no number
    | and writes nothing outside its own two tables. Only confirm() moves money,
    | and it does so through the Batch-4.3 purchase pipeline.
    |
    */

    'bet_slip' => [
        'max_items' => (int) env('PLAYER_BET_SLIP_MAX_ITEMS', 20),
        'ttl_minutes' => (int) env('PLAYER_BET_SLIP_TTL_MINUTES', 120),
        'max_stake' => env('PLAYER_BET_SLIP_MAX_STAKE', '1000000.00'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Wallet Overview (display only)
    |--------------------------------------------------------------------------
    |
    | trend_days controls the snapshot lookback used for the trend percentage.
    | If there is no snapshot at or before that horizon the trend is null - it
    | is NEVER computed against zero or against an invented baseline.
    |
    | fx is DISPLAY ONLY. `enabled=false` (the default) means the overview
    | carries no FX block at all. The number, when present, is never used to
    | move money: conversions keep using the audited WalletConversion quote
    | service, which has its own rate source and its own ledger proof.
    |
    */

    'wallet' => [
        'trend_days' => (int) env('PLAYER_WALLET_TREND_DAYS', 7),
        'fx_display_quote' => env('PLAYER_WALLET_FX_QUOTE', 'USD'),
        'fx_display_enabled' => (bool) env('PLAYER_WALLET_FX_ENABLED', false),
        'fx_max_age_minutes' => (int) env('PLAYER_WALLET_FX_MAX_AGE_MINUTES', 1440),
        'fx_default_base' => env('PLAYER_WALLET_FX_BASE', 'THB'),
        'snapshot_retention_days' => (int) env('PLAYER_WALLET_SNAPSHOT_RETENTION_DAYS', 400),
    ],

    /*
    |--------------------------------------------------------------------------
    | FX Provider
    |--------------------------------------------------------------------------
    |
    | Default driver is `null`: it never performs a network call and always
    | answers "no rate". A deployment that wants display FX switches the driver
    | to `manual`, which reads the admin-entered row in fx_rate_snapshots.
    |
    | There is no `http` driver in this batch on purpose: an outbound call on
    | the money path is a decision that belongs to a reviewed change, not to a
    | default configuration value.
    |
    */

    'fx' => [
        'driver' => env('PLAYER_FX_DRIVER', 'null'),
        'manual_source' => env('PLAYER_FX_MANUAL_SOURCE', 'manual_admin'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Score
    |--------------------------------------------------------------------------
    |
    | The score is `sum of the weights of the checks that PASSED`. It is not a
    | marketing figure: every weight below is attached to a real, verifiable
    | predicate on a real column. Weight arithmetic is integer; the percentage
    | is computed with integer division and floored, so 5 passed checks out of
    | a 100 point budget can never present as "fully secured".
    |
    | The weights must sum to exactly 100 for `score_percent` to be meaningful.
    | SecurityScoreConfigTest asserts that.
    |
    */

    'security' => [
        'score_weights' => [
            'two_factor' => (int) env('PLAYER_SECURITY_WEIGHT_TWO_FACTOR', 30),
            'email_verified' => (int) env('PLAYER_SECURITY_WEIGHT_EMAIL', 10),
            'phone_verified' => (int) env('PLAYER_SECURITY_WEIGHT_PHONE', 15),
            'kyc_verified' => (int) env('PLAYER_SECURITY_WEIGHT_KYC', 30),
            'no_suspicious_activity' => (int) env('PLAYER_SECURITY_WEIGHT_NO_ALERTS', 15),
        ],

        /*
         | Label thresholds are lower bounds, evaluated highest-first. The label
         | is a statement about the SCORE, never about the platform: no label
         | says "secure", "100%" or anything a licence claim could hide behind.
         */
        'score_labels' => [
            'strong' => (int) env('PLAYER_SECURITY_LABEL_STRONG', 90),
            'good' => (int) env('PLAYER_SECURITY_LABEL_GOOD', 70),
            'fair' => (int) env('PLAYER_SECURITY_LABEL_FAIR', 40),
            'weak' => 0,
        ],

        // A security alert inside this window, still unresolved, fails the
        // `no_suspicious_activity` check.
        'suspicious_window_days' => (int) env('PLAYER_SECURITY_SUSPICIOUS_WINDOW_DAYS', 30),
        'alerts_feed_limit' => (int) env('PLAYER_SECURITY_ALERTS_LIMIT', 20),
        'login_events_limit' => (int) env('PLAYER_SECURITY_LOGIN_EVENTS_LIMIT', 4),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Mandatory categories can NEVER be disabled by a member: the service and
    | the request validator both refuse, and the dispatcher ignores a stored
    | `enabled=false` row for these categories. This is the list the operator
    | supplies; the defaults below are the statutory floor already pronounced
    | in App\Enums\NotificationEventType::isMandatory().
    |
    */

    'notifications' => [
        'mandatory_categories' => [
            'security',
            'deposit_status',
            'withdrawal_status',
            'kyc',
            'responsible_gaming',
            'account_restriction',
        ],

        // Categories a member may switch off. Anything not in this list and not
        // mandatory is treated as optional as well, which keeps the vocabulary
        // open without granting a switch over a mandatory row.
        'optional_categories' => [
            'marketing',
            'promotions',
            'loyalty',
            'results',
            'chat',
        ],

        'channels' => ['in_app', 'push', 'email', 'sms'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Support
    |--------------------------------------------------------------------------
    |
    | `hours` describes the PUBLISHED service window only. It is displayed, and
    | it is not used to refuse a conversation: a member may open a conversation
    | at 03:00 and the queue holds it. Refusing on the clock would lose the
    | ticket and the evidence with it.
    |
    */

    'support' => [
        'hours' => env('PLAYER_SUPPORT_HOURS', '08:00-22:00'),
        'timezone' => env('PLAYER_SUPPORT_TIMEZONE', 'Asia/Bangkok'),
        'max_open_conversations' => (int) env('PLAYER_SUPPORT_MAX_OPEN', 3),
        'max_message_length' => (int) env('PLAYER_SUPPORT_MAX_MESSAGE_LENGTH', 4000),
        'max_list_limit' => (int) env('PLAYER_SUPPORT_MAX_LIST', 50),
        'rating_min' => 1,
        'rating_max' => 5,
        // Support reuses the chat stack. This flag documents that reuse; when
        // false the service refuses rather than creating a second inbox.
        'delegate_to_chat_stack' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Promotions / Points Multiplier
    |--------------------------------------------------------------------------
    |
    | `multiplier_stacking` is `highest` by default: the single highest active
    | campaign wins and multipliers never multiply each other. `none` disables
    | campaign multipliers entirely, which must produce byte-identical points
    | to the pre-Batch-3 behaviour.
    |
    | bps = basis points. 15000 bps = 1.5x. Integer arithmetic only.
    |
    */

    'promotions' => [
        'multiplier_stacking' => env('PLAYER_PROMO_MULTIPLIER_STACKING', 'highest'),
        'active_list_limit' => (int) env('PLAYER_PROMO_ACTIVE_LIMIT', 20),
        'campaign_overlap_warning' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Compliance
    |--------------------------------------------------------------------------
    |
    | forbidden_claims is the deny-list MarketingClaimGuard scans for. These are
    | strings that assert a licence, an audit or a guarantee. None of them may
    | appear in a player-facing surface unless it is produced by
    | ComplianceBadgeService from a valid attestation row.
    |
    | badge_requirements maps a public badge key to the attestation code that
    | must exist and be valid for the badge to be returned. An empty map means
    | "no badges", which is the honest default for a system with no attestation
    | rows seeded.
    |
    */

    'compliance' => [
        /*
         | The deny list. Matching is case-insensitive (MarketingClaimGuard
         | lowercases both sides), so one entry covers every capitalisation -
         | the duplicated 'Secure'/'Security' variants that used to sit here were
         | noise, not coverage.
         |
         | WHAT BELONGS ON THIS LIST
         | A phrase that asserts a CREDENTIAL ("licensed by..."), an ABSOLUTE
         | ("100%", "always", "guaranteed") or a GUARANTEE ("you cannot lose").
         | Those are the three shapes only ComplianceBadgeService may emit, and
         | only from a valid attestation row.
         |
         | WHAT DOES NOT BELONG
         | Ordinary product copy. A guard that refuses "check the draw results"
         | is a guard operators route around.
         */
        'forbidden_claims' => [
            // Credentials the operator must not self-assert.
            'Licensed by GLO',
            'licensed by the government',
            'licensed by the gambling commission',
            'government approved',
            'officially licensed',
            'official government lottery operator',
            'official national lottery',

            // Absolutes and percentages.
            '100% secure',
            '100% safe',
            '99%',
            'bank-level security',
            'bank-grade certified security',
            'military-grade encryption',
            'absolutely safe',

            // Guarantees.
            'guaranteed win',
            'guaranteed payout',
            'guaranteed returns',
            'risk free',
            'risk-free',
            'you cannot lose',
            'instant payouts, always',
            'provably fair',
        ],

        'badge_requirements' => [
            'licence' => 'licence',
            'security_audit' => 'security_audit',
            'age_policy' => 'age_policy',
            'responsible_gaming' => 'responsible_gaming',
        ],

        // Always renderable without an attestation: they assert a POLICY the
        // operator applies to itself, not a third-party credential.
        'unattested_allowed' => ['age_18_plus', 'responsible_gaming_notice'],

        'attestation_expiry_warning_days' => (int) env('PLAYER_ATTESTATION_WARN_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ops
    |--------------------------------------------------------------------------
    |
    | scheduler_stale_seconds is how old the heartbeat may be before /health/ready
    | reports not-ready. The heartbeat itself is written to the cache store by
    | ops:heartbeat; it carries no member data and no secret.
    |
    */

    'health' => [
        'scheduler_stale_seconds' => (int) env('PLAYER_HEALTH_SCHEDULER_STALE', 180),
        'heartbeat_cache_key' => env('PLAYER_HEALTH_HEARTBEAT_KEY', 'ops:scheduler:heartbeat'),
        'heartbeat_ttl_seconds' => (int) env('PLAYER_HEALTH_HEARTBEAT_TTL', 900),
        'ledger_check_chunk' => (int) env('PLAYER_LEDGER_CHECK_CHUNK', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Loyalty
    |--------------------------------------------------------------------------
    |
    | Points multiplier is applied at EARN time only and recorded on the points
    | ledger row, so an audit can recompute what was paid and why.
    |
    */

    'loyalty' => [
        'multiplier_audit_key' => 'points_multiplier_key',
        'max_multiplier_bps' => (int) env('PLAYER_LOYALTY_MAX_MULTIPLIER_BPS', 50000),
    ],
];
