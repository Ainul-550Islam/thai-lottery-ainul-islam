<?php

/*
|--------------------------------------------------------------------------
| Account verification + account services
|--------------------------------------------------------------------------
|
| Verification composes the EXISTING canonical KYC stack
| (KycDocument / KycVerification / Security+Compliance services).
| This file only configures the account-facing presentation, document
| limits, phone rules, retention and notifications — never a second
| identity state machine.
|
*/

return [

    'verification' => [
        // Public status vocabulary mapped from KycStatus / KycVerificationStatus.
        'statuses' => [
            'NOT_SUBMITTED',
            'PENDING',
            'UNDER_REVIEW',
            'APPROVED',
            'REJECTED',
            'EXPIRED',
        ],

        // Document upload verification (NOT e-KYC — no provider is wired).
        'method' => 'DOCUMENT_UPLOAD_VERIFICATION',
        'provider' => env('ACCOUNT_KYC_PROVIDER'),

        'document_types' => [
            'national_id',
            'passport',
            'driving_license',
            'proof_of_address',
            'selfie',
            'other',
        ],

        'allowed_mimes' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
        'max_file_kb' => (int) env('ACCOUNT_KYC_MAX_FILE_KB', 10240),

        // Private disk root for uploads (never public /storage).
        'storage_disk' => 'local',
        'storage_prefix' => 'kyc_documents',

        // Retention: documents kept while account active + grace days;
        // expired evidence moves to EXPIRED lifecycle (never silent purge).
        'retention_days' => (int) env('ACCOUNT_KYC_RETENTION_DAYS', 1095),

        'phone' => [
            // OTP lane is not implemented — page must show NOT_CONFIGURED.
            'otp_enabled' => (bool) env('ACCOUNT_PHONE_OTP_ENABLED', false),
            'default_country_code' => (string) env('ACCOUNT_PHONE_DEFAULT_CC', '+66'),
            'max_length' => 20,
        ],
    ],

    'rate_limits' => [
        'verification_submit_per_minute' => (int) env('ACCOUNT_VERIFICATION_SUBMIT_PER_MINUTE', 5),
        'verification_upload_per_minute' => (int) env('ACCOUNT_VERIFICATION_UPLOAD_PER_MINUTE', 10),
        'grade_history_per_minute' => (int) env('ACCOUNT_GRADE_HISTORY_PER_MINUTE', 30),
    ],

    'grade' => [
        // Mirrors account_grades for one read path; canonical thresholds
        // remain in config/account_grades.php.
        'period_days' => (int) env('ACCOUNT_GRADE_PERIOD_DAYS', 30),
        'cache_ttl_seconds' => (int) env('ACCOUNT_GRADE_CACHE_TTL', 60),
        'recalculate_on_qualifying_transaction' => true,
    ],

];
