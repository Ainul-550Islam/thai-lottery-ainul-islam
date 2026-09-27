<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| National Lottery result lane (PROMPT 5)
|--------------------------------------------------------------------------
|
| WHAT THIS PRODUCT IS, AND WHAT IT IS NOT
| ----------------------------------------------------------------------
| "National Lottery Result" is a SEPARATE public data lane. It is not GLO L6,
| it is not GLO N3, and it is not one of the operator markets (3D / TOD / 2D /
| Run). Those three families keep their own tables, their own services and
| their own vocabulary:
|
|   GLO L6 / N3   → glo_* tables, App\Services\Lottery\Glo*  (first_prize,
|                   front_three, last_three, last_two, prize ladder, payouts)
|   Operator      → draws / bets / market rules              (3d_direct, …)
|   National      → national_lottery_* tables, this config   (1st Prize, 3Up,
|                   2Up, 3Front, 3After, 2Down — presentation + provenance)
|
| Mapping this lane onto GLO would be an assertion about the real world that
| nothing in this repository proves. Until an authoritative source states that
| identity, the lanes stay apart, and NOTHING here calls a GLO prize
| calculator. This lane is RESULT PRESENTATION + DATA PROVENANCE, not a payout
| engine.
|
| NO NUMBERS FROM ANYWHERE ELSE
| ----------------------------------------------------------------------
| There is not a single lottery number in this file. Every draw value comes
| from the database, written by an import that recorded where it came from.
| A "current result" that cannot be sourced is reported as UNAVAILABLE rather
| than filled in.
|
| LEADING ZEROS ARE DATA
| ----------------------------------------------------------------------
| Every field below is a fixed-width STRING. '004615' is six characters, not
| the integer 4615, and the patterns in 'fields' enforce exactly that width.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Identity
    |--------------------------------------------------------------------------
    */

    'product_key' => 'national_lottery',

    // Bumped when the public projection's SHAPE changes. Part of every cache
    // key, so a deploy that changes the shape cannot serve a stale shape.
    'projection_version' => (string) env('NATIONAL_LOTTERY_PROJECTION_VERSION', '1'),

    'enabled' => (bool) env('NATIONAL_LOTTERY_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Time
    |--------------------------------------------------------------------------
    |
    | Draw dates are business dates in the market's timezone. The Buddhist-era
    | offset lives here and ONLY here: App\Services\Lottery\
    | NationalLotteryDateService is the single reader. No Blade template and no
    | JavaScript file is allowed to add or subtract 543 anywhere.
    |
    */

    'timezone' => (string) env('NATIONAL_LOTTERY_TIMEZONE', env('APP_TIMEZONE', 'Asia/Bangkok')),

    'calendar' => [
        // Buddhist Era = Common Era + 543. Declared once.
        'buddhist_offset' => 543,

        // Bounds for any year the public may put in a URL. A year outside this
        // window is rejected by validation before a query is built - this is
        // the anti-DoS bound on /national-lottery/year/{year}.
        'min_gregorian_year' => (int) env('NATIONAL_LOTTERY_MIN_YEAR', 1990),
        'max_future_years' => 1,

        // Accepted date input formats for the public date search, tried in
        // order. All are parsed in the configured timezone.
        'accepted_date_formats' => ['Y-m-d', 'd/m/Y', 'd-m-Y'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Result fields
    |--------------------------------------------------------------------------
    |
    | The canonical shape of one National Lottery result. 'pattern' is the
    | validation applied to EVERY imported value; a payload that fails it is
    | rejected, never trimmed or padded into shape.
    |
    | 'cardinality' says how many values a list field carries. It is expressed
    | as a MIN/MAX range rather than a fixed number because the number of
    | 3Front / 3After values is a property of the source schema, not something
    | this application may decide. An import declares how many it actually
    | received; the page renders exactly that many.
    |
    | ORDERING IS NOT ASSUMED. Values are stored in the order the source
    | delivered them and are rendered in that same order. Nothing here sorts
    | them, because a sort would be an invented claim about which value is
    | "first".
    |
    */

    'fields' => [

        'first_prize' => [
            'type' => 'scalar',
            'length' => 6,
            'pattern' => '/^[0-9]{6}$/',
            'required' => true,
            'label_key' => 'national_lottery.field_first_prize',
        ],

        'three_up' => [
            'type' => 'scalar',
            'length' => 3,
            'pattern' => '/^[0-9]{3}$/',
            'required' => false,
            'label_key' => 'national_lottery.field_three_up',
        ],

        'two_up' => [
            'type' => 'scalar',
            'length' => 2,
            'pattern' => '/^[0-9]{2}$/',
            'required' => false,
            'label_key' => 'national_lottery.field_two_up',
        ],

        'three_front' => [
            'type' => 'list',
            'length' => 3,
            'pattern' => '/^[0-9]{3}$/',
            'cardinality' => ['min' => 0, 'max' => 4],
            'required' => false,
            'label_key' => 'national_lottery.field_three_front',
        ],

        'three_after' => [
            'type' => 'list',
            'length' => 3,
            'pattern' => '/^[0-9]{3}$/',
            'cardinality' => ['min' => 0, 'max' => 4],
            'required' => false,
            'label_key' => 'national_lottery.field_three_after',
        ],

        'two_down' => [
            'type' => 'scalar',
            'length' => 2,
            'pattern' => '/^[0-9]{2}$/',
            'required' => false,
            'label_key' => 'national_lottery.field_two_down',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Source policy
    |--------------------------------------------------------------------------
    |
    | PRIORITY IS NOT A FALLBACK CHAIN. The order below is the order in which a
    | provider is ASKED, but 'fall_through_to_fixture' is false: when the
    | official provider is configured and fails, the lane reports the failure.
    | It does not quietly answer with fixture data wearing a production face.
    |
    | A fixture import is always stamped FIXTURE_ONLY and can never be
    | relabelled by any code path - NationalLotterySourceService has no branch
    | that promotes it.
    |
    | CREDENTIALS ARE NEVER IN THIS FILE. 'official.endpoint' and
    | 'official.token' read env only, and NationalLotterySourceService never
    | includes them in any public projection.
    |
    */

    'sources' => [

        'priority' => ['official', 'internal', 'fixture'],

        'fall_through_to_fixture' => false,

        'official' => [
            // Empty by default: this repository has NO authorised National
            // Lottery data agreement, so the honest default is "not
            // configured" rather than a guessed endpoint.
            'endpoint' => env('NATIONAL_LOTTERY_OFFICIAL_ENDPOINT'),
            'token' => env('NATIONAL_LOTTERY_OFFICIAL_TOKEN'),
            'timeout_seconds' => (int) env('NATIONAL_LOTTERY_OFFICIAL_TIMEOUT', 8),
            'provider_label' => 'official',
        ],

        'internal' => [
            'enabled' => true,
            'provider_label' => 'internal',
        ],

        'fixture' => [
            // Fixture imports are for local development and the test suite.
            // Enabling this in production still cannot produce an official
            // label; it only allows the FIXTURE_ONLY lane to exist.
            'enabled' => (bool) env('NATIONAL_LOTTERY_FIXTURE_ENABLED', true),
            'provider_label' => 'fixture',
            'schema_version' => 'NATIONAL_FIXTURE_V1',
        ],

        // Parser version travels with every stored version row, so a payload
        // re-read by a newer parser is distinguishable from the old reading.
        'parser_version' => (string) env('NATIONAL_LOTTERY_PARSER_VERSION', '1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Publication policy
    |--------------------------------------------------------------------------
    */

    'publication' => [
        // A version in CONFLICT is never publishable. Resolution is an
        // explicit, recorded act by an authorised operator.
        'block_publication_on_conflict' => true,

        // Which source states a version may be published from. FIXTURE_ONLY is
        // publishable only when fixtures are explicitly enabled, and even then
        // the page keeps showing the FIXTURE_ONLY badge.
        'publishable_source_states' => [
            'OFFICIAL_SOURCE_VERIFIED',
            'INTERNAL_RECONCILED',
            'FIXTURE_ONLY',
        ],

        // A published version's result values are frozen. A correction is a
        // NEW version; the old one is retained and marked superseded.
        'immutable_after_publication' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Public page bounds
    |--------------------------------------------------------------------------
    |
    | Every one of these is a hard ceiling applied BEFORE a query is built, so
    | a crafted URL cannot ask the database for an unbounded scan.
    |
    */

    'page' => [
        'per_page' => (int) env('NATIONAL_LOTTERY_PER_PAGE', 20),
        'max_per_page' => 50,

        // How many recent draws the landing page lists under the current
        // result, and how many years the navigation may render.
        'recent_rows' => 12,
        'max_years_listed' => 40,

        // Longest public search input accepted, in characters. Anything longer
        // is rejected by the request validator.
        'max_search_length' => 32,
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Only PUBLISHED projections are cached, and every key carries the version
    | that produced it, so publishing a correction cannot serve the superseded
    | numbers: the new version writes a new key.
    |
    | Search results are not cached. A cache keyed on user-supplied input is an
    | oracle with a memory, and the query volume it would serve is exactly the
    | volume the rate limiter exists to refuse.
    |
    */

    'cache' => [
        'enabled' => (bool) env('NATIONAL_LOTTERY_CACHE_ENABLED', true),
        'ttl_seconds' => (int) env('NATIONAL_LOTTERY_CACHE_TTL', 300),
        'key_prefix' => 'national-lottery',
        'cache_search' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limiting
    |--------------------------------------------------------------------------
    |
    | Consumed by the named limiter 'national-result-search', registered in
    | AppServiceProvider next to the existing limiters. Search over a
    | 1,000,000-value six-digit space is enumerable, so the ceilings mirror the
    | shape already used by 'ticket-verification': IP per minute, IP per hour,
    | and a HASHED query fingerprint per minute.
    |
    */

    'rate_limit' => [
        'per_minute' => (int) env('NATIONAL_LOTTERY_SEARCH_PER_MINUTE', 20),
        'per_hour' => (int) env('NATIONAL_LOTTERY_SEARCH_PER_HOUR', 200),
        'fingerprint_per_minute' => (int) env('NATIONAL_LOTTERY_SEARCH_FINGERPRINT_PER_MINUTE', 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Public vocabularies
    |--------------------------------------------------------------------------
    |
    | Closed lists. A value not present here is downgraded before it reaches a
    | template, so a new internal state can never leak to the public by simply
    | existing.
    |
    */

    'public_source_states' => [
        'OFFICIAL_SOURCE_VERIFIED',
        'INTERNAL_RECONCILED',
        'FIXTURE_ONLY',
        'NOT_CONFIGURED',
        'UNAVAILABLE',
    ],

    'public_lookup_states' => [
        'RESULT_FOUND',
        'RESULT_NOT_FOUND',
        'NO_PUBLIC_DATA',
        'INVALID_QUERY',
        'UNAVAILABLE',
    ],

    /*
    |--------------------------------------------------------------------------
    | SEO
    |--------------------------------------------------------------------------
    |
    | Titles are built from translation keys plus real data. The phrase
    | "official GLO" is deliberately absent: this lane has no verified GLO
    | identity, so claiming one in a <title> would be a false statement served
    | to search engines.
    |
    */

    'seo' => [
        'path' => 'national-lottery',
        'index_year_pages' => true,
        // Search result pages are never indexed: they are query-dependent and
        // would otherwise invite crawlers to enumerate the number space.
        'index_search_pages' => false,
    ],
];
