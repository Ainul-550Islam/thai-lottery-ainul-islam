<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Weekly Lottery result lane (PROMPT 6)
|--------------------------------------------------------------------------
|
| WHAT THIS PRODUCT IS, AND WHAT IT IS NOT
| ----------------------------------------------------------------------
| "Weekly Lottery" is a SEPARATE public data lane. It is not GLO L6, not GLO
| N3, not the National Lottery lane from PROMPT 5, and not one of the operator
| markets (3D / TOD / 2D / Run). Each family keeps its own tables, services and
| vocabulary:
|
|   GLO L6 / N3   -> glo_* tables,              App\Services\Lottery\Glo*
|   National      -> national_lottery_* tables, App\Services\Lottery\NationalLottery*
|   Operator      -> draws / bets / market rules
|   Weekly        -> weekly_lottery_* tables,   this config  (6Ball / 3Ball / 2Ball)
|
| Mapping any of these onto another would be an assertion about the real world
| that nothing in this repository proves. NOTHING here calls a GLO prize
| calculator, reads a glo_* table, or touches the National lane's data. This
| lane is RESULT PRESENTATION + DATA PROVENANCE. It has no stake, no payout, no
| jackpot and no commission, because no verified product defines those for it.
|
| THE NAME
| ----------------------------------------------------------------------
| The public wording is "Weekly Lottery Results". Any market nickname a third
| party uses for a weekly draw is THEIR wording, and adopting it would be an
| implicit claim that this data is that product. It is not adopted.
|
| NO NUMBERS LIVE IN THIS FILE
| ----------------------------------------------------------------------
| There is not one lottery value below. Every draw value comes from the
| database, written by an import that recorded where it came from. A result
| that cannot be sourced is reported UNAVAILABLE rather than filled in.
|
| A MISSING RESULT IS A REAL STATE
| ----------------------------------------------------------------------
| A weekly draw can exist with no published numbers. That is represented as
| result_status = 'unavailable' with NULL values - never as 000000 / 000 / 00,
| which would be three fabricated numbers wearing the shape of a real result.
|
| LEADING ZEROS ARE DATA
| ----------------------------------------------------------------------
| Every field below is a fixed-width STRING. '049' is three characters, not the
| integer 49, and the patterns enforce exactly that width.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Identity
    |--------------------------------------------------------------------------
    */

    'product_key' => 'weekly_lottery',

    // Bumped when the public projection's SHAPE changes. Part of every cache
    // key, so a deploy that changes the shape cannot serve a stale shape.
    'projection_version' => (string) env('WEEKLY_LOTTERY_PROJECTION_VERSION', '1'),

    'enabled' => (bool) env('WEEKLY_LOTTERY_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Time
    |--------------------------------------------------------------------------
    |
    | Read ONLY by App\Services\Lottery\WeeklyLotteryDateService, which is a
    | four-line subclass of the shared AbstractLotteryCalendarService. The
    | Buddhist-era offset is declared here and nowhere else: no Blade template
    | and no JavaScript file in this lane may add or subtract 543.
    |
    */

    'timezone' => (string) env('WEEKLY_LOTTERY_TIMEZONE', env('APP_TIMEZONE', 'Asia/Bangkok')),

    'calendar' => [
        'buddhist_offset' => 543,

        // Bounds for any year the public may put in a URL. A year outside this
        // window is rejected before a query is built.
        'min_gregorian_year' => (int) env('WEEKLY_LOTTERY_MIN_YEAR', 1990),
        'max_future_years' => 1,

        'accepted_date_formats' => ['Y-m-d', 'd/m/Y', 'd-m-Y'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Draw cadence
    |--------------------------------------------------------------------------
    |
    | DELIBERATELY EMPTY OF ASSUMPTIONS. Nothing in this repository proves the
    | draw falls on a particular weekday, so no weekday is configured and no
    | scheduler generates future draws. Draw dates come from imported result
    | records only. Inventing a calendar would put fictional draws on a public
    | page, which is the same failure as inventing numbers.
    |
    */

    'cadence' => [
        'generates_future_draws' => false,
        'assumed_weekday' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Result fields
    |--------------------------------------------------------------------------
    |
    | The canonical shape of one Weekly result. 'pattern' is applied to EVERY
    | imported value; a payload that fails it is rejected, never trimmed or
    | padded into shape.
    |
    | ALL THREE OR NONE. A draw either publishes 6Ball, 3Ball and 2Ball
    | together, or publishes nothing and is marked unavailable. A partial row
    | would render a table cell that is silently missing rather than honestly
    | empty.
    |
    */

    'fields' => [

        'first_6' => [
            'length' => 6,
            'pattern' => '/^[0-9]{6}$/',
            'label_key' => 'weekly_lottery.field_first_6',
        ],

        'three_ball' => [
            'length' => 3,
            'pattern' => '/^[0-9]{3}$/',
            'label_key' => 'weekly_lottery.field_three_ball',
        ],

        'two_ball' => [
            'length' => 2,
            'pattern' => '/^[0-9]{2}$/',
            'label_key' => 'weekly_lottery.field_two_ball',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    |
    | TYPE IS EXPLICIT, NEVER GUESSED FROM LENGTH. '09' could be a 2Ball or the
    | first two characters of something else; guessing would let the page claim
    | a match in a field the visitor never asked about. The type arrives as one
    | of this closed list or the request is refused.
    |
    */

    'search' => [
        'types' => ['6ball', '3ball', '2ball', 'date'],
        'default_type' => '6ball',

        // Maps a search type to its column and exact width. The service reads
        // this map; a type outside it can never reach a query.
        'type_map' => [
            '6ball' => ['column' => 'first_6', 'length' => 6, 'pattern' => '/^[0-9]{6}$/'],
            '3ball' => ['column' => 'three_ball', 'length' => 3, 'pattern' => '/^[0-9]{3}$/'],
            '2ball' => ['column' => 'two_ball', 'length' => 2, 'pattern' => '/^[0-9]{2}$/'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Source policy
    |--------------------------------------------------------------------------
    |
    | PRIORITY IS NOT A FALLBACK CHAIN. 'fall_through_to_fixture' is false: when
    | the official provider is configured and fails, the lane reports the
    | failure. It does not quietly answer with fixture data wearing a
    | production face, and it does not re-serve an older draw as if it were
    | current.
    |
    | CREDENTIALS ARE NEVER IN THIS FILE. 'official.endpoint' and
    | 'official.token' read env only, and no service includes them in any
    | public projection - only the endpoint's HOST is ever stored or shown.
    |
    */

    'sources' => [

        'priority' => ['official', 'internal', 'replay', 'fixture'],

        'fall_through_to_fixture' => false,

        'official' => [
            // Empty by default: this repository has NO authorised Weekly
            // Lottery data agreement, so the honest default is "not
            // configured" rather than a guessed endpoint.
            'endpoint' => env('WEEKLY_LOTTERY_OFFICIAL_ENDPOINT'),
            'token' => env('WEEKLY_LOTTERY_OFFICIAL_TOKEN'),
            'timeout_seconds' => (int) env('WEEKLY_LOTTERY_OFFICIAL_TIMEOUT', 8),
            'provider_label' => 'official',
        ],

        'internal' => [
            'enabled' => true,
            'provider_label' => 'internal',
        ],

        // A replay re-reads a payload this platform already holds. It can never
        // outrank an internal reconciliation, because no new external evidence
        // arrived.
        'replay' => [
            'enabled' => true,
            'provider_label' => 'replay',
        ],

        'fixture' => [
            // PRODUCTION FIXTURE CONTAMINATION GUARD.
            //
            // A fixture lane may never exist in production, whatever the
            // environment file says. .env.example shipped this flag as `true`
            // and `composer create-project` copies .env.example to .env, so a
            // stock deployment could stand up a fake-result lane. The env var
            // is still honoured everywhere else so local work and the test
            // suite are unaffected.
            'enabled' => env('APP_ENV') === 'production'
                ? false
                : (bool) env('WEEKLY_LOTTERY_FIXTURE_ENABLED', false),
            'provider_label' => 'fixture',
            'schema_version' => 'WEEKLY_FIXTURE_V1',
        ],

        'parser_version' => (string) env('WEEKLY_LOTTERY_PARSER_VERSION', '1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cryptographic integrity (Rust)
    |--------------------------------------------------------------------------
    |
    | security/weekly-result-integrity is an isolated, non-networked Rust crate
    | that independently rebuilds the canonical bytes for a result and hashes
    | them. Laravel computes the same bytes in PHP; the two must agree or the
    | import is refused.
    |
    | THIS IS DEFENCE IN DEPTH, NOT A GUARANTEE. It catches a stored result
    | whose fingerprint no longer matches its values - a PHP canonicalizer bug,
    | a partial write, a tampered row. It does not make anything unhackable,
    | and it holds no authority: Laravel's policies and the database's
    | constraints remain the only gates.
    |
    | 'required' = false by default so a fresh clone with no compiled binary
    | still works; the PHP canonicalizer alone then produces the fingerprint
    | and the report says so honestly (VERIFIER_UNAVAILABLE). Set it true in an
    | environment where the binary is deployed and a missing verifier should
    | block imports.
    |
    | NO SIGNING KEY IS SHIPPED. Without one the answer is
    | INTEGRITY_HASH_ONLY - a hash is not a signature, and reporting
    | SIGNED_VERIFIED without a key would be a false assurance.
    |
    */

    'integrity' => [
        'enabled' => (bool) env('WEEKLY_LOTTERY_INTEGRITY_ENABLED', true),
        'binary' => env(
            'WEEKLY_LOTTERY_INTEGRITY_BINARY',
            'security/weekly-result-integrity/target/release/weekly-result-integrity',
        ),
        'timeout_seconds' => (int) env('WEEKLY_LOTTERY_INTEGRITY_TIMEOUT', 5),
        'required' => (bool) env('WEEKLY_LOTTERY_INTEGRITY_REQUIRED', false),
        'canonical_version' => 'WKLY1',

        // Hex Ed25519 public key of a real signed provider, when one exists.
        // Absent means the verifier may only report INTEGRITY_HASH_ONLY.
        'public_key_hex' => env('WEEKLY_LOTTERY_INTEGRITY_PUBLIC_KEY'),
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

        'publishable_source_states' => [
            'OFFICIAL_SOURCE_VERIFIED',
            'INTERNAL_RECONCILED',
            'FIXTURE_ONLY',
        ],

        // A published version's values are frozen. A correction is a NEW
        // version; the old one is retained and marked superseded.
        'immutable_after_publication' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Public page bounds
    |--------------------------------------------------------------------------
    |
    | Hard ceilings applied BEFORE a query is built, so a crafted URL cannot ask
    | the database for an unbounded scan.
    |
    */

    'page' => [
        'per_page' => (int) env('WEEKLY_LOTTERY_PER_PAGE', 20),
        'max_per_page' => 50,
        'recent_rows' => 12,
        'max_years_listed' => 40,
        'max_search_length' => 32,
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Only PUBLISHED projections are cached, and every key carries the version
    | that produced it, so publishing a correction cannot serve superseded
    | numbers: the new version writes a new key.
    |
    | Search is NOT cached. A cache keyed on user-supplied input is an
    | enumeration oracle with a memory, and the query volume it would serve is
    | exactly the volume the rate limiter exists to refuse.
    |
    */

    'cache' => [
        'enabled' => (bool) env('WEEKLY_LOTTERY_CACHE_ENABLED', true),
        'ttl_seconds' => (int) env('WEEKLY_LOTTERY_CACHE_TTL', 300),
        'key_prefix' => 'weekly-lottery',
        'cache_search' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limiting
    |--------------------------------------------------------------------------
    |
    | Consumed by the named limiter 'weekly-result-search', registered in
    | AppServiceProvider beside the existing limiters. A six-digit space is
    | 1,000,000 values and therefore enumerable, so the ceilings mirror the
    | shape already used by 'ticket-verification' and 'national-result-search':
    | IP per minute, IP per hour, and a HASHED query fingerprint per minute.
    |
    */

    'rate_limit' => [
        'per_minute' => (int) env('WEEKLY_LOTTERY_SEARCH_PER_MINUTE', 20),
        'per_hour' => (int) env('WEEKLY_LOTTERY_SEARCH_PER_HOUR', 200),
        'fingerprint_per_minute' => (int) env('WEEKLY_LOTTERY_SEARCH_FINGERPRINT_PER_MINUTE', 6),
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
        'RESULT_UNAVAILABLE',
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
        'path' => 'weekly-lottery',
        'index_year_pages' => true,
        // Search pages are never indexed: they are query dependent and would
        // otherwise invite crawlers to enumerate the number space.
        'index_search_pages' => false,
    ],
];
