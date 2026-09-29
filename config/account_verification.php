<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Account Verification (PROMPT 3 — canonical verification config)
|--------------------------------------------------------------------------
|
| The policy for member identity verification: supported document
| types, mobile/country requirements, upload MIME/size rules, the
| status vocabulary, submission limits, review rules, the private
| storage disk, rule version and retention.
|
| This file COMPLEMENTS config/account.php (the pre-existing
| account.verification block). Where both define a knob the SAME env
| key is read, so there is exactly one number per knob in every
| environment. The document-type list is deliberately identical to
| account.verification.document_types: the KycDocumentType enum is
| the one vocabulary.
|
| The identity state machine itself stays where it has always lived:
| kyc_verifications / kyc_documents (one canonical KYC domain). The
| account_verifications table created by PROMPT 3 is the immutable
| SUBMISSION-EVENT aggregate on top of it — who submitted what, when,
| and which decision closed it — never a second identity status.
|
*/

return [
    /*
    |----------------------------------------------------------------------
    | Rule version — bump when the verification contract changes.
    |----------------------------------------------------------------------
    */
    'rule_version' => (string) env('ACCOUNT_VERIFICATION_RULE_VERSION', '1'),

    /*
    |----------------------------------------------------------------------
    | Document types accepted by the member submission surface.
    | Values MUST be KycDocumentType cases (the single vocabulary).
    |----------------------------------------------------------------------
    */
    'document_types' => [
        'national_id',
        'passport',
        'driving_license',
        'proof_of_address',
        'selfie',
        'other',
    ],

    /*
    |----------------------------------------------------------------------
    | Upload rules (mirrors the hardened Security KYC service limits).
    |----------------------------------------------------------------------
    */
    'uploads' => [
        'allowed_mimes' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
        'allowed_mime_types' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
        ],
        'max_file_kb' => (int) env('ACCOUNT_KYC_MAX_FILE_KB', 10240),
        // Sanity bounds for raster images (corruption + absurd-size guard).
        'min_image_width' => 60,
        'min_image_height' => 60,
        'max_image_width' => 10000,
        'max_image_height' => 10000,
        // Back-of-document requirement. The benchmark collects front and
        // back; the current domain contract accepts front-only packages
        // (back optional) — flip via env when the business requires both.
        'require_back_document' => (bool) env('VERIFICATION_REQUIRE_BACK_DOCUMENT', false),
    ],

    /*
    |----------------------------------------------------------------------
    | Mobile / country-code pair policy.
    |----------------------------------------------------------------------
    */
    'phone' => [
        // Controlled country-code catalogue (Section Q): free-form
        // country text is never trusted. +66 is the platform default;
        // extend via env as the market grows.
        'country_codes' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('VERIFICATION_COUNTRY_CODES', '+66')),
        ))),
        'default_country_code' => (string) env('ACCOUNT_PHONE_DEFAULT_CC', '+66'),
        'min_nsn_digits' => 5,
        'max_nsn_digits' => 15,
        'max_combined_length' => (int) env('ACCOUNT_PHONE_MAX_LENGTH', 20),
    ],

    /*
    |----------------------------------------------------------------------
    | Status vocabulary (public words; the storage values are the
    | AccountVerificationStatus enum cases).
    |----------------------------------------------------------------------
    */
    // Storage values (the canonical KYC machine vocabulary). The
    // member-facing words — NOT_SUBMITTED / PENDING / UNDER_REVIEW /
    // APPROVED / REJECTED / EXPIRED — are rendered by
    // AccountVerificationStatus::publicWord().
    'statuses' => [
        'not_submitted',
        'pending',
        'under_review',
        'verified',
        'rejected',
        'expired',
    ],

    /*
    |----------------------------------------------------------------------
    | Submission limits.
    |----------------------------------------------------------------------
    */
    'submission' => [
        // Per-user per-minute on the POST surface (the account-verification
        // limiter in AppServiceProvider reads this at request time).
        'max_per_minute' => (int) env('VERIFICATION_SUBMISSIONS_PER_MINUTE', 5),
        // History window shown to the member on the verification page.
        'history_limit' => 10,
    ],

    /*
    |----------------------------------------------------------------------
    | Review rules.
    |----------------------------------------------------------------------
    */
    'review' => [
        // Roles allowed to decide a verification. Mirrors the panel
        // convention (AdminAccess::PANEL_ROLES) plus the explicit admin
        // check used across the API surface.
        'roles' => ['admin', 'super-admin'],
        'max_reason_length' => 500,
    ],

    /*
    |----------------------------------------------------------------------
    | Private document storage.
    |----------------------------------------------------------------------
    | Documents NEVER live under public/. The 'local' disk is private
    | in this application (storage/app/private semantics), paths are
    | server-generated and non-guessable, and every read goes through
    | an authorization-checked controller action.
    |----------------------------------------------------------------------
    */
    'storage' => [
        'disk' => (string) env('VERIFICATION_STORAGE_DISK', 'local'),
        'path_prefix' => 'kyc_documents',
    ],

    /*
    |----------------------------------------------------------------------
    | Retention policy — explicit, never silent.
    |----------------------------------------------------------------------
    | No automatic deletion is performed. Verification evidence must be
    | retained until a compliance process explicitly disposes of it;
    | this config only records the policy horizon so operators and the
    | report can see it.
    |----------------------------------------------------------------------
    */
    'retention' => [
        'auto_delete' => false,
        'retention_days' => (int) env('VERIFICATION_RETENTION_DAYS', 2555), // ~7 years
    ],
];
