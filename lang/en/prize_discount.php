<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Prize Verification + Lotto Discount (PROMPT 4)
|--------------------------------------------------------------------------
|
| Key parity with lang/th/prize_discount.php is enforced by
| tests/Feature/PublicServices/PrizeVerificationAndDiscountTest.php: both
| files must expose exactly the same key set, so a translator can never
| silently drop a legally meaningful sentence.
|
| WORDING RULES BAKED INTO THESE STRINGS
|  - Never "official GLO verification". This platform is GLO-COMPATIBLE and
|    says so; it has no authorised connection to the Government Lottery
|    Office, and claiming one would be a false statement to the public.
|  - A database record can prove a DIGITAL RECORD, never the authenticity of
|    a piece of paper. Hence DIGITAL_RECORD_VERIFIED / PUBLIC_RECORD_FOUND
|    and an explicit statement that physical inspection notes are
|    informational only.
|  - No fee, payout, discount percentage, or sentence here is copied from any
|    other operator's site. Every number the page shows comes from this
|    application's own config.
|
*/

return [

    // -- Page meta ---------------------------------------------------------
    'verify_meta_title' => 'Prize verification — check a lottery ticket',
    'verify_meta_description' => 'Check a six-digit number, a ticket reference, or a scanned barcode against published draw results. GLO-compatible verification; not an official Government Lottery Office service.',
    'discount_meta_title' => 'Lotto discounts and payout rates',
    'discount_meta_description' => 'Published discount rules and direct/reverse payout rates for operator markets. Government Lottery Office ticket prices are fixed by law and never discounted.',

    // -- Verification page: form -------------------------------------------
    'verify_heading' => 'Prize verification',
    'verify_intro' => 'Check whether a ticket appears in a published draw result. Results are read from the published result projection only.',
    'verify_mode_label' => 'What are you checking?',
    'verify_value_label' => 'Value to check',
    'verify_draw_label' => 'Draw reference (optional)',
    'verify_draw_hint' => 'Leave empty to check against the most recently published draw. A draw reference is required for 3-digit (N3) checks.',
    'verify_submit' => 'Check ticket',
    'verify_reset' => 'Clear',
    'verify_rate_note' => 'This checker is rate limited to protect ticket holders from bulk lookups.',

    'mode_number' => 'Six-digit number',
    'mode_reference' => 'Ticket reference',
    'mode_barcode' => 'Scanned barcode',
    'mode_number_hint' => 'Exactly six digits. Leading zeros matter and are preserved.',
    'mode_reference_hint' => 'The reference printed on a ticket issued by this platform.',
    'mode_barcode_hint' => 'Paste the decoded payload from a barcode or Data Matrix scan.',

    // -- Verification page: results ----------------------------------------
    'result_heading' => 'Verification result',
    'result_none' => 'Submit a value above to see a result.',
    'result_checked_at' => 'Checked at',
    'result_reference' => 'Verification reference',
    'result_policy_version' => 'Policy version',
    'result_query_echo' => 'Value checked',
    'result_product' => 'Product',
    'result_draw' => 'Draw',
    'result_draw_date' => 'Draw date',
    'result_prize_category' => 'Prize category',
    'result_prize_amount' => 'Prize amount',
    'result_matches' => 'Matched tiers',
    'result_no_matches' => 'No matched tiers.',

    'status_not_found' => 'Not found',
    'status_not_found_detail' => 'No published record matches that value. This does not by itself mean a ticket is invalid — it may belong to a draw that has not been published here.',
    'status_found_not_winning' => 'Found — no prize',
    'status_found_not_winning_detail' => 'A record matching that value exists in the published result, and it does not match any prize tier for that draw.',
    'status_winning' => 'Winning',
    'status_winning_detail' => 'That value matches a prize tier in the published result. Claiming a prize still requires identity checks and the physical ticket where applicable.',
    'status_payment_hold' => 'Payment on hold',
    'status_payment_hold_detail' => 'A prize is recorded for this ticket and payment is currently held pending review. Contact support with your claim reference; the reason for a hold is never published.',
    'status_paid' => 'Already paid',
    'status_paid_detail' => 'A prize for this ticket has already been recorded as paid.',
    'status_invalid' => 'Invalid input',
    'status_invalid_detail' => 'That value does not have a shape this checker can read. Nothing was looked up.',
    'status_unavailable' => 'Unavailable',
    'status_unavailable_detail' => 'This check cannot be completed right now. No conclusion about the ticket should be drawn from this answer.',

    'reason_heading' => 'Reason',
    'reason_draw_reference_required' => 'A draw reference is required to check a 3-digit (N3) number, because N3 prizes are scoped to a single draw.',
    'reason_not_six_digits' => 'A six-digit check needs exactly six digits.',
    'reason_invalid_payload' => 'The scanned payload could not be read.',
    'reason_unsupported_format' => 'That barcode format is not configured on this platform.',
    'reason_verification_disabled' => 'Public verification is currently switched off.',
    'reason_no_published_draw' => 'There is no published draw to check against yet.',

    // -- Authenticity ------------------------------------------------------
    'authenticity_heading' => 'Record check',
    'authenticity_digital_record_verified' => 'Digital record verified',
    'authenticity_digital_record_verified_detail' => 'A ticket record issued by this platform matches. This verifies the digital record only.',
    'authenticity_public_record_found' => 'Public record found',
    'authenticity_public_record_found_detail' => 'The value appears in the published public result. This verifies the published record only.',
    'authenticity_not_verified' => 'No record matched',
    'authenticity_not_verified_detail' => 'Nothing in the published records matches that value.',
    'authenticity_revoked' => 'Record revoked',
    'authenticity_revoked_detail' => 'The matching record has been revoked and is not valid for a claim.',
    'authenticity_paper_disclaimer' => 'This service cannot certify a physical ticket. It reports what is recorded digitally.',

    'barcode_heading' => 'Barcode support',
    'barcode_supported' => 'Supported',
    'barcode_not_configured' => 'Not configured',
    'barcode_unsupported_format' => 'Unsupported format',
    'barcode_invalid' => 'Invalid',
    'barcode_provider_glo_data_matrix' => 'GLO Data Matrix reader',
    'barcode_provider_operator_qr' => 'Operator ticket code',
    'barcode_fixture_notice' => 'A decoded value labelled as a test fixture is never treated as an official ticket.',
    'barcode_fixture_schema' => 'Test fixture schema',

    'physical_note_heading' => 'Checking a printed ticket yourself',
    'physical_note_intro' => 'The following notes are informational only. They are general handling advice and are not a certification of any individual ticket.',
    'physical_note_markings' => 'Compare the printed number, draw date and set/series markings against the published result for that draw.',
    'physical_note_barcode' => 'If the ticket carries a machine-readable code, a successful scan tells you the code could be decoded — it does not by itself prove the paper is genuine.',
    'physical_note_limits' => 'Only the issuing authority can rule on a disputed physical ticket. Keep the ticket undamaged and present it through the official claim process.',

    'not_official_notice' => 'GLO-compatible checking. This platform is not the Government Lottery Office, this page is not a government service, and nothing here is an authorised statement by that office.',
    'privacy_notice' => 'No ticket holder name, contact detail, payment detail, or account information is ever shown on this page.',

    // -- Discount page -----------------------------------------------------
    'discount_heading' => 'Lotto discounts',
    'discount_intro' => 'Published discount rules, applied by the server at purchase time. A price shown in your browser is never the price that is charged.',
    'discount_catalogue_version' => 'Catalogue version',
    'discount_generated_at' => 'Generated at',
    'discount_currency' => 'Currency',
    'discount_filter_label' => 'Filter by product',
    'discount_filter_all' => 'All products',
    'discount_no_rules' => 'No discount rule is published for this product right now.',
    'discount_none_published' => 'No discount rules are published at the moment.',

    'discount_table_rule' => 'Rule',
    'discount_table_market' => 'Market',
    'discount_table_type' => 'Type',
    'discount_table_value' => 'Value',
    'discount_table_minimum' => 'Minimum spend',
    'discount_table_maximum' => 'Maximum discount',
    'discount_table_period' => 'Effective period',
    'discount_table_stackable' => 'Combines with others',
    'discount_table_version' => 'Version',

    'discount_type_percentage' => 'Percentage',
    'discount_type_fixed' => 'Fixed amount',
    'discount_market_any' => 'All markets',
    'discount_stackable_yes' => 'Yes',
    'discount_stackable_no' => 'No — applied alone',
    'discount_period_open' => 'Until further notice',
    'discount_period_from' => 'From :from',
    'discount_period_between' => ':from to :to',
    'discount_no_limit' => 'No limit',

    'immutable_heading' => 'Fixed-price products',
    'immutable_notice' => 'Government Lottery Office ticket prices are fixed by law. No discount rule, account grade, promotion, or affiliate arrangement can change them.',
    'immutable_badge' => 'Fixed price',
    'immutable_price' => 'Price',

    'layer_heading' => 'How your final price is calculated',
    'layer_account_grade' => 'Verified accounts may receive an additional grade-based discount. Your grade rate is shown on your own account pages and is never published here.',
    'layer_server_computed' => 'Every discount is calculated on the server from these published rules. Values sent by a browser are ignored.',
    'layer_order' => 'Rules are applied in a fixed order, each rule at most once, and the total discount is capped.',

    'product_glo_l6' => 'Government six-digit ticket (L6)',
    'product_glo_n3' => 'Government three-digit ticket (N3)',
    'product_operator_3d' => 'Three-digit market',
    'product_operator_2d' => 'Two-digit market',
    'product_operator_run' => 'Running-number market',

    'rule_operator_3d_launch' => 'Introductory discount on three-digit entries.',
    'rule_operator_2d_launch' => 'Introductory discount on two-digit entries.',
    'rule_operator_run_bottom_intro' => 'Introductory discount on running-number bottom entries.',
    'rule_operator_3d_exclusive_event' => 'Event discount on three-digit entries; not combined with other rules.',
    'rule_operator_2d_opening_week' => 'Opening-week discount on two-digit entries.',

    // -- Payout presentation ----------------------------------------------
    'payout_heading' => 'Direct and reverse payout rates',
    'payout_note' => 'These rates are read from the same configuration the purchase and settlement engine uses, so what is displayed is what is paid.',
    'payout_direct' => 'Direct (exact order)',
    'payout_reverse' => 'Reverse (any order)',
    'payout_market' => 'Market',
    'payout_multiplier' => 'Pays',
    'payout_multiplier_unit' => ':value× stake',
    'payout_digits' => 'Digits',
    'payout_permutation' => 'Permutations',
    'payout_permutation_yes' => 'Allowed',
    'payout_permutation_no' => 'Not allowed',
    'payout_unavailable' => 'Not offered for this product.',
    'payout_none_published' => 'Payout rates are not published at the moment.',

    // -- Affiliate presentation -------------------------------------------
    'affiliate_heading' => 'Affiliate commission bands',
    'affiliate_note' => 'Published headline bands for prospective affiliates. Individual earnings, individual agent rates, and internal settlement figures are never published.',
    'affiliate_band' => 'Band',
    'affiliate_rate' => 'Commission',
    'affiliate_eligibility' => 'Eligibility',
    'affiliate_not_published' => 'Affiliate commission bands are not published at the moment.',
    'affiliate_band_standard' => 'Standard',
    'affiliate_band_partner' => 'Partner',
    'affiliate_band_internal' => 'Internal pilot',
    'affiliate_eligibility_standard' => 'Open to any verified account approved as an affiliate.',
    'affiliate_eligibility_partner' => 'Approved partners meeting the published volume and compliance conditions.',
    'affiliate_eligibility_internal' => 'Internal use only.',

    // -- Shared ------------------------------------------------------------
    'not_configured' => 'Not configured',
    'value_unavailable' => 'Not available',
    'percent_suffix' => '%',
];
