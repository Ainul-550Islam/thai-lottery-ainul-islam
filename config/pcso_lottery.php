<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PCSO Lottery result lane (PROMPT 9)
|--------------------------------------------------------------------------
|
| A SEPARATE PRODUCT LANE. It is not GLO L6, not GLO N3, not the National
| Lottery lane, not the Weekly Lottery lane, and not an operator market. It
| publishes results and states where they came from. There is no stake, no
| payout, no jackpot and no commission anywhere in this file, because there is
| none anywhere in this lane.
|
| MULTIPLE DRAWS PER DAY. This is the structural difference from every other
| lane in the repository. PCSO publishes several draws on one calendar date -
| for example 14:00, 17:00 and 21:00 - so a date does NOT identify a draw. The
| 'draw_times' block below turns that on, and the shared engine then keys draw
| identity on date AND time. Without it the 17:00 result would be imported as
| a correction of the 14:00 one.
|
| WHAT THIS LANE CANNOT DO. It has no integrity verifier, so this file has no
| 'integrity' block and no PCSO_LOTTERY_INTEGRITY_* key. The Weekly lane runs
| an independent Rust verifier and can therefore say something stronger about
| its fingerprints; this lane hashes in PHP and says exactly that -
| INTEGRITY_HASH_ONLY, independently_verified = false. Wiring the Weekly
| verifier in here and labelling PCSO results "signed verified" would be a
| false claim: that crate canonicalises the Weekly schema, and a hash without
| a trusted authorisation is an integrity signal, not a signature.
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

    'product_key' => 'pcso_lottery',

    'projection_version' => (string) env('PCSO_LOTTERY_PROJECTION_VERSION', '1'),

    'enabled' => (bool) env('PCSO_LOTTERY_ENABLED', true),

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

    'timezone' => (string) env('PCSO_LOTTERY_TIMEZONE', env('APP_TIMEZONE', 'Asia/Bangkok')),

    'calendar' => [
        'buddhist_offset' => 543,

        // Bounds exist so a crafted /year/{year} cannot ask the database to
        // scan for a year that could never hold a draw.
        'min_gregorian_year' => (int) env('PCSO_LOTTERY_MIN_YEAR', 1990),
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

        'six_digit' => [
            'length' => 6,
            'pattern' => '/^[0-9]{6}$/',
            'label_key' => 'pcso_lottery.field_six_digit',
        ],

        'four_digit' => [
            'length' => 4,
            'pattern' => '/^[0-9]{4}$/',
            'label_key' => 'pcso_lottery.field_four_digit',
        ],

        'three_digit' => [
            'length' => 3,
            'pattern' => '/^[0-9]{3}$/',
            'label_key' => 'pcso_lottery.field_three_digit',
        ],

        'two_digit' => [
            'length' => 2,
            'pattern' => '/^[0-9]{2}$/',
            'label_key' => 'pcso_lottery.field_two_digit',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Draw times
    |--------------------------------------------------------------------------
    |
    | THE ONE SETTING THAT CHANGES WHAT A DRAW IS.
    |
    | With this enabled the shared engine keys draw identity on date AND local
    | draw time, orders history by date then time, and includes the time in the
    | public draw reference. Weekly, Mega and National leave it off and keep
    | one draw per date.
    |
    | The times are NOT an allow-list of when draws may happen - inventing that
    | list would mean rejecting a real draw the operator scheduled differently.
    | They are only what the public page offers as a filter hint.
    |
    */

    'draw_times' => [
        'enabled' => true,
        'display_format' => 'h:i A',
    ],

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    |
    | TYPE IS EXPLICIT, NEVER GUESSED FROM LENGTH. '09' could be a 2D or the
    | first two characters of something else; guessing would let the page claim
    | a match in a field the visitor never asked about. The type arrives as one
    | of this closed list or the request is refused.
    |
    | FOUR SEARCHABLE WIDTHS HERE: 6, 4, 3 and 2. A five-digit or one-digit
    | term matches no declared width and is refused before a query exists.
    |
    | The type map is also the only place a column name can enter a query. A
    | type outside it cannot reach the database at all, which is what keeps the
    | search free of user-supplied column names.
    |
    */

    'search' => [
        'types' => ['6d', '4d', '3d', '2d', 'date'],
        'default_type' => '6d',

        'type_map' => [
            '6d' => ['column' => 'six_digit', 'length' => 6, 'pattern' => '/^[0-9]{6}$/'],
            '4d' => ['column' => 'four_digit', 'length' => 4, 'pattern' => '/^[0-9]{4}$/'],
            '3d' => ['column' => 'three_digit', 'length' => 3, 'pattern' => '/^[0-9]{3}$/'],
            '2d' => ['column' => 'two_digit', 'length' => 2, 'pattern' => '/^[0-9]{2}$/'],
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
    | authorised PCSO Lottery data agreement. While the endpoint is empty the
    | lane can reach INTERNAL_RECONCILED or FIXTURE_ONLY and can never reach
    | OFFICIAL_SOURCE_VERIFIED. Configuring a URL does not upgrade data that
    | did not come from it.
    |
    */

    'sources' => [

        'priority' => ['official', 'internal', 'replay', 'fixture'],

        'fall_through_to_fixture' => false,

        'official' => [
            'endpoint' => env('PCSO_LOTTERY_OFFICIAL_ENDPOINT'),
            'token' => env('PCSO_LOTTERY_OFFICIAL_TOKEN'),
            'timeout_seconds' => (int) env('PCSO_LOTTERY_OFFICIAL_TIMEOUT', 8),
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
            'enabled' => (bool) env('PCSO_LOTTERY_FIXTURE_ENABLED', true),
            'provider_label' => 'fixture',
            'schema_version' => 'PCSO_FIXTURE_V1',
        ],

        'parser_version' => (string) env('PCSO_LOTTERY_PARSER_VERSION', '1'),
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

    'canonical_version' => 'PCSO1',

    /*
    |--------------------------------------------------------------------------
    | Publication policy
    |--------------------------------------------------------------------------
    */

    'publication' => [
        // PARTIAL RESULTS ARE LEGITIMATE IN THIS LANE.
        //
        // PCSO runs its 6D/4D/3D/2D categories independently, and the public
        // surface shows "Off" for one that did not run on a given draw. So a
        // payload carrying three of the four categories is a real result, not
        // a broken delivery, and the absent category is stored as NULL.
        //
        // Weekly, Mega and National leave this at its default of true, where
        // a half-filled payload really does mean something went wrong.
        'require_complete_result_set' => false,

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
        'per_page' => (int) env('PCSO_LOTTERY_PER_PAGE', 20),
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
        'enabled' => (bool) env('PCSO_LOTTERY_CACHE_ENABLED', true),
        'ttl_seconds' => (int) env('PCSO_LOTTERY_CACHE_TTL', 300),
        'key_prefix' => 'pcso-lottery',
        'cache_search' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits
    |--------------------------------------------------------------------------
    |
    | Consumed by the 'pcso-result-search' limiter registered in
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
        'per_minute' => (int) env('PCSO_LOTTERY_SEARCH_PER_MINUTE', 20),
        'per_hour' => (int) env('PCSO_LOTTERY_SEARCH_PER_HOUR', 200),
        'fingerprint_per_minute' => (int) env('PCSO_LOTTERY_SEARCH_FINGERPRINT_PER_MINUTE', 6),
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
        'path' => 'pcso-lottery',
        'index_year_pages' => true,
        // Search pages are never indexed: they are query dependent and would
        // otherwise invite crawlers to enumerate the number space.
        'index_search_pages' => false,
    ],
];
