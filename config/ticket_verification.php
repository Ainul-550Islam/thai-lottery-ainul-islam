<?php

/*
|--------------------------------------------------------------------------
| Public prize / ticket verification policy (PROMPT 4)
|--------------------------------------------------------------------------
|
| Governs the anonymous verification surface: GET /prize-verification and its
| POST. Everything here is a SAFETY BOUNDARY, not decoration - input sizes,
| rate limits, what the public may be told, and what the barcode adapter is
| allowed to claim.
|
| TWO PRODUCTS, NEVER MERGED
|  - 'glo'     : GLO L6 / N3 ticket references, verified through the existing
|                GLO services (GloTicketChecker, GloN3TicketChecker,
|                GloPublicTicketVerificationService, GloPrizeClaimService,
|                GloTicketFreezeService).
|  - 'operator': this platform's own betting tickets, verified through
|                App\Services\Ticket\TicketQrVerificationService and the
|                generic Ticket/Bet models.
| An operator ticket is NEVER labelled an official GLO ticket, and a GLO
| lookup never returns an operator bet.
|
| AUTHENTICITY WORDING
| A database row proves a DIGITAL RECORD, never a piece of paper. The only
| verdicts this system may publish about authenticity are declared in
| 'authenticity_states' below; "the paper ticket is genuine" is not one of
| them and must never be synthesised from a successful lookup.
|
| BARCODE / QR / DATA MATRIX
| The proprietary GLO Data Matrix encoding is not publicly documented, so no
| decoder is invented here. The adapter delegates to the existing
| App\Services\Lottery\GloDataMatrixParser (whose mode lives in
| config('glo.result_experience.data_matrix')) and reports one of the provider
| states verbatim. A payload accepted under the synthetic fixture schema is
| always reported as a fixture and never as an official ticket.
|
*/

return [

    'enabled' => (bool) env('TICKET_VERIFICATION_ENABLED', true),

    // Stamped onto every stored verification record so evidence can be
    // replayed against the policy that was in force at the time.
    'policy_version' => (string) env('TICKET_VERIFICATION_POLICY_VERSION', '1'),

    /*
    |--------------------------------------------------------------------------
    | Verification modes offered on the public page
    |--------------------------------------------------------------------------
    |
    | A disabled mode is not rendered, not routed and not accepted by the
    | request validator.
    |
    */
    'modes' => [
        'number' => [
            'enabled' => true,
            'label_key' => 'prize_discount.mode_number',
            // Six-digit GLO L6 numbers are STRINGS. Leading zeros are data.
            'products' => ['glo_l6', 'glo_n3'],
        ],
        'reference' => [
            'enabled' => true,
            'label_key' => 'prize_discount.mode_reference',
            'products' => ['glo_l6', 'glo_n3', 'operator'],
        ],
        'barcode' => [
            'enabled' => true,
            'label_key' => 'prize_discount.mode_barcode',
            'products' => ['glo_l6', 'glo_n3', 'operator'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Input bounds - enforced BEFORE any database access
    |--------------------------------------------------------------------------
    |
    | Every bound below is checked in the service layer against the normalised
    | value, so an oversized or malformed payload is rejected without costing
    | a query. This is the anti-enumeration and anti-DoS boundary.
    |
    */
    'input' => [
        'number' => [
            // GLO L6 = 6 digits, N3 = 3 digits, N3 two-digit variant = 2.
            'allowed_lengths' => [2, 3, 6],
            'pattern' => '/^[0-9]{2,6}$/',
            'max_length' => 6,
        ],
        'reference' => [
            // Mirrors config('glo.public_status.reference_pattern') shape but
            // also admits this platform's own ticket references.
            'pattern' => '/^[0-9A-Za-z][0-9A-Za-z._:-]{2,63}$/',
            'max_length' => 64,
        ],
        'barcode' => [
            'max_length' => 512,
            // Base64/hex/JSON envelope characters only. Anything else is
            // INVALID before the parser is even constructed.
            'pattern' => '/^[0-9A-Za-z+\/=:_.\-{}",\[\]\s]{8,512}$/',
            'max_bytes' => 4096,
        ],
        'set_series' => [
            'pattern' => '/^[0-9A-Za-z]{1,16}$/',
            'max_length' => 16,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limiting
    |--------------------------------------------------------------------------
    |
    | A six-digit space is 1,000,000 values; an unthrottled public checker is a
    | free enumeration oracle. The named limiter 'ticket-verification' is
    | registered in AppServiceProvider and is keyed on the client IP plus a
    | HASHED query fingerprint, so hammering one value and sweeping a range are
    | both bounded without the limiter itself storing the query.
    |
    */
    'rate_limit' => [
        'per_minute' => (int) env('TICKET_VERIFICATION_PER_MINUTE', 12),
        'per_hour' => (int) env('TICKET_VERIFICATION_PER_HOUR', 120),
        'fingerprint_per_minute' => (int) env('TICKET_VERIFICATION_FINGERPRINT_PER_MINUTE', 4),
        'limiter' => 'ticket-verification',
    ],

    /*
    |--------------------------------------------------------------------------
    | Timing shaping
    |--------------------------------------------------------------------------
    |
    | A found ticket costs more queries than a rejected pattern, which leaks
    | existence through response time. Every public verification is padded to
    | a floor so the cheap paths are not obviously cheap. This is mitigation,
    | not a constant-time guarantee, and is documented as such.
    |
    */
    'timing' => [
        'enabled' => (bool) env('TICKET_VERIFICATION_TIMING_FLOOR_ENABLED', true),
        'floor_milliseconds' => (int) env('TICKET_VERIFICATION_TIMING_FLOOR_MS', 120),
        'max_sleep_milliseconds' => 400,
    ],

    /*
    |--------------------------------------------------------------------------
    | Public response vocabulary
    |--------------------------------------------------------------------------
    |
    | The ONLY statuses a public caller may receive. Anything the services
    | cannot map to one of these becomes UNAVAILABLE rather than leaking a
    | richer internal state.
    |
    */
    'public_statuses' => [
        'NOT_FOUND',
        'FOUND_NOT_WINNING',
        'WINNING',
        'PAYMENT_HOLD',
        'PAID',
        'INVALID',
        'UNAVAILABLE',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authenticity vocabulary
    |--------------------------------------------------------------------------
    |
    | DIGITAL_RECORD_VERIFIED - a canonical record was found AND its integrity
    |                           fingerprint/ownership/issue state checks passed.
    | PUBLIC_RECORD_FOUND     - a public record exists, but the stronger
    |                           integrity checks are not available for it.
    | NOT_VERIFIED            - nothing could be affirmed.
    | REVOKED                 - the record exists and is void/cancelled.
    |
    | There is deliberately no state meaning "the physical ticket is genuine".
    |
    */
    'authenticity_states' => [
        'DIGITAL_RECORD_VERIFIED',
        'PUBLIC_RECORD_FOUND',
        'NOT_VERIFIED',
        'REVOKED',
    ],

    'authenticity' => [
        // Physical-paper security features (watermark, ink, fibre, embossing)
        // are INFORMATIONAL ONLY on the public page and must never be combined
        // with a database verdict to imply the paper was inspected.
        'physical_features_are_informational_only' => true,
        'require_ownership_for_digital_verified' => true,
        'require_fingerprint_for_digital_verified' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Barcode provider states (verbatim vocabulary)
    |--------------------------------------------------------------------------
    */
    'barcode' => [
        'provider_states' => [
            'SUPPORTED',
            'NOT_CONFIGURED',
            'UNSUPPORTED_FORMAT',
            'INVALID',
        ],
        // Delegated to the existing adapter; not re-implemented here.
        'glo_adapter' => 'App\\Services\\Lottery\\GloDataMatrixParser',
        'glo_mode_source' => 'glo.result_experience.data_matrix.mode',
        'fixture_schema_version' => 'SYNTHETIC_FIXTURE_V1',
        // A fixture decode is always flagged, never presented as official.
        'fixture_label' => 'SYNTHETIC_FIXTURE',
        'operator_adapter' => 'App\\Services\\Ticket\\TicketQrVerificationService',
    ],

    /*
    |--------------------------------------------------------------------------
    | Public disclosure policy
    |--------------------------------------------------------------------------
    |
    | Deny-list is not enough on its own, so the service builds its response
    | from an explicit allow-list. These flags exist so a reviewer can see the
    | policy without reading the service.
    |
    */
    'disclosure' => [
        'allow_prize_amount' => true,
        'allow_prize_category' => true,
        'allow_draw_identity' => true,
        'allow_claim_public_status' => true,

        'expose_owner_identity' => false,
        'expose_contact_details' => false,
        'expose_bank_details' => false,
        'expose_national_id' => false,
        'expose_freeze_case_details' => false,
        'expose_internal_ids' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Evidence records
    |--------------------------------------------------------------------------
    |
    | Every public verification writes ONE immutable row. The row stores a
    | keyed hash of the query, never the query itself, so the evidence trail
    | cannot be mined for the numbers people checked.
    |
    */
    'evidence' => [
        'enabled' => (bool) env('TICKET_VERIFICATION_EVIDENCE_ENABLED', true),
        'hash_algorithm' => 'sha256',
        // Falls back to APP_KEY when unset; never empty in practice.
        'hash_key' => env('TICKET_VERIFICATION_HASH_KEY'),
        'retention_days' => (int) env('TICKET_VERIFICATION_RETENTION_DAYS', 365),
        'store_ip' => false,
        'store_user_agent' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Result caching
    |--------------------------------------------------------------------------
    |
    | Only immutable, already-public projections are cacheable. Claim state,
    | freeze evidence and anything owner-scoped are never cached.
    |
    */
    'cache' => [
        'enabled' => (bool) env('TICKET_VERIFICATION_CACHE_ENABLED', false),
        'ttl_seconds' => 30,
        'key_prefix' => 'ticket_verification.public',
        'never_cache' => ['PAYMENT_HOLD', 'PAID'],
    ],
];
