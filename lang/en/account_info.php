<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Public account-programme explainers
|--------------------------------------------------------------------------
|
| Wording for the two signed-out pages: the grade ladder and the verification
| guide. Neither page shows anybody's data, and no string here names a
| document type by example, an identifier format or a sample image.
|
| NO FIGURE LIVES IN THIS FILE. Every threshold and rate on the ladder page
| comes from config/account_grades.php, which is the same source the live
| calculation uses. A number written here would be a second, drifting copy.
|
*/

return [
    'grades_meta_title' => 'Account grades and discounts',
    'grades_meta_description' => 'The account grade ladder: the 30-day spend each grade requires and the discount it carries on operator markets.',
    'grades_title' => 'Account grades',
    'grades_lead' => 'Grades are worked out from your qualifying spend over a rolling 30-day window. The highest grade you qualify for applies.',
    'grades_unavailable' => 'The grade programme is not configured yet.',

    'ladder_heading' => 'Grade ladder',
    'ladder_caption' => 'Each grade, the 30-day qualifying spend it needs, and the discount it carries.',
    'col_grade' => 'Grade',
    'col_min_spend' => '30-day minimum spend',
    'col_discount' => 'Discount',
    'col_scope' => 'Applies to',

    'scope_operator_markets' => 'Operator markets',
    'scope_all' => 'All products',

    'col_sl' => 'SL',
    'col_discount_of_game' => 'Discount Of Game',
    'sl_label' => 'Programme level :sl',
    'min_spend_a11y' => 'minimum qualifying spend over :days days',
    'discount_of_game' => 'Discount Of Game',
    'discount_of_game_title' => 'Games discounted for :grade',
    'discount_of_game_lead' => 'The :percent grade discount applies to these games.',
    'discount_of_game_empty' => 'No games are discounted at this grade.',
    'discount_of_game_close' => 'Close',

    // Stated on the page where the discount is advertised, so nobody infers a
    // reduction on a government-priced ticket.
    'discount_scope_note' => 'Grade discounts apply to operator markets only. GLO ticket prices are fixed and are never discounted.',
    'max_discount_note' => 'The combined discount is capped at :max.',
    'rule_version_note' => 'Grade rule version :version.',

    'verification_meta_title' => 'How account verification works',
    'verification_meta_description' => 'What account verification asks for, what the review checks and what happens afterwards.',
    'verification_title' => 'Account verification',
    'verification_lead' => 'Verification confirms that an account belongs to the person using it. Here is what the process involves before you start.',

    'steps_heading' => 'What happens',
    'step_register_title' => '1. Create and sign in to an account',
    'step_register_text' => 'Verification is attached to an account, so it starts from a signed-in session.',
    'step_submit_request_title' => '2. Send a verification request',
    'step_submit_request_text' => 'Enter your account details and submit the request from your account area.',
    'step_provide_documents_title' => '3. Provide the requested information',
    'step_provide_documents_text' => 'The request tells you which categories of information the review needs. Only what is asked for is required.',
    'step_review_title' => '4. Review',
    'step_review_text' => 'A reviewer checks the submission. This is a manual step, so it is not instant, and no timescale is promised here.',
    'step_outcome_title' => '5. Outcome',
    'step_outcome_text' => 'The result is recorded on your account. If something is missing you are told what, and you can submit again.',

    'verification_privacy_note' => 'Submitted information is used to review the account and nothing else. It is never shown on a public page.',
    'verification_cta' => 'Go to account verification',
    'verification_public_note' => 'This public guide never displays a user’s status, identity documents, address, or personal grade. Those values remain inside the authenticated account workflow.',
    'verification_guide_status' => 'Guide status',
    'verification_document_types' => 'Document types',
    'verification_max_upload' => 'Maximum upload',
    'verification_access' => 'Access',
    'verification_authenticated' => 'Authenticated',
    'verification_guide_map' => 'Guide map',
    'verification_documents' => 'Documents',
    'verification_workflow' => 'Authenticated workflow',
    'verification_private_policy' => 'Private document policy',
    'verification_configured_documents' => 'Configured document types',
    'verification_only_authenticated' => 'Only the authenticated account verification route accepts document uploads.',
    'verification_no_public_identity' => 'Never upload identity documents through Contact Us, public APIs, or an unverified third-party link.',
    'verification_private_workflow' => 'Private workflow',
    'verification_continue_title' => 'Continue in your account',
    'verification_continue_text' => 'Sign in to view or submit your own verification state. A public page cannot reveal it.',
    'verification_sign_in' => 'Sign in',
    'verification_open' => 'Open verification',
    'verification_contact' => 'Contact support',
    'verification_guide_unavailable' => 'Verification guide unavailable',
    'verification_no_steps' => 'No approved guide steps are configured.',
    'verification_eyebrow' => '07 · ACCOUNT VERIFICATION',
    'verification_brand_subtitle' => 'ACCOUNT SECURITY',
    'verification_nav_home' => 'HOME',
    'verification_nav_about' => 'ABOUT US',
    'verification_nav_verification' => 'VERIFICATION',
    'verification_nav_grades' => 'GRADES',
    'verification_nav_contact' => 'CONTACT',
    'verification_login' => 'LOGIN',
    'verification_my_verification' => 'MY VERIFICATION',
    'verification_contents' => 'Verification contents',
    'verification_home_aria' => 'Home',
    'verification_primary_nav' => 'Primary navigation',
    'verification_document_marker' => 'ID',
    // ------------------------------------------------------------------ Page 83 interface labels
    'grade_home_aria' => 'Home',
    'grade_brand_subtitle' => 'Account grade ladder',
    'grade_primary_nav' => 'Primary navigation',
    'grade_nav_home' => 'Home',
    'grade_nav_about' => 'About us',
    'grade_nav_verification' => 'Verification',
    'grade_nav_grades' => 'Grades',
    'grade_nav_contact' => 'Contact',
    'grade_login' => 'Login',
    'grade_my_grade' => 'My grade',
    'grade_eyebrow' => '08 · Member programme',
    'grade_public_note' => 'This catalogue explains the rules only. It does not reveal a user’s spend, current grade, history, eligibility, or personal discount.',
    'grade_metadata_aria' => 'Grade programme metadata',
    'grade_rule_version' => 'Rule version',
    'grade_review_period' => 'Review period',
    'grade_currency' => 'Currency',
    'grade_public_tiers' => 'Public tiers',
    'grade_days' => ':days days',
    'grade_ladder_summary' => 'Public ladder summary',
    'grade_ladder_map' => 'Ladder map',
    'grade_tier_fallback' => 'Tier',
    'grade_configured_tier' => 'Configured tier',
    'grade_minimum_spend' => 'Minimum spend',
    'grade_published_discount' => 'Published discount',
    'grade_scope' => 'Scope',
    'grade_eligible_games' => 'Eligible configured games: :count.',
    'grade_game_matrix_eyebrow' => 'Discount of game',
    'grade_game_matrix_title' => 'Discount of game',
    'grade_private_eyebrow' => 'Account-only information',
    'grade_private_title' => 'Your grade is private',
    'grade_private_body' => 'Personal spend totals, current grade, history, and any individual entitlement are available only after authentication through the Account Grade workflow.',
    'grade_private_note' => 'The public ladder is not a personal eligibility decision.',
    'grade_private_account' => 'Private account state',
    'grade_private_marker' => 'Me',
    'grade_unavailable_title' => 'Grade ladder unavailable',
    'grade_unavailable_body' => 'No public grade tiers are currently configured.',
    'grade_check_account' => 'Check your account',
    'grade_open_authenticated' => 'Open the authenticated view',
    'grade_authenticated_body' => 'Sign in to see your own account information. Never submit credentials to a public checker.',
    'grade_sign_in' => 'Sign in',
    'grade_open_my_grade' => 'Open my grade',
    'grade_contact_support' => 'Contact support',

];
