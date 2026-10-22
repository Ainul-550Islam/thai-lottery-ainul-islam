<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Conversion Tooling Configuration (Audit §D6)
|--------------------------------------------------------------------------
|
| Owns the seven conversion sub-systems introduced in §D6:
|   1. Promo / coupon redemption (`coupons`)
|   2. Push notification provider delivery (`push` — FCM & OneSignal)
|   3. Live support chat widget (`live_chat` — Crisp, Intercom & Tawk.to)
|   4. Public referral landing links & attribution (`referrals`)
|   5. Loyalty points accrual & reward catalogue (`loyalty`)
|   6. Verified social proof & moderated member testimonials (`social_proof`)
|   7. Deterministic feature-flag A/B experimentation (`experiments`)
|
| RESPONSIBLE GAMBLING INVARIANT
| ------------------------------
| Self-exclusion checks on promotional coupons, loyalty bonus redemptions,
| social-proof inducement banners, and promotional A/B variants are NOT
| configurable here: they are enforced unconditionally in code.
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | 1. Promo / Coupon Redemption Engine
    |----------------------------------------------------------------------
    */
    'coupons' => [
        'enabled' => (bool) env('COUPONS_ENABLED', true),
        'currency' => (string) env('COUPONS_DEFAULT_CURRENCY', 'THB'),

        // Hard circuit-breaker ceiling per single redemption in major units.
        // Prevents a misconfigured admin coupon from minting unbounded funds.
        'max_grant_ceiling' => (string) env('COUPONS_MAX_GRANT_CEILING', '5000.00'),

        // Allowed normalised coupon code pattern (uppercase ASCII + digits + hyphen).
        'code_pattern' => '/^[A-Z0-9][A-Z0-9_-]{2,31}$/',
    ],

    /*
    |----------------------------------------------------------------------
    | 2. Push Notification Provider (FCM HTTP v1 / Legacy & OneSignal)
    |----------------------------------------------------------------------
    */
    'push' => [
        'enabled' => (bool) env('PUSH_NOTIFICATIONS_ENABLED', false),

        // Supported drivers: 'null', 'fcm', 'onesignal'
        'provider' => (string) env('PUSH_PROVIDER', 'null'),

        'timeout_seconds' => (int) env('PUSH_TIMEOUT_SECONDS', 4),

        'fcm' => [
            'project_id' => (string) env('FCM_PROJECT_ID', ''),
            'access_token' => (string) env('FCM_ACCESS_TOKEN', ''),
            'endpoint_base' => (string) env('FCM_ENDPOINT_BASE', 'https://fcm.googleapis.com/v1/projects'),
            'web_vapid_public_key' => (string) env('FCM_WEB_VAPID_PUBLIC_KEY', ''),
        ],

        'onesignal' => [
            'app_id' => (string) env('ONESIGNAL_APP_ID', ''),
            'rest_api_key' => (string) env('ONESIGNAL_REST_API_KEY', ''),
            'endpoint' => (string) env('ONESIGNAL_ENDPOINT', 'https://api.onesignal.com/notifications'),
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | 3. Live Support Chat Widget (Crisp / Intercom / Tawk.to)
    |----------------------------------------------------------------------
    */
    'live_chat' => [
        'enabled' => (bool) env('LIVE_CHAT_ENABLED', false),

        // Supported providers: 'crisp', 'intercom', 'tawk'
        'provider' => (string) env('LIVE_CHAT_PROVIDER', 'crisp'),

        // Crisp website UUID, Intercom app_id, or Tawk "propertyId/widgetId"
        'widget_id' => (string) env('LIVE_CHAT_WIDGET_ID', ''),

        // Shared HMAC secret for signed member identity verification.
        // When blank, member identifiers are NOT transmitted to third parties.
        'identity_secret' => (string) env('LIVE_CHAT_IDENTITY_SECRET', ''),

        // Defer third-party network requests until explicit user click or consent.
        'require_consent' => (bool) env('LIVE_CHAT_REQUIRE_CONSENT', true),

        // Route prefixes where third-party chat scripts must never load.
        'excluded_prefixes' => ['admin', 'filament', 'horizon', 'telescope'],
    ],

    /*
    |----------------------------------------------------------------------
    | 4. Public Referral Links & Attribution
    |----------------------------------------------------------------------
    */
    'referrals' => [
        'enabled' => (bool) env('REFERRAL_LINKS_ENABLED', true),
        'cookie_name' => (string) env('REFERRAL_COOKIE_NAME', 'tl_ref'),
        'cookie_ttl_minutes' => (int) env('REFERRAL_COOKIE_TTL_MINUTES', 43200), // 30 days
        'ip_hash_salt' => (string) env('REFERRAL_IP_HASH_SALT', ''),
        'dedup_window_minutes' => (int) env('REFERRAL_CLICK_DEDUP_MINUTES', 60),
    ],

    /*
    |----------------------------------------------------------------------
    | 5. Loyalty Points & Rewards Programme
    |----------------------------------------------------------------------
    */
    'loyalty' => [
        'enabled' => (bool) env('LOYALTY_ENABLED', true),
        'currency' => (string) env('LOYALTY_CURRENCY', 'THB'),

        // Base earn rate: settled wager major units required for 1 base point.
        // e.g. '10.00' => 100 THB wager earns 10 base points before tier multiplier.
        'unit_stake_per_point' => (string) env('LOYALTY_UNIT_STAKE_PER_POINT', '10.00'),

        'point_expiry_days' => (int) env('LOYALTY_POINT_EXPIRY_DAYS', 365),
        'max_daily_redemptions' => (int) env('LOYALTY_MAX_DAILY_REDEMPTIONS', 3),

        // Multipliers aligned with canonical keys in config/account_grades.php.
        'tier_multipliers' => [
            'bronze' => '1.00',
            'gold_plus' => '1.20',
            'platinum' => '1.50',
            'platinum_plus' => '1.75',
            'diamond' => '2.00',
            'diamond_plus' => '2.50',
        ],

        // Redeemable bonus rewards (credited to WalletType::Bonus).
        'rewards' => [
            'bonus_20' => [
                'name_key' => 'conversion.reward_bonus_20',
                'points_cost' => 200,
                'bonus_amount' => '20.00',
                'enabled' => true,
            ],
            'bonus_50' => [
                'name_key' => 'conversion.reward_bonus_50',
                'points_cost' => 450,
                'bonus_amount' => '50.00',
                'enabled' => true,
            ],
            'bonus_100' => [
                'name_key' => 'conversion.reward_bonus_100',
                'points_cost' => 850,
                'bonus_amount' => '100.00',
                'enabled' => true,
            ],
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | 6. Social Proof & Moderated Testimonials
    |----------------------------------------------------------------------
    */
    'social_proof' => [
        'enabled' => (bool) env('SOCIAL_PROOF_ENABLED', true),
        'recent_wins_limit' => (int) env('SOCIAL_PROOF_RECENT_WINS_LIMIT', 6),
        'testimonials_limit' => (int) env('SOCIAL_PROOF_TESTIMONIALS_LIMIT', 3),
        'min_display_payout' => (string) env('SOCIAL_PROOF_MIN_DISPLAY_PAYOUT', '100.00'),
        'quote_min_length' => 20,
        'quote_max_length' => 280,

        // Phrases prohibited in member testimonials under responsible-gambling rules
        // (no guaranteed returns, winning formulas, or debt-recovery claims).
        'prohibited_phrases' => [
            'guaranteed',
            'guarantee win',
            '100% win',
            'sure win',
            'never lose',
            'secret formula',
            'easy money',
            'get rich',
            'pay off debt',
            'ชนะแน่นอน',
            'การันตีผล',
            'สูตรรวย',
            'ได้เงินชัวร์',
            'নিশ্চিত জয়',
            '১০০% জয়',
            'গ্যারান্টি জয়',
        ],

        // Unconditional disclosure rendered beside any social-proof surface.
        'disclosure' => [
            'en' => 'Verified historical payouts reflect random draw results and never guarantee future outcomes. 18+ · Play responsibly.',
            'th' => 'รายการจ่ายรางวัลย้อนหลังที่ยืนยันแล้วเกิดจากการออกรางวัลแบบสุ่ม และไม่รับประกันผลลัพธ์ในอนาคต 18+ โปรดเล่นอย่างมีความรับผิดชอบ',
            'bn' => 'যাচাইকৃত পূর্ববর্তী পুরস্কারের তথ্য সম্পূর্ণ দৈব ড্র ফলাফলের প্রতিফলন এবং ভবিষ্যতের ফলাফলের নিশ্চয়তা দেয় না। ১৮+ · দায়িত্বশীলভাবে খেলুন।',
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | 7. Feature-Flag A/B Experimentation Engine
    |----------------------------------------------------------------------
    */
    'experiments' => [
        'enabled' => (bool) env('EXPERIMENTS_ENABLED', true),
        'salt' => (string) env('EXPERIMENTS_HASH_SALT', 'tl-exp-v1'),
        'record_exposures' => (bool) env('EXPERIMENTS_RECORD_EXPOSURES', false),

        'definitions' => [
            'home_hero_cta' => [
                'enabled' => (bool) env('EXP_HOME_HERO_CTA_ENABLED', false),
                'promotional' => true,
                'traffic_percent' => 100,
                'variants' => [
                    'control' => 50,
                    'results_first' => 50,
                ],
            ],
            'register_referral_callout' => [
                'enabled' => (bool) env('EXP_REGISTER_REFERRAL_CALLOUT_ENABLED', false),
                'promotional' => false,
                'traffic_percent' => 100,
                'variants' => [
                    'control' => 50,
                    'compact_hint' => 50,
                ],
            ],
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | 8. Verified Build Checksum & Download Telemetry (Audit §D10.2)
    |----------------------------------------------------------------------
    */
    'downloads' => [
        'tracking_enabled' => (bool) env('DOWNLOAD_TRACKING_ENABLED', false),
        'dedup_minutes' => (int) env('DOWNLOAD_TRACKING_DEDUP_MINUTES', 60),
    ],

];
