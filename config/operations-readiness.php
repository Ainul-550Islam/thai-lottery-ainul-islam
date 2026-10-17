<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Operations readiness (Part 63)
|--------------------------------------------------------------------------
|
| The declaration ProviderReadinessService reads: which provider lanes this
| deployment is expected to have, the switch that turns each one on, and
| the config keys that must hold values before the lane can work.
|
| A lane whose required keys do not exist AT ALL in configuration is
| reported LANE_NOT_DECLARED — a different answer from "declared but empty"
| (NOT_CONFIGURED) and from "on but unusable"
| (ENABLED_WITHOUT_CREDENTIALS). Nothing here is invented to fill a gap:
| a lane is declared only where a real configuration home exists.
|
*/

return [

    'lanes' => [

        'payment.bkash' => [
            'switch' => 'payment.gateways.bkash.enabled',
            'required' => [
                'payment.gateways.bkash.app_key',
                'payment.gateways.bkash.app_secret',
                'payment.gateways.bkash.username',
                'payment.gateways.bkash.password',
                'payment.gateways.bkash.base_url',
            ],
            'note' => 'bKash tokenized checkout; credentials come from the bKash merchant agreement.',
        ],

        'payment.promptpay' => [
            'switch' => 'payment.gateways.promptpay.enabled',
            'required' => [
                'payment.gateways.promptpay.target',
                'payment.gateways.promptpay.webhook_secret',
            ],
            'note' => 'PromptPay collection target plus the aggregator callback secret.',
        ],

        'payment.nagad' => [
            'switch' => 'payment.gateways.nagad.enabled',
            'required' => [
                'payment.gateways.nagad.merchant_id',
                'payment.gateways.nagad.app_key',
                'payment.gateways.nagad.app_secret',
                'payment.gateways.nagad.base_url',
            ],
            'note' => 'Nagad merchant credentials from the Nagad agreement.',
        ],

        'captcha.turnstile' => [
            'switch' => 'auth_security.captcha.enabled',
            'required' => [
                'auth_security.captcha.turnstile.site_key',
                'auth_security.captcha.turnstile.secret_key',
            ],
            'note' => 'Cloudflare Turnstile keys for the external CAPTCHA driver.',
        ],

        'captcha.hcaptcha' => [
            'switch' => null,
            'required' => [
                'auth_security.captcha.hcaptcha.site_key',
                'auth_security.captcha.hcaptcha.secret_key',
            ],
            'note' => 'hCaptcha keys, if that driver is selected instead of Turnstile.',
        ],

        // The account OTP lane has no configuration home anywhere in this
        // repository today (no OTP provider keys exist to point at), which
        // is exactly the state LANE_NOT_DECLARED exists to report.
        'otp.account' => [
            'switch' => null,
            'required' => [
                'account.otp',
            ],
            'note' => 'No OTP provider is configured; phone verification cannot send codes.',
        ],

    ],

    /*
    | Commands that must be on the console schedule. ScheduleCoverageService
    | measures the real schedule against this list; a guard nobody runs is
    | indistinguishable from a guard that does not exist.
    */
    'required_scheduled_commands' => [
        'settlement:verify-guards',
        'operations:readiness-report',
        'lottery:tick',
        'risk:provision-number-limits',
        'webhooks:purge-replay-guards',
    ],

];
