<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Mega Lottery result surface — English (PROMPT 8)
|--------------------------------------------------------------------------
|
| NO NUMBERS LIVE IN THIS FILE. Not a 6 Mega, not a 3 Mega, not a sample draw.
| Every value a page renders comes from the database, and every string here is
| a LABEL for such a value. A translation file carrying an example result
| would, the moment someone copied it into a view, become fabricated data with
| a translator's name on it.
|
| NO CLAIM OF OFFICIAL STATUS. The phrase "official GLO" does not appear, and
| neither does any market nickname a third party uses for this draw. This
| lane presents results with their provenance attached; whether a given
| version is officially sourced is a per-row fact rendered from the
| OFFICIAL_SOURCE_VERIFIED badge, never a blanket sentence in a heading.
|
| The source_state and status blocks mirror the CLOSED vocabularies in
| config/bingo_lottery.php. A state with no entry here would render its raw
| key, which is why both lists are kept complete - and why lang/th carries the
| identical key set.
|
*/

return [

    // ---------------------------------------------------------------- SEO --
    'meta_title' => 'Mega Lottery Results',
    'meta_title_search' => 'Mega Lottery Results — Search',
    'meta_description' => 'Published Mega Lottery results with the 6 Mega, 3 Mega and 2 Mega shown together with their source, import time and record version.',
    'meta_description_year' => 'Mega Lottery results for :year, with the source and version recorded for every published draw.',

    // ------------------------------------------------------------- Page ----
    'heading' => 'Mega Lottery Results',
    'public_name' => 'Mega Lottery',
    'intro' => 'Every result below is shown together with where it came from, when it was imported and which version of the record you are reading.',
    'not_official_notice' => 'This page presents results recorded by this platform with their source attached. It is not an official government publication, and a result is only as authoritative as the source badge shown beside it.',
    'skip_to_content' => 'Skip to content',
    'latest_link' => 'Latest result',
    'history_link' => 'Historical results',
    'buy_title' => 'Buy / Ticket selection',
    'purchase_status_heading' => 'Purchase availability',
    'purchase_not_configured' => 'NOT_CONFIGURED',
    'purchase_not_configured_explainer' => 'Mega ticket selection and purchase are unavailable because no verified product-specific purchase contract is configured. No price, ticket, balance, reservation or purchase action is shown.',
    'back_to_results' => 'Back to Mega Lottery results',

    'current_result_heading' => 'Latest published result',
    'recent_draws_heading' => 'Recent draws',
    'history_heading' => 'Results history',
    'detail_heading' => 'Draw detail',
    'provenance_heading' => 'Where this result came from',
    'integrity_heading' => 'Record integrity',

    // ------------------------------------------------------------ Fields ---
    'field_first_6_mega' => '6 Mega',
    'field_three_mega' => '3 Mega',
    'field_two_mega' => '2 Mega',
    'field_date' => 'Draw date',

    'field_first_6_mega_hint' => 'Six digits, leading zeros included.',
    'field_three_mega_hint' => 'Three digits, leading zeros included.',
    'field_two_mega_hint' => 'Two digits, leading zeros included.',

    // ------------------------------------------------------------- Table ---
    'table_caption' => 'Published Mega Lottery results, newest draw first. A draw with no published numbers is shown with its fields marked as not published.',
    'col_draw_date' => 'Draw date',
    'col_first_6_mega' => '6 Mega',
    'col_three_mega' => '3 Mega',
    'col_two_mega' => '2 Mega',
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
        '6mega' => '6 Mega',
        '3mega' => '3 Mega',
        '2mega' => '2 Mega',
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
    // --------------------------------------------------------- Integrity ---
    //
    // THIS LANE HAS NO INDEPENDENT VERIFIER, so the vocabulary below is
    // deliberately SHORTER than the Weekly lane's. There is no
    // 'signed_verified' and no 'verifier_unavailable' label here, because a
    // label the lane can never legitimately render is a claim waiting to be
    // made by accident - a future refactor that guessed at a status string
    // would find a friendly translation sitting ready for it.
    //
    'integrity' => [
        'status' => 'Integrity check',
        'canonical_version' => 'Canonical format',
        'independently_verified' => 'Independently re-derived',
        'yes' => 'Yes',
        'no' => 'No',
        'explainer' => 'The canonical form of this result is hashed when it is imported, and the hash is stored beside it. This lane has no independent verifier, so the hash shows the stored values still match what was imported; it is not evidence about who authorised the numbers.',
    ],

    'integrity_status' => [
        'integrity_hash_only' => 'Hash recorded (not signed)',
        'signature_unsupported' => 'Signature not supported in this lane',
        'fingerprint_mismatch' => 'Fingerprint did not match',
        'not_verified' => 'Not checked',
        'rejected' => 'Rejected',
    ],

    'integrity_hint' => [
        'integrity_hash_only' => 'The stored values match the hash recorded when they were imported. That hash was computed by this application, so it shows the record is internally consistent and nothing more.',
        'signature_unsupported' => 'Signature material was supplied, but this lane has no verifier able to check it, so the record was refused rather than accepted unchecked.',
        'fingerprint_mismatch' => 'The recorded hash does not match the stored values.',
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

    'empty_current' => 'No Mega Lottery result has been published yet. Nothing is shown here until a result has been imported with a recorded source.',
    'empty_year' => 'No published result exists for this year.',
    'empty_search' => 'No published draw matches that search.',

    // ------------------------------------------------------- Corrections ---
    'correction_notice' => 'This draw has been corrected. You are reading version :version; the earlier version is retained in the record.',
    'conflict_notice' => 'A newer payload for this draw disagrees with the published result. Publication is on hold until the disagreement is resolved, and the previously verified result stays on this page.',
];
