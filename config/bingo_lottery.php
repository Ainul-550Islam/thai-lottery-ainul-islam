<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Bingo / Mega Lottery result lane (PROMPT 8)
|--------------------------------------------------------------------------
|
| A SEPARATE PRODUCT LANE. It is not GLO L6, not GLO N3, not the National
| Lottery lane, not the Weekly Lottery lane, and not an operator market. It
| publishes results and states where they came from. There is no stake, no
| payout, no jackpot and no commission anywhere in this file, because there is
| none anywhere in this lane.
|
| NAMING. The code says "bingo" because that is the roadmap's name for this
| slot; the PUBLIC page says "Mega Lottery" because that is what the surface
| actually publishes - a 6 Mega, a 3 Mega and a 2 Mega. Shipping code whose
| labels contradict its data would make every future reader guess which one is
| true, so the two names are kept deliberately and the reason is written down
| here rather than left to be rediscovered.
|
| WHAT THIS LANE CANNOT DO. It has no integrity verifier, so this file has no
| 'integrity' block and no BINGO_LOTTERY_INTEGRITY_* key. The Weekly lane runs
| an independent Rust verifier and can therefore say something stronger about
| its fingerprints; this lane hashes in PHP and says exactly that -
| INTEGRITY_HASH_ONLY, independently_verified = false. Adding the settings
| without the verifier would advertise a guarantee that does not exist.
|
| NOTHING HERE WAS COPIED. The reference surface that motivated the lane was
| read for its INFORMATION ARCHITECTURE - that a Mega result has a date and
| three numbers, and that history is browsed by year. No text, markup, value,
| prize, fee, contact detail or claim from it appears in this repository.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Identity
    |--------------------------------------------------------------------------
    |
    | product_key is what a log line, a cache key and an audit row call this
    | lane. It never changes; a rename would orphan every cached projection and
    | make historical audit rows unreadable.
    |
    | projection_version is the manual cache-busting lever. Every cache key in
    | the lane embeds it, so changing the SHAPE of a public projection - a new
    | field, a renamed key - is a one-character config change rather than a
    | deploy that serves stale structures to live visitors.
    |
    */

    'product_key' => 'bingo_lottery',

    'projection_version' => (string) env('BINGO_LOTTERY_PROJECTION_VERSION', '1'),

    'enabled' => (bool) env('BINGO_LOTTERY_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Calendar
    |--------------------------------------------------------------------------
    |
    | Draw dates are Thai calendar dates. A server in another timezone must not
    | shift them, so the lane pins its own zone rather than inheriting whatever
    | the host happens to be set to.
    |
    | The Buddhist offset is 543 and lives here as data, not as arithmetic
    | scattered through templates. Year pages are addressed by GREGORIAN year in
    | the URL and DISPLAYED in whichever calendar the locale wants; keeping the
    | offset in one place is what makes that safe.
    |
    */

    'timezone' => (string) env('BINGO_LOTTERY_TIMEZONE', env('APP_TIMEZONE', 'Asia/Bangkok')),

    'calendar' => [
        'buddhist_offset' => 543,

        // Bounds exist so a crafted /year/{year} cannot ask the database to
        // scan for a year that could never hold a draw.
        'min_gregorian_year' => (int) env('BINGO_LOTTERY_MIN_YEAR', 1990),
        'max_future_years' => 1,

        'accepted_date_formats' => ['Y-m-d', 'd/m/Y', 'd-m-Y'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cadence
    |--------------------------------------------------------------------------
    |
    | This lane does NOT generate future draws and assumes NO weekday. A draw
    | exists because a result was imported for it. Manufacturing an empty row
    | for a date nobody has published would put a draw on the public page that
    | may never have happened.
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
    | Three values, each an exact width, each a STRING.
    |
    | The patterns are anchored and length-exact on purpose. A six-character
    | field that accepts five characters would let '04615' be stored where
    | '004615' was meant, and the two are different results. The importer
    | REJECTS a wrong length; it never pads or truncates to make a payload fit,
    | because a repaired result is a fabricated one.
    |
    | Every field is nullable. A draw that published nothing stores NULL in all
    | three and a result_status of 'unavailable' - never '000000', '000' or
    | '00', which are legitimate results a real draw could produce.
    |
    */

    'fields' => [

        'first_6_mega' => [
            'length' => 6,
            'pattern' => '/^[0-9]{6}$/',
            'label_key' => 'bingo_lottery.field_first_6_mega',
        ],

        'three_mega' => [
            'length' => 3,
            'pattern' => '/^[0-9]{3}$/',
            'label_key' => 'bingo_lottery.field_three_mega',
        ],

        'two_mega' => [
            'length' => 2,
            'pattern' => '/^[0-9]{2}$/',
            'label_key' => 'bingo_lottery.field_two_mega',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    |
    | TYPE IS EXPLICIT, NEVER GUESSED FROM LENGTH. '09' could be a 2 Mega or the
    | first two characters of something else; guessing would let the page claim
    | a match in a field the visitor never asked about. The type arrives as one
    | of this closed list or the request is refused.
    |
    | The type map is also the only place a column name can enter a query. A
    | type outside it cannot reach the database at all, which is what keeps the
    | search free of user-supplied column names.
    |
    */

    'search' => [
        'types' => ['6mega', '3mega', '2mega', 'date'],
        'default_type' => '6mega',

        'type_map' => [
            '6mega' => ['column' => 'first_6_mega', 'length' => 6, 'pattern' => '/^[0-9]{6}$/'],
            '3mega' => ['column' => 'three_mega', 'length' => 3, 'pattern' => '/^[0-9]{3}$/'],
            '2mega' => ['column' => 'two_mega', 'length' => 2, 'pattern' => '/^[0-9]{2}$/'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sources
    |--------------------------------------------------------------------------
    |
    | Priority is the order in which a provider is ASKED. It is NOT a fallback
    | chain: 'fall_through_to_fixture' is false, so a failed official fetch
    | surfaces as unavailable rather than quietly becoming development data
    | wearing an official label.
    |
    | THE OFFICIAL ENDPOINT IS BLANK AND STAYS BLANK. This repository holds no
    | authorised Mega Lottery data agreement. While the endpoint is empty the
    | lane can reach INTERNAL_RECONCILED or FIXTURE_ONLY and can never reach
    | OFFICIAL_SOURCE_VERIFIED. Configuring a URL does not upgrade data that
    | did not come from it.
    |
    */

    'sources' => [

        'priority' => ['official', 'internal', 'replay', 'fixture'],

        'fall_through_to_fixture' => false,

        'official' => [
            'endpoint' => env('BINGO_LOTTERY_OFFICIAL_ENDPOINT'),
            'token' => env('BINGO_LOTTERY_OFFICIAL_TOKEN'),
            'timeout_seconds' => (int) env('BINGO_LOTTERY_OFFICIAL_TIMEOUT', 8),
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
            'enabled' => (bool) env('BINGO_LOTTERY_FIXTURE_ENABLED', false),
            'provider_label' => 'fixture',
            'schema_version' => 'BINGO_FIXTURE_V1',
        ],

        'parser_version' => (string) env('BINGO_LOTTERY_PARSER_VERSION', '1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fingerprint domain
    |--------------------------------------------------------------------------
    |
    | The canonical label stored beside every fingerprint. It records WHICH
    | canonicalisation produced the hash, so a later change to the byte layout
    | is distinguishable from a tampered row rather than looking identical to
    | one.
    |
    | There is no verifier binary in this lane, so a fingerprint here means
    | "PHP hashed these canonical bytes" and nothing more. That is exactly what
    | INTEGRITY_HASH_ONLY says, and it is the strongest claim this lane is
    | entitled to make.
    |
    */

    'canonical_version' => 'MEGA1',

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
        'per_page' => (int) env('BINGO_LOTTERY_PER_PAGE', 20),
        'max_per_page' => 50,
        'recent_rows' => 12,
        'max_years_listed' => 40,
        'max_search_length' => 32,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | SEARCH IS NEVER CACHED. 'cache_search' is false and the search service
    | has no cache call in it. Caching a query-dependent page over an
    | enumerable number space would turn the cache into a free enumeration
    | oracle and would let one visitor's query answer another's.
    |
    */

    'cache' => [
        'enabled' => (bool) env('BINGO_LOTTERY_CACHE_ENABLED', true),
        'ttl_seconds' => (int) env('BINGO_LOTTERY_CACHE_TTL', 300),
        'key_prefix' => 'bingo-lottery',
        'cache_search' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits
    |--------------------------------------------------------------------------
    |
    | Consumed by the 'bingo-result-search' limiter registered in
    | AppServiceProvider beside the existing limiters. A six-digit space is
    | 1,000,000 values and therefore enumerable, so the ceilings mirror the
    | shape already used by 'national-result-search' and
    | 'weekly-result-search': IP per minute, IP per hour, and a HASHED query
    | fingerprint per minute.
    |
    | robots.txt asks crawlers to stay out of the same route. That is a
    | request; these numbers are the control.
    |
    */

    'rate_limit' => [
        'per_minute' => (int) env('BINGO_LOTTERY_SEARCH_PER_MINUTE', 20),
        'per_hour' => (int) env('BINGO_LOTTERY_SEARCH_PER_HOUR', 200),
        'fingerprint_per_minute' => (int) env('BINGO_LOTTERY_SEARCH_FINGERPRINT_PER_MINUTE', 6),
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
        'path' => 'bingo-lottery',
        'index_year_pages' => true,
        // Search pages are never indexed: they are query dependent and would
        // otherwise invite crawlers to enumerate the number space.
        'index_search_pages' => false,
    ],
];
