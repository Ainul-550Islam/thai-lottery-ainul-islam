<?php

declare(strict_types=1);

return [
    'currency' => env('REWARDS_CURRENCY', 'THB'),

    'cashback' => [
        'enabled' => (bool) env('REWARDS_CASHBACK_ENABLED', true),
        'target_wallet' => env('REWARDS_CASHBACK_TARGET_WALLET', 'main'),
        'default_period' => env('REWARDS_CASHBACK_DEFAULT_PERIOD', 'daily'),
        'default_basis' => env('REWARDS_CASHBACK_DEFAULT_BASIS', 'real_net_loss'),
        'default_min_claim' => (string) env('REWARDS_CASHBACK_MIN_CLAIM', '10.00'),
        'default_expiry_days' => (int) env('REWARDS_CASHBACK_EXPIRY_DAYS', 30),
        // Members processed per AccrueCashbackJob chunk / dry-run chunk.
        'accrual_chunk_size' => (int) env('REWARDS_CASHBACK_ACCRUAL_CHUNK', 200),

        'policies' => [
            'bronze' => [
                'rate_bps' => 50,
                'period' => 'daily',
                'basis' => 'real_net_loss',
                'min_claim' => '10.00',
                'max_per_period' => '500.00',
                'expiry_days' => 30,
                'active' => true,
            ],
            'gold_plus' => [
                'rate_bps' => 100,
                'period' => 'daily',
                'basis' => 'real_net_loss',
                'min_claim' => '10.00',
                'max_per_period' => '1500.00',
                'expiry_days' => 30,
                'active' => true,
            ],
            'platinum' => [
                'rate_bps' => 150,
                'period' => 'daily',
                'basis' => 'real_net_loss',
                'min_claim' => '10.00',
                'max_per_period' => '3000.00',
                'expiry_days' => 30,
                'active' => true,
            ],
            'platinum_plus' => [
                'rate_bps' => 200,
                'period' => 'daily',
                'basis' => 'real_net_loss',
                'min_claim' => '10.00',
                'max_per_period' => '5000.00',
                'expiry_days' => 30,
                'active' => true,
            ],
            'diamond' => [
                'rate_bps' => 250,
                'period' => 'daily',
                'basis' => 'real_net_loss',
                'min_claim' => '10.00',
                'max_per_period' => '10000.00',
                'expiry_days' => 30,
                'active' => true,
            ],
            'diamond_plus' => [
                'rate_bps' => 300,
                'period' => 'daily',
                'basis' => 'real_net_loss',
                'min_claim' => '10.00',
                'max_per_period' => '20000.00',
                'expiry_days' => 30,
                'active' => true,
            ],
        ],
    ],

    'catalog' => [
        'default_expiry_days' => (int) env('REWARDS_DEFAULT_EXPIRY_DAYS', 14),
        'default_items' => [
            [
                'code' => 'bonus_cash_100',
                'name' => 'Bonus Cash THB 100',
                'description' => 'Redeem loyalty points for THB 100 credited to your bonus wallet.',
                'type' => 'bonus_cash',
                'points_cost' => 1000,
                'payload' => [
                    'amount' => '100.00',
                    'currency' => 'THB',
                ],
                'stock' => null,
                'per_member_limit' => null,
                'active' => true,
            ],
            [
                'code' => 'cashback_boost_7d',
                'name' => '7-Day Cashback Boost (+50 bps)',
                'description' => 'Adds +0.50% cashback boost to settled real-money net losses for 7 days.',
                'type' => 'cashback_boost',
                'points_cost' => 2000,
                'payload' => [
                    'extra_bps' => 50,
                    'duration_days' => 7,
                ],
                'stock' => null,
                'per_member_limit' => 4,
                'active' => true,
            ],
            [
                'code' => 'free_bet_150',
                'name' => 'Free Bet Voucher THB 150',
                'description' => 'Issues a single-use THB 150 free-bet voucher valid for 14 days.',
                'type' => 'free_bet',
                'points_cost' => 3000,
                'payload' => [
                    'amount' => '150.00',
                    'currency' => 'THB',
                    'expires_in_days' => 14,
                ],
                'stock' => 500,
                'per_member_limit' => 5,
                'active' => true,
            ],
            [
                'code' => 'vip_upgrade_review',
                'name' => 'VIP Tier Fast-Track Review',
                'description' => 'Submits a VIP tier upgrade review request for your account.',
                'type' => 'vip_upgrade',
                'points_cost' => 5000,
                'payload' => [
                    'target_grade' => 'gold_plus',
                ],
                'stock' => 100,
                'per_member_limit' => 1,
                'active' => true,
            ],
        ],
    ],

    'free_bet' => [
        'default_expiry_days' => (int) env('REWARDS_FREE_BET_EXPIRY_DAYS', 14),
        'code_prefix' => 'FBV',
    ],

    'bank_accounts' => [
        'max_per_member' => (int) env('REWARDS_BANK_ACCOUNTS_MAX_PER_MEMBER', 5),
        'account_number_min_digits' => (int) env('REWARDS_BANK_ACCOUNT_MIN_DIGITS', 6),
        'account_number_max_digits' => (int) env('REWARDS_BANK_ACCOUNT_MAX_DIGITS', 24),
        'blind_index_key' => env('REWARDS_BANK_BLIND_INDEX_KEY'),
        'mismatch_override_min_note_length' => (int) env('REWARDS_BANK_MISMATCH_NOTE_MIN', 10),
        'name_honorifics' => ['mr', 'mrs', 'ms', 'miss', 'dr', 'นาย', 'นาง', 'นางสาว', 'น.ส.'],
        'supported_banks' => [
            'KBANK' => 'Kasikornbank',
            'SCB' => 'Siam Commercial Bank',
            'BBL' => 'Bangkok Bank',
            'KTB' => 'Krungthai Bank',
            'BAY' => 'Bank of Ayudhya (Krungsri)',
            'TTB' => 'TMBThanachart Bank',
            'GSB' => 'Government Savings Bank',
        ],
    ],

    'withdrawals' => [
        // When true, withdrawals without a verified member_bank_account_id are rejected.
        'require_verified_bank_account' => (bool) env('REWARDS_WITHDRAWAL_REQUIRE_VERIFIED_BANK', false),
    ],

    'login_history' => [
        'retention_days' => (int) env('REWARDS_LOGIN_HISTORY_RETENTION_DAYS', 180),
        'summary_window_days' => (int) env('REWARDS_LOGIN_SUMMARY_WINDOW_DAYS', 30),
        'per_page' => (int) env('REWARDS_LOGIN_HISTORY_PER_PAGE', 20),
        'max_per_page' => (int) env('REWARDS_LOGIN_HISTORY_MAX_PER_PAGE', 100),
        'identifier_hash_key' => env('REWARDS_LOGIN_IDENTIFIER_HASH_KEY'),
        // A repeated SUCCESS from the same identifier/ip/device inside this window is
        // recorded once (guards the Login-event + API-token double-write). Failed and
        // blocked attempts are never collapsed. 0 disables.
        'dedupe_window_seconds' => (int) env('REWARDS_LOGIN_DEDUPE_SECONDS', 5),
    ],

    // Single owner of the GeoIP driver setting (null = offline, zero network).
    'geoip' => [
        'driver' => env('REWARDS_GEOIP_DRIVER'),
    ],

    'referral' => [
        'active_window_days' => (int) env('REWARDS_ACTIVE_REFERRAL_WINDOW_DAYS', 30),
        'series_days' => (int) env('REWARDS_REFERRAL_SERIES_DAYS', 30),
        'team_per_page' => (int) env('REWARDS_REFERRAL_TEAM_PER_PAGE', 20),
        'max_team_per_page' => (int) env('REWARDS_REFERRAL_MAX_TEAM_PER_PAGE', 100),
        'share_path' => (string) env('REWARDS_REFERRAL_SHARE_PATH', '/register'),
        'default_commission_rate' => (string) env('REWARDS_DEFAULT_COMMISSION_RATE', '0.00'),
    ],

    'dashboard' => [
        'cache_ttl_seconds' => (int) env('REWARDS_DASHBOARD_CACHE_TTL_SECONDS', 30),
        'cache_prefix' => 'player_dashboard_summary:v1',
    ],
];
