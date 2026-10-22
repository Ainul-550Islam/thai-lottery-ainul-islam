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

    // Semantic version shown on /terms. Bump when terms content changes.
    'version' => (string) env('LEGAL_VERSION', 'v1.0.0'),

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

];
