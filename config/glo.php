<?php

use App\Enums\GloPrizeTier;

/*
|--------------------------------------------------------------------------
| Government Lottery Office (GLO) — Official Prize-Rule Catalogue
|--------------------------------------------------------------------------
|
| This file declares the OFFICIAL Thai government lottery prize structure as a
| reference and settlement catalogue. It is deliberately separate from
| config('lottery.markets'), which describes the online-operator markets this
| project sells.
|
| WHY SEPARATE
| The GLO's printed 6-digit ticket pays a ladder of prizes (first, adjacent,
| second, third, fourth, fifth, front 3, last 3, last 2) that the operator
| markets do not model. Keeping the official ladder here means admin screens,
| result recording and any future "ticket checker" read one authoritative
| source, and nothing in config('lottery') pretends the operator markets are
| the government product.
|
| AMOUNTS AND COUNTS ARE DECLARED, NOT COMPUTED
| The official payout schedule (6,000,000 THB first prize, 5 second prizes,
| …) is data, not code. Operators update it here when the GLO amends the
| schedule. No service hard codes a baht amount.
|
| Official schedule (per ticket), standard draw:
|   first           6,000,000 THB, 1 number
|   adjacent_first    100,000 THB, 2 numbers (first prize +/- 1)
|   second            200,000 THB, 5 numbers
|   third              80,000 THB, 10 numbers
|   fourth             40,000 THB, 50 numbers
|   fifth              20,000 THB, 100 numbers
|   front_three         4,000 THB, 2 numbers
|   last_three          4,000 THB, 2 numbers
|   last_two            2,000 THB, 1 number
|
| CLAIM RULES (from the GLO)
|   - Prize money must be claimed within two years of the draw date.
|   - Banks can pay prizes under 20,000 THB; larger prizes are paid by GLO
|     cheque, which requires the original signed ticket and an ID (Thai ID
|     card or passport).
|   - Income tax on standard GLO prizes is exempt; a stamp duty of ceil(gross/200) × 1
|     THB applies (GloStampDutyCalculator). Do not reintroduce withholding.
|   - Standard official printed L6 ticket format is 80.00 THB per single ticket.
|   - Prize payment is restricted to claimants aged 20 or older.
|
| OFFICIAL REFERENCES (public GLO pages — rules only, no private systems):
|   https://www.glo.or.th/mission/reward-payment/freeze-to-deley
|   https://www.glo.or.th/mission/reward-payment/pay-condition
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Prize Schedule
    |--------------------------------------------------------------------------
    */

    'prizes' => [

        GloPrizeTier::First->value => [
            'amount' => '6000000.00',
            'winners' => 1,
            'digits' => 6,
            'tax_withheld' => false,
            'min_claim_venue' => 'glo_office',
        ],

        GloPrizeTier::AdjacentFirst->value => [
            'amount' => '100000.00',
            'winners' => 2,
            'digits' => 6,
            'tax_withheld' => false,
            'min_claim_venue' => 'glo_office',
        ],

        GloPrizeTier::Second->value => [
            'amount' => '200000.00',
            'winners' => 5,
            'digits' => 6,
            'tax_withheld' => false,
            'min_claim_venue' => 'glo_office',
        ],

        GloPrizeTier::Third->value => [
            'amount' => '80000.00',
            'winners' => 10,
            'digits' => 6,
            'tax_withheld' => false,
            'min_claim_venue' => 'glo_office',
        ],

        GloPrizeTier::Fourth->value => [
            'amount' => '40000.00',
            'winners' => 50,
            'digits' => 6,
            'tax_withheld' => false,
            'min_claim_venue' => 'glo_office',
        ],

        GloPrizeTier::Fifth->value => [
            'amount' => '20000.00',
            'winners' => 100,
            'digits' => 6,
            'tax_withheld' => false,
            'min_claim_venue' => 'any_bank',
        ],

        GloPrizeTier::FrontThree->value => [
            'amount' => '4000.00',
            'winners' => 2,
            'digits' => 3,
            'tax_withheld' => false,
            'min_claim_venue' => 'any_bank',
        ],

        GloPrizeTier::LastThree->value => [
            'amount' => '4000.00',
            'winners' => 2,
            'digits' => 3,
            'tax_withheld' => false,
            'min_claim_venue' => 'any_bank',
        ],

        GloPrizeTier::LastTwo->value => [
            'amount' => '2000.00',
            'winners' => 1,
            'digits' => 2,
            'tax_withheld' => false,
            'min_claim_venue' => 'any_bank',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Tier Recording Layout
    |--------------------------------------------------------------------------
    |
    | draw_results has dedicated columns only for first_prize, second_prize and
    | third_prize. The remaining official tiers (fourth, fifth, front 3, last 3,
    | last 2) are recorded inside draw_results.metadata JSON under this key. The
    | reader (App\Services\Lottery\GloResultService) resolves every tier from
    | this one structure, so an operator record layout change is a configuration
    | change, not a code change.
    |
    */

    'tiers' => [
        'metadata_key' => 'glo',
    ],

    /*
    |--------------------------------------------------------------------------
    | Claim & Tax Rules (for display and payout administration)
    |--------------------------------------------------------------------------
    */

    'claim' => [
        'window_years' => 2,
        'bank_cash_limit' => '20000.00',
        'require_original_ticket' => true,
        'require_signed_ticket' => true,
        'require_id' => true,
        'accepted_id_documents' => ['thai_national_id', 'passport', 'employee_id', 'driver_license'],
        'withholding_tax_rate' => '0.005',
        'withholding_tax_rate_max' => '0.01',
    ],

    /*
    |--------------------------------------------------------------------------
    | Ticket Rules (the printed government ticket)
    |--------------------------------------------------------------------------
    */

    'ticket' => [
        'digits' => 6,
        'sold_in_pairs' => false,
        'price' => '80.00',
        'ticket_price' => '80.00',
        'valid_series' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Draw Calendar
    |--------------------------------------------------------------------------
    |
    | The GLO draws twice a month. These are the structural calendar rules; the
    | application's own draw scheduling remains under config('lottery.draw').
    |
    */

    'draw_calendar' => [
        'days_of_month' => [1, 16],
        'announced_by' => 'Government Lottery Office (GLO)',
        'venue' => 'GLO Headquarters, Bangkok',
    ],

    /*
    |--------------------------------------------------------------------------
    | Stamp Duty (GLO official — income tax exempt; duty = ceil(gross/200) THB)
    |--------------------------------------------------------------------------
    |
    | Read by App\Services\Lottery\GloStampDutyCalculator. Never replace with a
    | 0.5% float or a 1% withholding: the verified GLO treatment is exact-integer
    | baht stamp duty on the gross prize, income tax exempt.
    |
    */

    'stamp_duty' => [
        'enabled' => true,
        'divisor' => '200',
        'per_unit_baht' => '1.00',
        'income_tax_exempt' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | L6 product parameters (official 6-digit printed ticket)
    |--------------------------------------------------------------------------
    */

    'l6' => [
        // Slice of draw revenue that funds the prize ladder. The official GLO
        // split retains 28 percent for the state, charity and administration,
        // which leaves 60 percent for prizes and 12 percent for seller margin.
        // Declared here so an operator changes the split in configuration and
        // never in GloL6ProportionalPrizeCalculator.
        'prize_pool_allocation_percent' => env('GLO_PRIZE_POOL_ALLOCATION_PERCENT', '60.00'),

        'ticket_price' => '80.00',
        'digits' => 6,
        'full_sale_units' => '1000000',
        'full_allocation' => '48000000.00',
        'total_prize_count' => 14168,
    ],

    /*
    |--------------------------------------------------------------------------
    | N3 product parameters (3-digit companion product, where modelled)
    |--------------------------------------------------------------------------
    */

    'n3' => [
        'ticket_price' => '20.00',
        'pool_rate' => '0.60',
        'digits' => 3,
        // Draw-calculated prize engine parameters (NO fixed baht payouts).
        // Pool is always total_gross_sales × pool_rate (BCMath).
        // Caps: each of the three main groups ≤ 30% / 30% / 39% of pool;
        // special group ≥ 1% of pool. Winners drawn via random_int CSPRNG
        // (injectable in tests) — amounts derived from the live pool only.
        'prize_engine' => [
            'mode' => 'pool_variable',
            'groups' => [
                'special' => ['min_share' => '0.01', 'max_share' => null, 'count' => 1],
                'first' => ['min_share' => '0.00', 'max_share' => '0.30', 'count' => 1],
                'second' => ['min_share' => '0.00', 'max_share' => '0.30', 'count' => 1],
                'third' => ['min_share' => '0.00', 'max_share' => '0.39', 'count' => null],
            ],
            // Unsold remainder stays in the pool reserve (never paid as fixed).
            'hold_unsold' => true,
        ],
        'seat' => [
            'full_seats' => 0,
            'conflict_gate' => 'GLON3_SALES_CONFLICT',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Official result source (documented public GLO endpoints only)
    |--------------------------------------------------------------------------
    */

    'official_source' => [
        'base_url' => 'https://www.glo.or.th',
        // Documented public catalog endpoints ONLY — never invent others.
        'latest_lottery_path' => '/api/lottery/getLatestLottery',
        'lottery_result_path' => '/api/checking/getLotteryResult',
        // 'fixture' replays resources/glo/fixtures; 'official' attempts the
        // documented endpoints and reports NOT_CONFIGURED when unreachable.
        'mode' => env('GLO_OFFICIAL_SOURCE_MODE', 'fixture'),
        'request_timeout_seconds' => 10,
        'fixture_path' => resource_path('glo/fixtures'),
        'user_agent' => 'thai-lottery-glo-result-provider/1.0',
    ],

    /*
    |--------------------------------------------------------------------------
    | Result sources — the ladder
    |--------------------------------------------------------------------------
    |
    | `priority` is the ordered list of sources GloResultProviderChain walks.
    | Implemented names: 'official' (the documented public GLO catalog endpoints)
    | and 'fixture' (resources/glo/fixtures — SYNTHETIC test vectors).
    |
    | LEAVING THIS EMPTY IS A VALID AND IMPORTANT CONFIGURATION. With no ladder,
    | the chain is the single source named by official_source.mode, which is
    | exactly how this lane behaved before the ladder existed. The ladder is
    | therefore additive: a deployment that configures nothing new keeps its
    | present behaviour, and one that wants ordering states it here.
    |
    | fall_through_to_fixture — READ THIS BEFORE SETTING IT TRUE.
    |
    | The fixture lane replays synthetic test vectors. Falling through to it does
    | not mean "another source answered"; it means "we could not get a real
    | result, so we will answer with test data instead" — and those numbers are
    | then published and settled against like any other. ProductionSafetyServiceProvider
    | REFUSES TO BOOT PRODUCTION while this is true, so setting it here is a
    | development convenience, never a production fallback.
    |
    | breaker.*     per-source circuit breaker (see Support/CircuitBreaker.php).
    |   enabled              true  — an absent breaker is not safe for a source
    |                                that can hang a scheduled command.
    |   failure_threshold    3     — consecutive failures before opening.
    |   cooldown_seconds     120   — how long to refuse before probing again.
    |   half_open_probes     1     — attempts allowed while half-open. NOT
    |                                unlimited: a still-broken upstream must not
    |                                receive full traffic the moment the cooldown
    |                                expires.
    |   window_seconds       300   — drives the cache TTL, not a rolling count.
    |
    */

    'sources' => [
        'priority' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('GLO_SOURCE_PRIORITY', '')),
        ))),

        'fall_through_to_fixture' => (bool) env('GLO_FALL_THROUGH_TO_FIXTURE', false),

        'breaker' => [
            'enabled' => (bool) env('GLO_BREAKER_ENABLED', true),
            'failure_threshold' => (int) env('GLO_BREAKER_FAILURE_THRESHOLD', 3),
            'cooldown_seconds' => (int) env('GLO_BREAKER_COOLDOWN_SECONDS', 120),
            'half_open_probes' => (int) env('GLO_BREAKER_HALF_OPEN_PROBES', 1),
            'window_seconds' => (int) env('GLO_BREAKER_WINDOW_SECONDS', 300),
            'key_prefix' => 'glo:breaker:',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sales reconciliation
    |--------------------------------------------------------------------------
    */

    'reconciliation' => [
        'conflict_gate' => 'GLON3_SALES_CONFLICT',
        'seat_key_parts' => ['draw_id', 'product'],
        'status' => ['matched', 'variance', 'conflicted'],
        // GLO-10 stress harness defaults.
        'stress' => [
            'default_iterations' => 100,
            'max_iterations' => 10000,
            'default_concurrency' => 4,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | GLO-11 Ticket freeze / seizure workflow (internal, rule-compatible)
    |--------------------------------------------------------------------------
    |
    | Official context (public pages only):
    |   - freeze-to-deley: police/administrative evidence, 2-year limitation,
    |     GLO or provincial office request; a frozen ticket that later wins can
    |     be announced with prize payment delayed in GLO's systems.
    |   - pay-condition: claimants aged 20+; appointment/partner channels noted.
    |
    | This block configures the INTERNAL state machine. It is NOT a connection
    | to any private GLO legal database. Evidence is referenced, never stored
    | as raw documents in ordinary columns.
    |
    */

    'freeze' => [
        'states' => ['requested', 'under_review', 'frozen', 'released', 'rejected', 'expired'],
        'evidence_required_before_freeze' => true,
        'request_limitation_years' => 2,
        'minimum_evidence_fields' => [
            'evidence_reference',
            'authority',
            'case_reference',
        ],
        'products' => ['l6', 'n3'],
        'expire_creates_transition' => true,
        'public_announcement_on_win' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | GLO-12/14 Prize claim + payment-hold lifecycle rules
    |--------------------------------------------------------------------------
    |
    | Age rule is the official pay-condition: 20 years or older. DOB must be
    | server-held (users.date_of_birth); a client-sent `age` is never trusted.
    |
    */

    'claims' => [
        'states' => ['pending', 'eligible', 'hold', 'approved', 'paid', 'rejected', 'cancelled'],
        'min_claimant_age' => 20,
        'window_years' => 2,
        'channels' => ['glo_office', 'provincial_office', 'bank', 'partner_platform'],
        'physical_requires_original_ticket' => true,
        'digital_channels' => ['partner_platform', 'bank'],
        'hold_reason_frozen' => 'FROZEN_TICKET',
        'payment_status_blocked' => 'blocked',
        'payment_status_executed' => 'executed',
        'payment_status_hold' => 'payment_hold',
    ],

    /*
    |--------------------------------------------------------------------------
    | GLO-13 Public ticket status / verification
    |--------------------------------------------------------------------------
    |
    | Anti-enumeration: lookups are normalized, rate-limited, and only expose
    | statuses that are legitimately public. No claimant PII, no evidence, no
    | internal notes, no staff identifiers.
    |
    */

    'public_status' => [
        'statuses' => [
            'NOT_FOUND',
            'NOT_FROZEN',
            'FROZEN',
            'FROZEN_AND_WINNING_PAYMENT_HELD',
            'RELEASED',
            'EXPIRED',
        ],
        'rate_limit_per_minute' => (int) env('GLO_PUBLIC_STATUS_PER_MINUTE', 30),
        'reference_pattern' => '/^\d{1,10}-(l6|n3)-[0-9A-Za-z]{1,16}$/',
    ],

    /*
    |------------------------------------------------------------------------
    | GLO-15 dealer e-Service
    |------------------------------------------------------------------------
    | Dealers are represented by app/Models/GloDealer (user-bound). Agent and
    | RetailVendor stay untouched for commission/quota lanes. dealer_ref is a
    | SYNTHETIC project reference — never a real GLO identity number.
    */
    'dealer' => [
        'change_types' => ['name', 'address', 'phone', 'sales_location'],
        // Workflow: submitted → under_review → approved | rejected.
        'workflow' => ['submitted', 'under_review', 'approved', 'rejected', 'cancelled'],
        'allow_self_approve' => false,
        'open_request_unique_per_type' => true,
        'max_history_page' => 100,
        'proxy_history_source' => 'internal_agent_lane', // labeled, not GLO-official
    ],

    /*
    |------------------------------------------------------------------------
    | GLO-16 sales points
    |------------------------------------------------------------------------
    */
    'sales_points' => [
        'public_page_size' => 20,
        'max_page_size' => 50,
        'max_radius_km' => 50.0, // float only for geo filter bound; money never float
        'default_radius_km' => 10.0,
        'max_results' => 50,
        'history_retention' => 365,
        // Only 'fixture' or 'operator' sources exist without an official feed.
        'allowed_sources' => ['fixture', 'operator'],
        'official_sync' => [
            'mode' => 'not_configured', // no authorized public sales-point feed
            'endpoint' => null,
        ],
    ],

    /*
    |------------------------------------------------------------------------
    | GLO-17 saved tickets + result notification
    |------------------------------------------------------------------------
    */
    'saved_tickets' => [
        'max_per_user' => 100,
        'products' => ['l6', 'n3'],
        // Notification idempotency: user_id + ticket_id + result_version + type
        'notification_types' => ['glo_result_available'],
    ],

    /*
    |------------------------------------------------------------------------
    | GLO-18 public result experience
    |------------------------------------------------------------------------
    */
    'result_experience' => [
        // Official apps document a rolling 2-year public history window.
        'history_years' => 2,
        'history_page_size' => 20,
        'max_history_page_size' => 50,
        'cache_ttl_seconds' => 60,
        // Cache key parts: product|draw|result_version
        'result_version_source' => 'draw_results.metadata.glo.import_fingerprint',
        'live_draw' => [
            // No authorized GLO live stream URL is configured for this project.
            'mode' => 'not_configured',
            'provider' => null,
            'embed_url' => null,
            'replay_catalog' => 'not_configured',
        ],
        'data_matrix' => [
            // Proprietary GLO Data Matrix encoding is NOT publicly documented.
            // The adapter validates an envelope only and reports UNSUPPORTED_FORMAT
            // unless a controlled fixture with an authorized schema is present.
            'mode' => 'not_configured',
            'fixture_schema_version' => 'SYNTHETIC_FIXTURE_V1',
            'max_payload_bytes' => 4096,
        ],
        'source_labels' => [
            'verified' => 'OFFICIAL_SOURCE_VERIFIED',
            'configured' => 'OFFICIAL_SOURCE_CONFIGURED',
            'reconciled' => 'INTERNAL_RECONCILED',
            'fixture' => 'FIXTURE_ONLY',
            'not_configured' => 'NOT_CONFIGURED',
            'unavailable' => 'UNAVAILABLE',
        ],
    ],

    'notifications' => [
        // Reuse App\Services\Notification (single system). Push provider is
        // NOT_CONFIGURED unless BROADCAST/push driver is wired; database/in-app
        // still works. Never claim push delivery when unconfigured.
        'push_provider' => 'not_configured',
        'default_channel' => 'in_app',
        'event_result' => 'glo_result_available',
        'event_dealer_request' => 'dealer_request_update',
    ],
];
