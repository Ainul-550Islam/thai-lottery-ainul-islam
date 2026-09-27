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
];
