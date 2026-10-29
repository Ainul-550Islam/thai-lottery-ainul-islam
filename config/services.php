<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Third-party service endpoints and credentials
|--------------------------------------------------------------------------
|
| THE ONE PLACE A URL LIVES. `glo` below is the endpoint the scheduled fetch
| command uses. App\Console\Commands\FetchGloOfficialDrawResultCommand reads it
| from here (falling back to config('glo.official_source')) so no command and no
| service contains a URL literal and a stale host cannot survive in a crontab.
|
| NOTHING IN THIS FILE REACHES THE BROWSER. The realtime client block lives in
| config/realtime.php and carries public identifiers only; every `secret`, `key`
| and `token` below is server-side and is never rendered into a view, a meta tag
| or a JSON payload.
|
*/

return [

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL', '#lottery-alerts'),
        ],
    ],

    // Existing active, draw-authorized maker used by the scheduled GLO intake.
    // No default identity is fabricated; a different operator must confirm.
    'system' => [
        'automated_operator_id' => env('GLO_AUTOMATED_OPERATOR_ID'),
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'bkash' => [
        'app_key' => env('BKASH_APP_KEY'),
        'app_secret' => env('BKASH_APP_SECRET'),
        'username' => env('BKASH_USERNAME'),
        'password' => env('BKASH_PASSWORD'),
        'sandbox' => env('BKASH_SANDBOX', true),
    ],

    'nagad' => [
        'app_key' => env('NAGAD_APP_KEY'),
        'app_secret' => env('NAGAD_APP_SECRET'),
        'public_key' => env('NAGAD_PUBLIC_KEY'),
        'sandbox' => env('NAGAD_SANDBOX', true),
    ],

    'crypto' => [
        'api_key' => env('CRYPTO_GATEWAY_API_KEY'),
        'webhook_secret' => env('CRYPTO_GATEWAY_WEBHOOK_SECRET'),
    ],

    'truemoney' => [
        'mobile' => env('TRUEMONEY_MERCHANT_MOBILE'),
    ],

    'kbank' => [
        'webhook_secret' => env('KBANK_WEBHOOK_SECRET'),
    ],

    'scb' => [
        'webhook_secret' => env('SCB_WEBHOOK_SECRET'),
    ],

    'sms' => [
        'api_key' => env('THAI_SMS_API_KEY'),
        'api_secret' => env('THAI_SMS_API_SECRET'),
        'sender_name' => env('THAI_SMS_SENDER'),
    ],

    'line' => [
        // Legacy LINE Notify (shut down 31 Mar 2025 — kept for reference only)
        'notify_token' => env('LINE_NOTIFY_TOKEN'),

        // LINE Messaging API (replacement)
        'messaging_channel_access_token' => env('LINE_MESSAGING_CHANNEL_ACCESS_TOKEN', ''),
        'messaging_user_id' => env('LINE_MESSAGING_USER_ID', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Government Lottery Office (GLO) official catalogue
    |--------------------------------------------------------------------------
    |
    | The documented public endpoints only. `base_url` is duplicated from
    | config/glo.php by design: the command path reads THIS file first because a
    | deployment may rotate the upstream host without touching the prize
    | catalogue, and the two must be able to differ during a migration. Neither
    | value may be hardcoded anywhere else.
    |
    | `timeout` and `connect_timeout` are seconds. A scheduled command that hangs
    | is indistinguishable from a scheduler that is not running, so both are
    | bounded and neither is left to a driver default.
    */
    'glo' => [
        'base_url' => (string) env('GLO_OFFICIAL_BASE_URL', env('GLO_API_BASE_URL', 'https://www.glo.or.th')),
        'latest_lottery_path' => (string) env('GLO_LATEST_LOTTERY_PATH', '/api/lottery/getLatestLottery'),
        'lottery_result_path' => (string) env('GLO_LOTTERY_RESULT_PATH', '/api/checking/getLotteryResult'),
        'timeout' => (int) env('GLO_REQUEST_TIMEOUT_SECONDS', 10),
        'connect_timeout' => (int) env('GLO_CONNECT_TIMEOUT_SECONDS', 3),
        'retries' => max(0, min(3, (int) env('GLO_REQUEST_RETRIES', 1))),
    ],

    /*
    |--------------------------------------------------------------------------
    | External CAPTCHA providers (server-side Siteverify)
    |--------------------------------------------------------------------------
    | Endpoints are pinned in ExternalCaptchaVerifier and are not configurable.
    | `allowed_hostnames` is the exact set of hostnames a Turnstile response may
    | carry. `actions` maps each server purpose to the widget action that must
    | match. Secrets stay server-side and are never echoed to the browser.
    */
    'captcha' => [
        'turnstile' => [
            'site_key' => env('TURNSTILE_SITE_KEY'),
            'secret' => env('TURNSTILE_SECRET_KEY'),
            'allowed_hostnames' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('TURNSTILE_ALLOWED_HOSTNAMES', (string) env('APP_HOST', 'localhost'))),
            ), static fn (string $host): bool => $host !== '')),
            'actions' => [
                'login' => 'login',
                'register' => 'register',
                'password_reset' => 'password_reset',
            ],
        ],
        'hcaptcha' => [
            'site_key' => env('HCAPTCHA_SITE_KEY'),
            'secret' => env('HCAPTCHA_SECRET_KEY'),
        ],
    ],

];
