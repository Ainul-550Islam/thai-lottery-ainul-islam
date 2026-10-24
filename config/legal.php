<?php

/*
|--------------------------------------------------------------------------
| Legal / Terms page configuration
|--------------------------------------------------------------------------
|
| Versioned, static legal metadata. effective_at and updated_at are FIXED
| dates from configuration — never generated per request, never "today".
| Operator identity fields are env-driven nullables: null renders an honest
| NOT_CONFIGURED / "not published" state. Never invent company names,
| registration numbers, addresses, certifications or government relationships.
|
*/

return [

    // Semantic versions shown on the fallback Terms and Privacy pages. Bump
    // the relevant value whenever the corresponding public text changes.
    'version' => (string) env('LEGAL_VERSION', 'v1.0.0'),
    'privacy_version' => (string) env('LEGAL_PRIVACY_VERSION', env('LEGAL_VERSION', 'v1.0.0')),
    'minimum_age' => min(255, max(18, (int) env('LEGAL_MINIMUM_AGE', 18))),

    // Fixed ISO dates (Y-m-d). Do not use date() / now() for these.
    'effective_at' => (string) env('LEGAL_EFFECTIVE_AT', '2026-09-24'),
    'updated_at' => (string) env('LEGAL_UPDATED_AT', '2026-09-24'),

    // Independent operator identity — all optional, never fabricated.
    'operator' => [
        'legal_name' => env('LEGAL_OPERATOR_NAME'),
        'registration_number' => env('LEGAL_OPERATOR_REGISTRATION'),
        'support_email' => env('LEGAL_SUPPORT_EMAIL'),
        'support_phone' => env('LEGAL_SUPPORT_PHONE'),
        'address' => env('LEGAL_OPERATOR_ADDRESS'),
    ],

    // GLO compatibility disclaimer facts used by TermsPageService.
    'disclaimer' => [
        // This platform is independent; GLO is referenced only for
        // prize-rule compatibility. Never claim ownership of, or
        // endorsement by, the Government Lottery Office.
        'independent_platform' => true,
        'glo_reference_for_compatibility_only' => true,
        'no_government_ownership_or_endorsement' => true,
    ],

    // Cache buster for legal-page HTML/data: bump to invalidate caches.
    'content_version' => (string) env('LEGAL_CONTENT_VERSION', '1'),

    /*
    |--------------------------------------------------------------------------
    | Terms of Use revision
    |--------------------------------------------------------------------------
    |
    | TERMS ARE VERSIONED SEPARATELY FROM PRIVACY.
    | The two documents are edited, effective and re-consented on different dates,
    | so a single 'version' cannot describe both: a Terms revision used to bump the
    | Privacy version (and its displayed effective date) as a side effect, and the
    | Terms page showed whatever privacy happened to be on. Each document now owns
    | its revision, its dates and its cache buster; 'legal.version' remains the
    | fallback for any installation that has not set the specific value.
    */
    'terms' => [
        'version' => (string) env('LEGAL_TERMS_VERSION', 'v1.0.1'),
        'effective_at' => (string) env('LEGAL_TERMS_EFFECTIVE_AT', '2026-10-14'),
        'updated_at' => (string) env('LEGAL_TERMS_UPDATED_AT', '2026-10-14'),
        'content_version' => (string) env('LEGAL_TERMS_CONTENT_VERSION', '1'),
    ],

];
