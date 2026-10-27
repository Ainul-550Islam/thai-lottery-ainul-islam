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

        // Document upload verification. `method` names the shape of the process;
        // `provider` names the driver that owns intake.
        'method' => 'DOCUMENT_UPLOAD_VERIFICATION',

        /*
        |----------------------------------------------------------------------
        | KYC provider selection
        |----------------------------------------------------------------------
        |
        | ACCOUNT_KYC_PROVIDER names one of the keys in `providers` below. The
        | default is `manual_review`, and that default is a DECISION, not a
        | convenience: with no external vendor contracted, a human queue is the
        | only honest process, and it is the only driver that is always
        | available.
        |
        | App\Services\Kyc\KycProviderManager resolves the name through this map
        | and REFUSES anything it cannot find:
        |
        |   - unset / empty        -> KycProviderException (kyc_provider_not_configured)
        |   - a name not in `providers` -> kyc_provider_unknown
        |   - a class that does not implement KycProviderInterface -> kyc_provider_unknown
        |   - a driver whose credentials are missing -> kyc_provider_not_configured
        |
        | There is NO runtime fallback to another driver. If an operator sets a
        | vendor driver and the vendor credentials expire, submissions fail
        | loudly instead of quietly becoming manual review — because a member
        | told "we are verifying you automatically" while nobody is looking is
        | worse than a visible outage.
        |
        | ------------------------------------------------------------------
        | HOW TO ADD A DRIVER
        | ------------------------------------------------------------------
        |   1. implement App\Services\Kyc\KycProviderInterface
        |   2. add its class to `providers` under a short driver name
        |   3. point ACCOUNT_KYC_PROVIDER at that name in the environment
        |
        | No line of code outside this file needs to change, and adding a name
        | here does not make it the default.
        |
        */
        'provider' => (string) env('ACCOUNT_KYC_PROVIDER', 'manual_review'),

        'providers' => [
            // A person reads the documents and records the decision through the
            // admin review queue. Always available; no credentials.
            'manual_review' => \App\Services\Kyc\ManualReviewKycProvider::class,
        ],

        // Drivers reserved for vendor adapters. The keys exist so an operator
        // can see the intended names, but they map to null: a name mapped to
        // null is refused by the manager exactly like an unknown name, so it can
        // never be selected into a half-written adapter. Replace a null with the
        // adapter class in the change that wires it.
        'reserved_providers' => [
            'sumsub' => null,
            'jumio' => null,
        ],

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
