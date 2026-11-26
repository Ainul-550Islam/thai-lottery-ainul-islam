<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Fraud & Risk Engine Operating Mode
    |--------------------------------------------------------------------------
    | 'observe' / 'log_only': attach and log decisions without interception.
    | 'enforce'             : enforce DENY, HOLD_FOR_REVIEW, and STEP_UP.
    | Any other value fails closed as enforcing; configuration typos must not
    | silently disable the pre-transaction risk gate.
    */
    'mode' => env('FRAUD_DETECTION_MODE', 'enforce'),

    'review_threshold' => (int) env('FRAUD_REVIEW_THRESHOLD', 60),

    'deny_threshold' => (int) env('FRAUD_DENY_THRESHOLD', 90),

    'max_deposit_daily_thb' => env('FRAUD_MAX_DEPOSIT_DAILY_THB', '1000000.0000'),

    'max_withdrawal_single_thb' => env('FRAUD_MAX_WITHDRAWAL_SINGLE_THB', '500000.0000'),

];
