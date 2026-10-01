<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PCSO Lottery result surface — English
|--------------------------------------------------------------------------
|
| This file contains labels only. Numbers, dates, source states and purchase
| capability come from the PCSO services and their projections.
|--------------------------------------------------------------------------
*/

return [
    'meta_title' => 'PCSO Lottery Results',
    'meta_title_search' => 'PCSO Lottery Results — Search',
    'meta_description' => 'Published PCSO Lottery results with 6D, 4D, 3D and 2D categories, draw times, source state and record version.',
    'meta_description_year' => 'PCSO Lottery results for :year, with draw time, source state and record version for each published draw.',

    'public_name' => 'PCSO Lottery',
    'heading' => 'PCSO Lottery Results',
    'intro' => 'Every result is shown with its published categories, draw time, source state and record version.',
    'hero_copy' => 'Browse the PCSO result record without replacing an absent category with a number. Each draw keeps its own date and local draw time.',
    'not_official_notice' => 'This page presents results recorded by this platform with their source attached. It is not an official government publication, and each result is only as authoritative as its source state.',
    'skip_to_content' => 'Skip to content',
    'latest_link' => 'Latest result',
    'history_link' => 'Historical results',
    'buy_title' => 'Buy / Ticket selection',
    'back_to_results' => 'Back to PCSO Lottery results',

    'current_result_heading' => 'Latest published result',
    'recent_draws_heading' => 'Recent draws',
    'history_heading' => 'Results history',
    'detail_heading' => 'Draw detail',
    'provenance_heading' => 'Where this result came from',
    'integrity_heading' => 'Record integrity',
    'year_archive_heading' => 'PCSO Lottery results for :year',
    'history_year_label' => 'Showing the available result records for :year.',
    'year_archive_link' => 'Open year archive',

    'field_six_digit' => '6D',
    'field_four_digit' => '4D',
    'field_three_digit' => '3D',
    'field_two_digit' => '2D',
    'field_date' => 'Draw date',
    'field_six_digit_hint' => 'Six digits, with leading zeros preserved.',
    'field_four_digit_hint' => 'Four digits, with leading zeros preserved.',
    'field_three_digit_hint' => 'Three digits, with leading zeros preserved.',
    'field_two_digit_hint' => 'Two digits, with leading zeros preserved.',

    'table_caption' => 'Published PCSO Lottery results, newest draw first. A category marked Off did not publish a value for that draw.',
    'col_draw_date' => 'Draw date',
    'col_draw_time' => 'Draw time',
    'col_six_digit' => '6D',
    'col_four_digit' => '4D',
    'col_three_digit' => '3D',
    'col_two_digit' => '2D',
    'col_source' => 'Source',
    'col_detail' => 'Detail',
    'view_detail' => 'View draw',

    'no_value' => 'Not published',
    'value_off' => 'Off',
    'result_unavailable_badge' => 'No result published',
    'result_unavailable_explainer' => 'This draw is on record and no numbers were published. Nothing is substituted for the missing values.',

    'date_buddhist_label' => 'Thai Buddhist year',
    'date_gregorian_label' => 'Gregorian date',
    'date_timezone_label' => 'Draw timezone',
    'published_at_label' => 'Published',

    'year_nav_heading' => 'Browse by year',
    'year_nav_empty' => 'No year has a published result yet.',
    'year_draw_count' => ':count draws',

    'search_heading' => 'Search results',
    'search_intro' => 'Choose a category or draw date, then enter the exact value. Leading zeros are preserved.',
    'search_type_label' => 'Search by',
    'search_term_label' => 'Value',
    'search_term_placeholder' => 'Exact value',
    'search_submit' => 'Search',
    'search_reset' => 'Clear',
    'search_results_heading' => 'Search results',
    'search_matched_in' => 'Matched in',
    'search_hint' => 'Matching is exact: 09 finds 09 and nothing else.',
    'search_type_missing' => 'Choose what you are searching for before searching.',
    'search_type' => [
        '6d' => '6D',
        '4d' => '4D',
        '3d' => '3D',
        '2d' => '2D',
        'date' => 'Draw date',
    ],

    'pagination_previous' => 'Previous',
    'pagination_next' => 'Next',
    'pagination_status' => 'Page :page of :last',
    'pagination_total' => ':total draws',

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
        'explainer' => 'The fingerprints are one-way hashes of the delivered payload and its canonical values. They help match a record without republishing the source payload.',
    ],

    'integrity' => [
        'status' => 'Integrity check',
        'canonical_version' => 'Canonical format',
        'independently_verified' => 'Independently re-derived',
        'yes' => 'Yes',
        'no' => 'No',
        'explainer' => 'This lane records a PHP-computed hash. It shows internal consistency and is not a claim of an independent signature or government authorisation.',
    ],
    'integrity_status' => [
        'integrity_hash_only' => 'Hash recorded (not signed)',
        'signature_unsupported' => 'Signature not supported in this lane',
        'fingerprint_mismatch' => 'Fingerprint did not match',
        'not_verified' => 'Not checked',
        'rejected' => 'Rejected',
    ],
    'integrity_hint' => [
        'integrity_hash_only' => 'The stored values match the hash recorded at import. This application computed the hash, so it says nothing about external authorisation.',
        'signature_unsupported' => 'Signature material was supplied, but this lane has no verifier able to check it.',
        'fingerprint_mismatch' => 'The recorded hash does not match the stored values.',
        'not_verified' => 'No integrity check is recorded for this version.',
        'rejected' => 'This record was refused by the integrity check.',
    ],

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

    'status' => [
        'result_found' => 'Result found.',
        'result_unavailable' => 'This draw is on record and no numbers were published for it.',
        'result_not_found' => 'No published result matches that request.',
        'no_public_data' => 'There is no published result to show yet.',
        'invalid_query' => 'That request could not be read. Check the value and try again.',
        'unavailable' => 'Results are temporarily unavailable.',
    ],
    'empty_current' => 'No PCSO Lottery result has been published yet.',
    'empty_year' => 'No published result exists for this year.',
    'empty_search' => 'No published draw matches that search.',
    'correction_notice' => 'This draw has been corrected. You are reading version :version; the earlier version remains in the record.',
    'conflict_notice' => 'A conflicting payload is on hold until it is resolved. The previously published record remains visible.',

    'purchase_intro' => 'The result lane is available. Purchase is disabled until a complete PCSO purchase contract is verified.',
    'purchase_status_heading' => 'Purchase availability',
    'purchase_not_configured_explainer' => 'PCSO ticket selection and purchase are disabled because the product, draw, validation, price, responsible-gaming, wallet, reservation, issuance, ledger and idempotency path is not verified end to end.',
    'purchase_contract_heading' => 'Required purchase contract',
    'purchase_contract_intro' => 'The page does not collect a number, display a price or submit a purchase while any required backend step is unverified.',
    'purchase_no_action_notice' => 'No ticket, inventory, price, wallet debit or reservation has been created by this page.',
];
