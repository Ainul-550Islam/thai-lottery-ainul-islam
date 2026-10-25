<?php

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
        'notify_token' => env('LINE_NOTIFY_TOKEN'),
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
