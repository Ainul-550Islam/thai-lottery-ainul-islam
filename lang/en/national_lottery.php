<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| National Lottery result surface — English (PROMPT 5)
|--------------------------------------------------------------------------
|
| NO NUMBERS LIVE IN THIS FILE. Not a first prize, not a 3Up, not a sample
| draw. Every value a page renders comes from the database, and every string
| here is a LABEL for such a value. A translation file that carried an example
| result would, the moment someone copied it into a view, become fabricated
| data with a translator's name on it.
|
| NO CLAIM OF OFFICIAL STATUS. The phrase "official GLO" does not appear.
| This lane presents results with their provenance attached; whether a given
| version is officially sourced is a per-row fact rendered from the
| OFFICIAL_SOURCE_VERIFIED badge, never a blanket sentence in a heading.
|
| The source_state and status blocks below mirror the CLOSED vocabularies in
| config/national_lottery.php. A state with no entry here would render its raw
| key, which is why both lists are kept complete.
|
*/

return [

    // ---------------------------------------------------------------- SEO --
    'meta_title' => 'National Lottery Results',
    'meta_title_search' => 'National Lottery Results — Search',
    'meta_description' => 'Published National Lottery results with the source, the import time and the result version shown for every draw.',
    'meta_description_year' => 'National Lottery results for :year, with the source and version recorded for every published draw.',

    // ------------------------------------------------------------- Page ----
    'heading' => 'National Lottery Results',
    'intro' => 'Every result below is shown together with where it came from, when it was imported and which version of the record you are reading.',
    'not_official_notice' => 'This page presents results recorded by this platform with their source attached. It is not the official GLO website or an official government publication, and a result is only as authoritative as the source badge shown beside it.',
    'skip_to_content' => 'Skip to content',

    'current_result_heading' => 'Latest published result',
    'recent_draws_heading' => 'Recent draws',
    'history_heading' => 'Results history',
    'detail_heading' => 'Draw detail',
    'provenance_heading' => 'Where this result came from',

    // ------------------------------------------------------------ Fields ---
    'field_first_prize' => '1st Prize',
    'field_three_up' => '3 Up',
    'field_two_up' => '2 Up',
    'field_three_front' => '3 Front',
    'field_three_after' => '3 After',
    'field_two_down' => '2 Down',
    // Used when a DATE search reports which field it matched on.
    'field_draw_date' => 'Draw date',

    'field_first_prize_hint' => 'Six digits, leading zeros included.',
    'field_three_front_hint' => 'Each value is a separate three-digit number, shown in the order the source supplied.',
    'field_three_after_hint' => 'Each value is a separate three-digit number, shown in the order the source supplied.',

    // ------------------------------------------------------------- Table ---
    'table_caption' => 'Published National Lottery results, newest draw first.',
    'col_draw_date' => 'Draw date',
    'col_first_prize' => '1st Prize',
    'col_three_up' => '3 Up',
    'col_two_up' => '2 Up',
    'col_three_front' => '3 Front',
    'col_three_after' => '3 After',
    'col_two_down' => '2 Down',
    'col_source' => 'Source',
    'col_detail' => 'Detail',
    'view_detail' => 'View draw',
    'no_value' => 'Not published',

    // -------------------------------------------------------------- Date ---
    'date_buddhist_label' => 'Thai Buddhist year',
    'date_gregorian_label' => 'Gregorian date',
    'published_at_label' => 'Published',

    // -------------------------------------------------------- Year nav -----
    'year_nav_heading' => 'Browse by year',
    'year_nav_empty' => 'No year has a published result yet.',
    'year_nav_current' => 'Currently viewing',
    'year_draw_count' => ':count draws',

    // -------------------------------------------------------- Search --------
    'search_heading' => 'Search results',
    'search_intro' => 'Search by a draw number or by a draw date. Leading zeros matter and are preserved exactly as typed.',
    'search_number_label' => 'Number',
    'search_number_placeholder' => 'Two to six digits',
    'search_date_label' => 'Draw date',
    'search_date_placeholder' => 'YYYY-MM-DD',
    'search_field_label' => 'Limit to field',
    'search_any_field' => 'Any matching field',
    'search_submit' => 'Search',
    'search_reset' => 'Clear',
    'search_matched_in' => 'Matched in',
    'search_results_heading' => 'Search results',
    'search_hint' => 'A six-digit term is matched against the 1st Prize, a three-digit term against 3 Up, 3 Front and 3 After, and a two-digit term against 2 Up and 2 Down.',

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
        'result_not_found' => 'No published result matches that request.',
        'no_public_data' => 'There is no published result to show yet.',
        'invalid_query' => 'That request could not be read. Check the number or the date and try again.',
        'unavailable' => 'Results are temporarily unavailable.',
    ],

    'empty_current' => 'No National Lottery result has been published yet. Nothing is shown here until a result has been imported with a recorded source.',
    'empty_year' => 'No published result exists for this year.',
    'empty_search' => 'No published draw matches that search.',

    // ------------------------------------------------------- Corrections ---
    'correction_notice' => 'This draw has been corrected. You are reading version :version; the earlier version is retained in the record.',
    'conflict_notice' => 'A newer payload for this draw disagrees with the published result. Publication is on hold until the disagreement is resolved, and the previously verified result stays on this page.',
];
