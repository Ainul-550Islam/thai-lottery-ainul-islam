<?php

declare(strict_types=1);

return [
    'lanes' => [
        'otp.account' => [
            'switch' => 'account.otp',
            'credentials' => [],
            'note' => 'OTP account configuration',
        ],
        'payment.bkash' => [
            'switch' => 'payment.gateways.bkash.enabled',
            'credentials' => [
                'payment.gateways.bkash.app_key',
                'payment.gateways.bkash.app_secret',
                'payment.gateways.bkash.username',
                'payment.gateways.bkash.password',
                'payment.gateways.bkash.base_url',
            ],
            'note' => 'bKash payment gateway integration',
        ],
        'captcha.turnstile' => [
            'switch' => 'captcha.turnstile.enabled',
            'credentials' => [
                'captcha.turnstile.site_key',
                'captcha.turnstile.secret_key',
            ],
            'note' => 'Cloudflare Turnstile captcha service',
        ],
        'payment.promptpay' => [
            'switch' => 'payment.gateways.promptpay.enabled',
            'credentials' => [
                'payment.gateways.promptpay.merchant_id',
                'payment.gateways.promptpay.api_key',
            ],
            'note' => 'PromptPay QR payment integration',
        ],
        'captcha.hcaptcha' => [
            'switch' => 'captcha.hcaptcha.enabled',
            'credentials' => [
                'captcha.hcaptcha.site_key',
                'captcha.hcaptcha.secret_key',
            ],
            'note' => 'hCaptcha service',
        ],
    ],
    'required_scheduled_commands' => [
        'settlement:verify-guards',
        'operations:readiness-report',
    ],
    'application_commands' => [
        'settlement:verify-guards',
        'operations:readiness-report',
        'cache:clear',
        'config:cache',
        'route:cache',
        'view:cache',
    ],
];
