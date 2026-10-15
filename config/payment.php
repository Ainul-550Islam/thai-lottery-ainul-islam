<?php

use App\Enums\PaymentMethod;

/*
|--------------------------------------------------------------------------
| Payment Configuration
|--------------------------------------------------------------------------
|
| Configuration only. No gateway client, HTTP call or signing implementation
| lives in this file. The concrete gateway drivers (Stripe, bKash, Nagad,
| Crypto, Bank Transfer) are bound in
| App\Services\Payment\PaymentGatewayManager and live in
| app/Services/Payment/Drivers — this file is the single place an operator
| enables a provider, supplies its credentials and, for manual bank
| settlement, supplies the REAL settlement account details.
|
| SECRETS
| Every credential is read from the environment and defaults to null. Never
| write a real key, secret, password or private key into this file.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Default Gateway
    |--------------------------------------------------------------------------
    */

    'default_gateway' => env('PAYMENT_DEFAULT_GATEWAY', 'stripe'),

    /*
    |--------------------------------------------------------------------------
    | Supported Currencies
    |--------------------------------------------------------------------------
    */

    'currency' => [
        'default' => env('FINANCE_DEFAULT_CURRENCY', 'THB'),
        'supported' => ['THB', 'USD', 'BDT'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Supported Banks (for Thai bank wire and withdrawal settlement)
    |--------------------------------------------------------------------------
    */

    'supported_banks' => [
        'Bangkok Bank' => 'Bangkok Bank (BBL)',
        'Kasikornbank' => 'Kasikornbank (KBANK)',
        'Siam Commercial Bank' => 'Siam Commercial Bank (SCB)',
        'Krungthai Bank' => 'Krungthai Bank (KTB)',
        'Bank of Ayudhya' => 'Bank of Ayudhya (Krungsri)',
        'TTB Bank' => 'TMBThanachart Bank (TTB)',
        'Government Savings Bank' => 'Government Savings Bank (GSB)',
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Methods
    |--------------------------------------------------------------------------
    |
    | Backing values of App\Enums\PaymentMethod, used to validate an inbound
    | deposit or withdrawal request before a gateway is resolved.
    |
    */

    'methods' => array_column(PaymentMethod::cases(), 'value'),

    /*
    |--------------------------------------------------------------------------
    | Public fee providers (public fee-schedule bridge)
    |--------------------------------------------------------------------------
    |
    | Maps the STABLE PUBLIC provider keys used by config/fees.php's
    | provider-specific rows onto the payment layer. 'method' names the live
    | PaymentMethod backing value when this platform actually operates that
    | provider's lane today; null means the provider exists only as a public
    | schedule row (documented fee, no executable lane, therefore no fee is
    | ever charged through it and no gateway is consulted for it).
    |
    | SECRETS: none. This map carries provider IDENTITY only — no gateway
    | credential, webhook secret, merchant id or internal margin. Fee VALUES
    | live in config/fees.php; gateway credentials live in 'gateways' below
    | and are never read by the public fees surface.
    |
    | The public Fees page and the fee-preview endpoint whitelist provider
    | input against exactly these keys, so a request can never name an
    | arbitrary internal provider.
    |
    */

    'public_fees' => [
        'providers' => [
            'bank' => [
                'method' => PaymentMethod::BankTransfer->value,
                'live' => true,
            ],
            'skrill' => [
                'method' => null,
                'live' => false,
            ],
            'neteller' => [
                'method' => null,
                'live' => false,
            ],
            'paypal' => [
                'method' => null,
                'live' => false,
            ],
            'perfect_money' => [
                'method' => null,
                'live' => false,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Deposits
    |--------------------------------------------------------------------------
    |
    | Monetary bounds are decimal strings and mirror config/finance.php so the
    | wallet engine and the payment layer cannot disagree.
    |
    */

    'deposit' => [
        'enabled' => true,
        'min' => (string) env('FINANCE_MIN_DEPOSIT', '50.00'),
        'max' => (string) env('FINANCE_MAX_DEPOSIT', '500000.00'),
        'auto_confirm' => (bool) env('FINANCE_DEPOSIT_AUTO_CONFIRM', false),
        'reference_prefix' => 'DP',
        'expire_unpaid_after_minutes' => 30,
        'allowed_methods' => [
            PaymentMethod::Stripe->value,
            PaymentMethod::Bkash->value,
            PaymentMethod::Nagad->value,
            PaymentMethod::Crypto->value,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Withdrawals
    |--------------------------------------------------------------------------
    */

    'withdrawal' => [
        'enabled' => true,
        'min' => (string) env('FINANCE_MIN_WITHDRAWAL', '100.00'),
        'max' => (string) env('FINANCE_MAX_WITHDRAWAL', '500000.00'),
        'require_manual_approval' => true,
        'processing_hours' => (int) env('FINANCE_WITHDRAWAL_PROCESSING_HOURS', 24),
        'reference_prefix' => 'WD',
        'encrypt_payout_details' => true,
        'allowed_methods' => [
            PaymentMethod::Bkash->value,
            PaymentMethod::Nagad->value,
            PaymentMethod::BankTransfer->value,
            PaymentMethod::Crypto->value,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Callback / Redirect URLs
    |--------------------------------------------------------------------------
    |
    | Paths may be relative ("/payment/success") or absolute
    | ("${APP_URL}/payment/success"). They are read in two places that can
    | never drift apart: the gateway drivers build provider success/cancel
    | URLs from them, and routes/web.php registers the browser-return
    | routes (PaymentCallbackController) from the same values. Those pages
    | are presentation only — payment state changes only via verified
    | webhooks.
    |
    */

    'callback' => [
        'success_url' => env('PAYMENT_SUCCESS_URL', '/payment/success'),
        'failure_url' => env('PAYMENT_FAILURE_URL', '/payment/failure'),
        'cancel_url' => env('PAYMENT_CANCEL_URL', '/payment/cancel'),
        'pending_url' => '/payment/pending',
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    |
    | Gateway callbacks and webhooks are retried by providers, so every inbound
    | notification is keyed and deduplicated before it can touch the ledger.
    |
    */

    'idempotency' => [
        'enabled' => true,
        'header' => env('IDEMPOTENCY_KEY_HEADER', 'X-Idempotency-Key'),
        'cache_ttl' => (int) env('IDEMPOTENCY_CACHE_TTL', 86400),
        'cache_prefix' => 'payment:idempotency:',
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Validation
    |--------------------------------------------------------------------------
    |
    | Consumed by App\Http\Middleware\VerifyWebhookSignature, which reads the
    | per-gateway 'webhook_secret' and 'signature_header' values below.
    |
    */

    'webhook' => [
        'verify_signature' => true,
        'algorithm' => 'sha256',
        'max_age_seconds' => 300,
        'replay_protection' => true,
        'replay_cache_prefix' => 'payment:webhook:seen:',

        /*
        |----------------------------------------------------------------------
        | Durable replay guard retention
        |----------------------------------------------------------------------
        |
        | How long a signed webhook payload is remembered in
        | webhook_replay_guards, as the substrate for replay refusal. Shorter
        | reopens the replay window; longer costs a few dozen bytes per delivery.
        |
        | 30 days is chosen to comfortably exceed every gateway's retry horizon
        | (Stripe retries for up to 3 days; bKash and Nagad for far less) while
        | keeping the table small enough that pruning stays an indexed range
        | delete.
        |
        | This is NOT the same thing as webhook.max_age_seconds, which bounds how
        | OLD a signed request may be. This bounds how long we remember that we
        | have seen it.
        |
        */
        'replay_guard_retention_days' => (int) env('WEBHOOK_REPLAY_GUARD_RETENTION_DAYS', 30),
        'require_https' => true,
        'log_rejections' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Gateways
    |--------------------------------------------------------------------------
    |
    | Concrete gateway drivers live in app/Services/Payment/Drivers (Stripe,
    | bKash, Nagad, Crypto, Bank Transfer). Each gateway is enabled via
    | environment flags and requires valid credentials or operator settlement
    | configuration before it can be used in deposit or withdrawal operations.
    |
    */

    'gateways' => [

        'stripe' => [
            'enabled' => (bool) env('STRIPE_ENABLED', false),
            'driver' => 'stripe',
            'key' => env('STRIPE_KEY'),
            'secret' => env('STRIPE_SECRET'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
            'signature_header' => 'Stripe-Signature',
            'currency' => env('FINANCE_DEFAULT_CURRENCY', 'THB'),
            'supported_currencies' => ['THB', 'USD'],
            'supports_deposit' => true,
            'supports_withdrawal' => false,
        ],

        'bkash' => [
            'enabled' => (bool) env('BKASH_ENABLED', false),
            'driver' => 'bkash',
            'app_key' => env('BKASH_APP_KEY'),
            'app_secret' => env('BKASH_APP_SECRET'),
            'username' => env('BKASH_USERNAME'),
            'password' => env('BKASH_PASSWORD'),
            'base_url' => env('BKASH_BASE_URL'),
            'sandbox' => (bool) env('BKASH_SANDBOX', true),
            'webhook_secret' => env('BKASH_APP_SECRET'),
            'signature_header' => 'X-Bkash-Signature',
            'currency' => 'BDT',
            'supported_currencies' => ['BDT'],
            'supports_deposit' => true,
            'supports_withdrawal' => true,
        ],

        'nagad' => [
            'enabled' => (bool) env('NAGAD_ENABLED', false),
            'driver' => 'nagad',
            'merchant_id' => env('NAGAD_MERCHANT_ID'),
            'merchant_number' => env('NAGAD_MERCHANT_NUMBER'),
            'app_key' => env('NAGAD_APP_KEY'),
            'app_secret' => env('NAGAD_APP_SECRET'),
            'public_key' => env('NAGAD_PUBLIC_KEY'),
            'private_key' => env('NAGAD_PRIVATE_KEY'),
            'base_url' => env('NAGAD_BASE_URL'),
            'sandbox' => (bool) env('NAGAD_SANDBOX', true),
            'webhook_secret' => env('NAGAD_APP_SECRET'),
            'signature_header' => 'X-Nagad-Signature',
            'currency' => 'BDT',
            'supported_currencies' => ['BDT'],
            'supports_deposit' => true,
            'supports_withdrawal' => true,
        ],

        'crypto' => [
            'enabled' => (bool) env('CRYPTO_ENABLED', false),
            'driver' => 'crypto',
            'provider' => env('CRYPTO_PROVIDER'),
            'api_key' => env('CRYPTO_GATEWAY_API_KEY'),
            'api_secret' => env('CRYPTO_GATEWAY_API_SECRET'),
            'webhook_secret' => env('CRYPTO_GATEWAY_WEBHOOK_SECRET'),
            'signature_header' => 'X-Crypto-Signature',
            'currency' => 'USD',
            'supported_currencies' => ['USD'],
            'confirmations_required' => 3,
            'supports_deposit' => true,
            'supports_withdrawal' => true,
        ],

        'promptpay' => [
            // ── THE THAI QR RAIL IS PARTIALLY BUILT. READ THIS BEFORE ENABLING IT. ──
            //
            // WHAT EXISTS AND WORKS (app/Services/Payment/PromptPayPaymentService.php):
            //   - dynamic ThaiQR/EMVCo payload generation, with the PromptPay
            //     merchant block and a CRC16-CCITT checksum;
            //   - a static collection payload;
            //   - HMAC-SHA256 verification of an aggregator callback over
            //     "reference:amount:THB", failing closed when the secret is absent.
            //
            // WHAT DOES NOT EXIST — the INBOUND lane, i.e. everything between a
            // player paying and this platform crediting them:
            //   - no `PaymentMethod::PromptPay` enum case;
            //   - no driver in App\Services\Payment\Drivers\;
            //   - no entry in PaymentGatewayManager::driver(), so
            //     `driver('promptpay')` throws and `isEnabled('promptpay')` is
            //     permanently false;
            //   - no route for the aggregator's notification, and
            //     PromptPayPaymentService itself is referenced by nothing.
            //
            // WHY IT WAS NOT WIRED HERE. A merchant does not receive PromptPay
            // notifications directly: they arrive from an ACQUIRING BANK or an
            // aggregator, whose notification contract, credentials and callback
            // shape are specific to the provider the operator contracts with.
            // Inventing one would produce a lane that looks integrated and credits
            // nothing — and a fake money rail is worse than an absent one. So this
            // is documented as unfinished rather than guessed at.
            //
            // TO WIRE IT, in this order:
            //   1. choose the acquirer/aggregator and obtain its notification spec;
            //   2. add `case PromptPay = 'promptpay';` to App\Enums\PaymentMethod;
            //   3. implement a PromptPayGateway in Drivers/ that composes this
            //      service and verifies callbacks with assertValidWebhookSignature;
            //   4. register it in PaymentGatewayManager::driver() and hasDriver();
            //   5. add it to payment.deposit.allowed_methods;
            //   6. add a webhook route behind 'throttle:webhook' and
            //      'webhook.signature' (the replay guard is already generic);
            //   7. add the method to whatever quotes deposit options to players.
            //
            // UNTIL STEP 3 IS DONE, `enabled => true` changes nothing: the manager
            // refuses the method before any driver is consulted. Setting it true
            // while believing otherwise is the mistake this comment exists to stop.
            'enabled' => (bool) env('PROMPTPAY_ENABLED', false),
            'driver' => 'promptpay',
            'target' => env('PROMPTPAY_TARGET'),
            'webhook_secret' => env('PROMPTPAY_WEBHOOK_SECRET'),
            'signature_header' => 'X-PromptPay-Signature',
            'currency' => 'THB',
            'supported_currencies' => ['THB'],
            'qr_expiry_minutes' => (int) env('PROMPTPAY_QR_EXPIRY_MINUTES', 15),
            'supports_deposit' => true,
            'supports_withdrawal' => false,
        ],

        'bank_transfer' => [
            // Bank transfer settlement configuration. Requires valid operator
            // settlement details (bank_name, account_number, account_name) in
            // the environment; otherwise BankTransferGateway fails closed.
            'enabled' => (bool) env('BANK_TRANSFER_ENABLED', false),
            'driver' => 'bank_transfer',
            'supported_currencies' => ['THB'],
            'supports_deposit' => true,
            'supports_withdrawal' => true,
            'settlement' => [
                'bank_name' => env('BANK_TRANSFER_BANK_NAME'),
                'account_number' => env('BANK_TRANSFER_ACCOUNT_NUMBER'),
                'account_name' => env('BANK_TRANSFER_ACCOUNT_NAME'),
                'instructions' => env('BANK_TRANSFER_INSTRUCTIONS'),
            ],
        ],

    ],

];
