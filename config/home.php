<?php

/*
|--------------------------------------------------------------------------
| Public Home page configuration
|--------------------------------------------------------------------------
|
| Support contact, app links and public campaigns are CONFIG only — never
| invented in Blade. Null / empty values render honest NOT_CONFIGURED or
| "No active promotions" states. Never put secrets, PII or admin metrics
| in this file.
|
*/

return [

    'support' => [
        // null = not configured → home shows support CTA without inventing contact.
        'email' => env('HOME_SUPPORT_EMAIL'),
        'phone' => env('HOME_SUPPORT_PHONE'),
        'hours' => env('HOME_SUPPORT_HOURS'),
        'route_name' => 'contact',
        'message_max_chars' => 2000,
    ],

    'app_links' => [
        'android' => env('HOME_APP_ANDROID_URL'),
        'ios' => env('HOME_APP_IOS_URL'),
        'pwa' => env('HOME_APP_PWA_URL'),
    ],

    // Public-safe campaign list. Empty by default — never seed fake offers.
    // Each item: id, title, description, valid_from, valid_until, status, cta_label, cta_url.
    'campaigns' => [
        // env-driven JSON is intentionally not used: operators edit this array
        // or a future admin UI. Expired windows are filtered at read time.
    ],

    'campaigns_max' => 6,

    'stats_cache_ttl_seconds' => (int) env('HOME_STATS_CACHE_TTL', 300),
    'page_cache_ttl_seconds' => (int) env('HOME_PAGE_CACHE_TTL', 60),

    'trust' => [
        'bullets' => [
            'Server-side authorization on every financial action',
            'Idempotent wallet and payout operations',
            'Append-only audit trail for money movements',
            'Webhook signature verification before settlement',
            'Transaction-safe ledger writes with row locks',
            'KYC-controlled withdrawals above the configured threshold',
        ],
    ],

];
