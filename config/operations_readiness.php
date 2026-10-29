<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Operations readiness: provider lanes and required commands
|--------------------------------------------------------------------------
|
| Each lane names the switch that decides whether it is in use, the credentials
| it needs once it is in use, and the configuration keys that must EXIST for the
| lane to be declared at all. A key that exists but is empty is a configuration
| gap. A key that does not exist is a declaration gap, which is a different
| finding.
|
| ProviderReadinessService only reads configuration. It never changes it.
|
*/

return [
    'lanes' => [
        'payment.bkash' => [
            'switch' => 'payment.gateways.bkash.enabled',
            'credentials' => [
                'payment.gateways.bkash.app_key',
                'payment.gateways.bkash.app_secret',
                'payment.gateways.bkash.username',
                'payment.gateways.bkash.password',
                'payment.gateways.bkash.base_url',
            ],
            'declared' => [
                'payment.gateways.bkash.enabled',
                'payment.gateways.bkash.app_key',
            ],
            'note' => 'bKash callback authentication is not implemented for the provider IPN. Credentials alone do not make this lane ready.',
        ],
        'payment.nagad' => [
            'switch' => 'payment.gateways.nagad.enabled',
            'credentials' => [
                'payment.gateways.nagad.merchant_id',
                'payment.gateways.nagad.merchant_number',
                'payment.gateways.nagad.app_key',
                'payment.gateways.nagad.app_secret',
                'payment.gateways.nagad.public_key',
                'payment.gateways.nagad.private_key',
                'payment.gateways.nagad.base_url',
                'payment.gateways.nagad.webhook_secret',
            ],
            'declared' => [
                'payment.gateways.nagad.enabled',
                'payment.gateways.nagad.merchant_id',
            ],
            'note' => 'Nagad callback authentication and authoritative settlement are not verified. Credentials alone do not make this lane ready.',
        ],
        'payment.promptpay' => [
            'switch' => 'payment.gateways.promptpay.enabled',
            'credentials' => [
                'payment.gateways.promptpay.target',
                'payment.gateways.promptpay.webhook_secret',
            ],
            'declared' => [
                'payment.gateways.promptpay.enabled',
                'payment.gateways.promptpay.target',
            ],
            'note' => 'PromptPay QR. The inbound driver is not built, so enabling the switch changes nothing until it is.',
        ],
        'captcha.turnstile' => [
            'switch' => null,
            'credentials' => [
                'services.captcha.turnstile.site_key',
                'services.captcha.turnstile.secret',
            ],
            'declared' => [
                'services.captcha.turnstile.site_key',
                'services.captcha.turnstile.secret',
            ],
            'note' => 'Cloudflare Turnstile. Without both keys the bot challenge cannot run.',
        ],
        'captcha.hcaptcha' => [
            'switch' => null,
            'credentials' => [
                'services.captcha.hcaptcha.site_key',
                'services.captcha.hcaptcha.secret',
            ],
            'declared' => [
                'services.captcha.hcaptcha.site_key',
                'services.captcha.hcaptcha.secret',
            ],
            'note' => 'hCaptcha fallback. Declared, and empty until both keys are provisioned.',
        ],
        'otp.account' => [
            'switch' => null,
            'credentials' => [],
            'declared' => [
                'account.otp',
            ],
            'note' => 'Account OTP. No configuration section exists for it yet.',
        ],
    ],

    /*
    | Artisan commands that must appear in the schedule. A missing entry here is
    | reported as required_but_missing and makes operations:readiness-report exit 1.
    */
    'required_commands' => [
        'lottery:tick',
        'settlement:verify-guards',
    ],
];
