<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Fraud & Risk Engine Operating Mode
    |--------------------------------------------------------------------------
    | 'log_only' : Logs risk decisions without holding/denying transactions.
    | 'review'   : Holds suspicious transactions for compliance review.
    | 'enforce'  : Enforces immediate rejection on critical fraud scores.
    */
    'mode' => env('FRAUD_DETECTION_MODE', 'enforce'),

    'review_threshold' => (int) env('FRAUD_REVIEW_THRESHOLD', 60),

    'deny_threshold' => (int) env('FRAUD_DENY_THRESHOLD', 90),

    'max_deposit_daily_thb' => env('FRAUD_MAX_DEPOSIT_DAILY_THB', '1000000.0000'),

    'max_withdrawal_single_thb' => env('FRAUD_MAX_WITHDRAWAL_SINGLE_THB', '500000.0000'),

];
