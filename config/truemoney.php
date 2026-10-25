<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('TRUEMONEY_ENABLED', false),
    'acknowledged_unofficial_api' => (bool) env('TRUEMONEY_ACKNOWLEDGE_UNOFFICIAL_API', false),
    'merchant_mobile' => (string) env('TRUEMONEY_MERCHANT_MOBILE', ''),
    'base_url' => (string) env('TRUEMONEY_BASE_URL', 'https://gift.truemoney.com'),
    'redeem_path' => (string) env('TRUEMONEY_REDEEM_PATH', '/campaign/vouchers/{voucher_code}/redeem'),
    'allowed_host' => (string) env('TRUEMONEY_ALLOWED_HOST', 'gift.truemoney.com'),
    'timeout_seconds' => (int) env('TRUEMONEY_TIMEOUT_SECONDS', 10),
    'min_amount' => (string) env('TRUEMONEY_MIN_AMOUNT', '0.01'),
    'max_amount' => (string) env('TRUEMONEY_MAX_AMOUNT', '100000.00'),
    'per_user_daily_count' => (int) env('TRUEMONEY_PER_USER_DAILY_COUNT', 5),
    'per_user_daily_amount' => (string) env('TRUEMONEY_PER_USER_DAILY_AMOUNT', '50000.00'),
];
