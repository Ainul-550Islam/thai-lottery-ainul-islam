<?php

declare(strict_types=1);

return [
    'readiness' => [
        'fail_boot_when_real_money_unready' => (bool) env('PAYMENT_OPS_FAIL_BOOT_WHEN_UNREADY', true),
        'required_env' => [
            'stripe' => ['STRIPE_KEY', 'STRIPE_SECRET', 'STRIPE_WEBHOOK_SECRET'],
            'bkash' => ['BKASH_APP_KEY', 'BKASH_APP_SECRET', 'BKASH_USERNAME', 'BKASH_PASSWORD', 'BKASH_BASE_URL'],
            'nagad' => ['NAGAD_MERCHANT_ID', 'NAGAD_APP_KEY', 'NAGAD_APP_SECRET', 'NAGAD_PUBLIC_KEY', 'NAGAD_PRIVATE_KEY', 'NAGAD_BASE_URL'],
            'promptpay' => ['PROMPTPAY_MERCHANT_ID', 'KBANK_WEBHOOK_SECRET|SCB_WEBHOOK_SECRET'],
            'thai_bank' => ['THAI_PAYOUT_*_CONTRACT_CONFIRMED', 'THAI_PAYOUT_*_BASE_URL', 'THAI_PAYOUT_*_TRANSFER_PATH', 'THAI_PAYOUT_*_STATUS_PATH'],
        ],
    ],
    'manual_disbursement' => [
        'sla_hours' => (int) env('PAYMENT_OPS_MANUAL_DISBURSEMENT_SLA_HOURS', 24),
        'stale_task_hours' => (int) env('PAYMENT_OPS_MANUAL_DISBURSEMENT_STALE_HOURS', 12),
        'sweep_batch_size' => (int) env('PAYMENT_OPS_MANUAL_DISBURSEMENT_SWEEP_BATCH_SIZE', 100),
        'notification_email' => (string) env('PAYMENT_OPS_NOTIFICATION_EMAIL', ''),
    ],
];
