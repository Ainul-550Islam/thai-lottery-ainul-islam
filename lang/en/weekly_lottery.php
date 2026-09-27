<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Weekly Lottery result surface — English (PROMPT 6)
|--------------------------------------------------------------------------
|
| NO NUMBERS LIVE IN THIS FILE. Not a 6Ball, not a 3Ball, not a sample draw.
| Every value a page renders comes from the database, and every string here is
| a LABEL for such a value. A translation file carrying an example result
| would, the moment someone copied it into a view, become fabricated data with
| a translator's name on it.
|
| NO CLAIM OF OFFICIAL STATUS. The phrase "official GLO" does not appear, and
| neither does any market nickname a third party uses for a weekly draw. This
| lane presents results with their provenance attached; whether a given
| version is officially sourced is a per-row fact rendered from the
| OFFICIAL_SOURCE_VERIFIED badge, never a blanket sentence in a heading.
|
| The source_state and status blocks mirror the CLOSED vocabularies in
| config/weekly_lottery.php. A state with no entry here would render its raw
| key, which is why both lists are kept complete - and why lang/th carries the
| identical key set.
|
*/

return [

    // ---------------------------------------------------------------- SEO --
    'meta_title' => 'Weekly Lottery Results',
    'meta_title_search' => 'Weekly Lottery Results — Search',
    'meta_description' => 'Published Weekly Lottery results with the 6Ball, 3Ball and 2Ball shown together with their source, import time and record version.',
    'meta_description_year' => 'Weekly Lottery results for :year, with the source and version recorded for every published draw.',

    // ------------------------------------------------------------- Page ----
    'heading' => 'Weekly Lottery Results',
    'intro' => 'Every result below is shown together with where it came from, when it was imported and which version of the record you are reading.',
    'not_official_notice' => 'This page presents results recorded by this platform with their source attached. It is not an official government publication, and a result is only as authoritative as the source badge shown beside it.',
    'skip_to_content' => 'Skip to content',

    'current_result_heading' => 'Latest published result',
    'recent_draws_heading' => 'Recent draws',
    'history_heading' => 'Results history',
    'detail_heading' => 'Draw detail',
    'provenance_heading' => 'Where this result came from',
    'integrity_heading' => 'Record integrity',

    // ------------------------------------------------------------ Fields ---
    'field_first_6' => '6Ball',
    'field_three_ball' => '3Ball',
    'field_two_ball' => '2Ball',
    'field_date' => 'Draw date',

    'field_first_6_hint' => 'Six digits, leading zeros included.',
    'field_three_ball_hint' => 'Three digits, leading zeros included.',
    'field_two_ball_hint' => 'Two digits, leading zeros included.',

    // ------------------------------------------------------------- Table ---
    'table_caption' => 'Published Weekly Lottery results, newest draw first. A draw with no published numbers is shown with its fields marked as not published.',
    'col_draw_date' => 'Draw date',
    'col_first_6' => '6Ball',
    'col_three_ball' => '3Ball',
    'col_two_ball' => '2Ball',
    'col_source' => 'Source',
    'col_detail' => 'Detail',
    'view_detail' => 'View draw',

    // ------------------------------------------------- Missing result -------
    'no_value' => 'Not published',
    'result_unavailable_badge' => 'No result published',
    'result_unavailable_explainer' => 'This draw is on record and no numbers were published for it. Nothing is shown in place of the missing values, because a substituted zero would look like a real result.',

    // -------------------------------------------------------------- Date ---
    'date_buddhist_label' => 'Thai Buddhist year',
    'date_gregorian_label' => 'Gregorian date',
    'date_timezone_label' => 'Draw timezone',
    'published_at_label' => 'Published',

    // -------------------------------------------------------- Year nav -----
    'year_nav_heading' => 'Browse by year',
    'year_nav_empty' => 'No year has a published result yet.',
    'year_draw_count' => ':count draws',

    // -------------------------------------------------------- Search --------
    'search_heading' => 'Search results',
    'search_intro' => 'Choose what you are searching for, then enter the exact value. Leading zeros matter and are preserved exactly as typed.',
    'search_type_label' => 'Search by',
    'search_term_label' => 'Value',
    'search_term_placeholder' => 'Exact value',
    'search_submit' => 'Search',
    'search_reset' => 'Clear',
    'search_results_heading' => 'Search results',
    'search_matched_in' => 'Matched in',
    'search_hint' => 'Matching is exact: 09 finds 09 and nothing else. Nothing is matched partially.',
    'search_type_missing' => 'Choose what you are searching for before searching.',

    'search_type' => [
        '6ball' => '6Ball',
        '3ball' => '3Ball',
        '2ball' => '2Ball',
        'date' => 'Draw date',
    ],

    // --------------------------------------------------------- Pagination --
    'pagination_previous' => 'Previous',
    'pagination_next' => 'Next',
    'pagination_status' => 'Page :page of :last',
    'pagination_total' => ':total draws',

    // -------------------------------------------------------- Provenance ---
    'provenance' => [
        'provider' => 'Provider',
        'source_state' => 'Source state',
        'source_identifier' => 'Source reference',
        'source_host' => 'Source host',
        'payload_fingerprint' => 'Payload fingerprint',
        'normalized_fingerprint' => 'Value fingerprint',
        'parser_version' => 'Parser version',
        'retrieved_at' => 'Retrieved',
        'imported_at' => 'Imported',
        'result_version' => 'Result version',
        'supersedes' => 'Replaces version',
        'none' => 'Not recorded',
        'explainer' => 'The fingerprints are one-way hashes of the delivered payload and of its canonical values. They let a record be matched against its source without republishing the source.',
    ],

    // --------------------------------------------------------- Integrity ---
    'integrity' => [
        'status' => 'Integrity check',
        'canonical_version' => 'Canonical format',
        'independently_verified' => 'Independently re-derived',
        'yes' => 'Yes',
        'no' => 'No',
        'explainer' => 'The canonical form of this result is hashed when it is imported. Where the independent verifier is installed, it rebuilds those bytes separately and both hashes must agree before the record is stored.',
    ],

    'integrity_status' => [
        'integrity_hash_only' => 'Hash verified (not signed)',
        'signed_verified' => 'Cryptographically signed and verified',
        'signature_invalid' => 'Signature did not verify',
        'fingerprint_mismatch' => 'Fingerprint did not match',
        'verifier_unavailable' => 'Independent verifier not installed',
        'not_verified' => 'Not checked',
        'rejected' => 'Rejected',
    ],

    'integrity_hint' => [
        'integrity_hash_only' => 'The stored values match their recorded hash. No signing key is configured for this source, so nothing is being claimed about who authorised the numbers.',
        'signed_verified' => 'A configured signing key verified this record against its canonical form.',
        'signature_invalid' => 'A signature was supplied for this record and did not verify.',
        'fingerprint_mismatch' => 'The recorded hash does not match the stored values.',
        'verifier_unavailable' => 'The independent verifier was not available when this record was imported; the hash was computed by the application alone.',
        'not_verified' => 'No integrity check is recorded for this version.',
        'rejected' => 'This record was refused by the integrity check.',
    ],

    // ------------------------------------------------------ Source states --
    'source_state' => [
        'official_source_verified' => 'Official source, verified',
        'official_source_configured' => 'Official source, not yet verified',
        'internal_reconciled' => 'Internally reconciled',
        'fixture_only' => 'Test fixture data',
        'not_configured' => 'No source configured',
        'unavailable' => 'Source unavailable',
    ],

    'source_state_hint' => [
        'official_source_verified' => 'Delivered by the configured official provider and validated on import.',
        'official_source_configured' => 'An official provider is configured but has not verified this record.',
        'internal_reconciled' => 'Recorded and reconciled inside this platform rather than delivered by an official provider.',
        'fixture_only' => 'Sample data used for development and testing. It is not a real draw result.',
        'not_configured' => 'No provider is configured for this lane, so no source can be claimed.',
        'unavailable' => 'The source of this record could not be established.',
    ],

    // ------------------------------------------------------- Lookup states --
    'status' => [
        'result_found' => 'Result found.',
        'result_unavailable' => 'This draw is on record and no numbers were published for it.',
        'result_not_found' => 'No published result matches that request.',
        'no_public_data' => 'There is no published result to show yet.',
        'invalid_query' => 'That request could not be read. Check the value and try again.',
        'unavailable' => 'Results are temporarily unavailable.',
    ],

    'empty_current' => 'No Weekly Lottery result has been published yet. Nothing is shown here until a result has been imported with a recorded source.',
    'empty_year' => 'No published result exists for this year.',
    'empty_search' => 'No published draw matches that search.',

    // ------------------------------------------------------- Corrections ---
    'correction_notice' => 'This draw has been corrected. You are reading version :version; the earlier version is retained in the record.',
    'conflict_notice' => 'A newer payload for this draw disagrees with the published result. Publication is on hold until the disagreement is resolved, and the previously verified result stays on this page.',
];
