<?php

/*
|--------------------------------------------------------------------------
| Public pages copy — English (/about, /vision, /terms)
|--------------------------------------------------------------------------
| Neutral wording only. Never present the platform as the Government
| Lottery Office, an official agent, or a government portal. Official GLO
| prize facts come from config/glo.php via TermsPageService — not hard-coded
| here as business rules. Keep keys identical to lang/th/public_pages.php.
|
*/

return [

    // ------------------------------------------------------------------ meta
    'about_meta_title' => 'About',
    'about_meta_description' => 'How this GLO-compatible lottery platform works: choose a draw, buy securely, follow verified results, and claim under clear rules.',
    'vision_meta_title' => 'Vision & Mission',
    'vision_meta_description' => 'Our platform vision, mission, core values, and the governance controls we actually implement for lottery results, settlement, and player protection.',
    'terms_meta_title' => 'Terms of Use',
    'terms_meta_description' => 'Versioned terms of use covering products, prizes, stamp duty, accounts, age rules, claims, responsible gaming, and legal disclaimers.',
    'og_type' => 'website',

    // ------------------------------------------------------------------ about
    'about_title' => 'About this platform',
    'about_lead' => 'An independent, GLO-compatible lottery information and wagering platform. We are not the Government Lottery Office, not an official GLO agent, and not a government portal.',

    'how_it_works_title' => 'How it works',
    'how_it_works_choose_label' => 'Choose',
    'how_it_works_choose_text' => 'Browse published draws and results, then choose a product: GLO-style L6 or N3 on this platform, or operator markets such as 3D, TOD, 2D, and Run.',
    'how_it_works_buy_label' => 'Buy',
    'how_it_works_buy_text' => 'Create an account or sign in, fund your wallet through configured payment methods, and place your purchase. Ticket numbers keep leading zeros.',
    'how_it_works_result_label' => 'Result',
    'how_it_works_result_text' => 'Follow published draw results with explicit source-status labels. Fixture or sample data is never presented as an official government announcement.',
    'how_it_works_claim_label' => 'Claim',
    'how_it_works_claim_text' => 'If a ticket wins, claim follows verification, eligibility checks, any applicable holds, KYC gates, stamp duty calculation, and the approved payout path — never an automatic promise of instant payment.',

    'history_title' => 'Our approach',
    'history_text' => 'This platform exists to make lottery results, ticket checking, and account tooling clear and auditable. We publish results only with honest source labels, calculate prizes from configured GLO-compatible rules, and keep an append-only trail for money movements. We do not claim a founding date, government affiliation, or certification that is not published here.',

    'useful_links_title' => 'Useful links',
    'useful_links_text' => 'Public pages on this site — no account required for results, ticket checks, or sales-point search.',

    'contact_title' => 'Contact',
    'contact_text' => 'Questions about the platform, your account, or these pages? Use the contact page when support details are configured.',

    // ------------------------------------------------------------------ vision
    'vision_title' => 'Vision & Mission',
    'vision_lead' => 'A trustworthy, GLO-compatible lottery platform where every result, prize, and payout is verifiable.',

    'vision_section_title' => 'Vision',
    'vision_section_text' => 'To be the most transparent GLO-compatible lottery platform: players can verify every published result, understand every prize calculation, and follow every payout decision with clear source labels and audit evidence.',

    'mission_section_title' => 'Mission',
    'mission_section_text' => 'Operate lottery information and wagering features with verified result publication, configured prize settlement, sales reconciliation, immutable audit trails, KYC-gated withdrawals, and privacy-respecting public pages.',

    'core_values_title' => 'Core values',
    'core_values_transparency_label' => 'Transparency',
    'core_values_transparency_text' => 'Results carry explicit source-status labels; prize rules come from published configuration, not hidden tables.',
    'core_values_security_label' => 'Security',
    'core_values_security_text' => 'Server-side authorization, signed webhooks, and transactional ledger writes guard every financial action.',
    'core_values_accountability_label' => 'Accountability',
    'core_values_accountability_text' => 'Append-only audit records make money movements and privileged actions reviewable after the fact.',
    'core_values_fairness_label' => 'Fairness',
    'core_values_fairness_text' => 'Prize engines follow configured rules; N3-style pools are draw-calculated, never fixed historical payouts.',
    'core_values_privacy_label' => 'Privacy',
    'core_values_privacy_text' => 'Public pages stay anonymous; personal data never appears on shared result or sales-point views.',
    'core_values_reliability_label' => 'Reliability',
    'core_values_reliability_text' => 'Idempotent operations and concurrency locks keep double-pays and race conditions out of the system.',

    'governance_title' => 'Governance & controls',
    'governance_text' => 'Controls we actually implement in this platform:',
    'governance_idempotency' => 'Idempotency keys on wallet and payout operations to prevent duplicate execution.',
    'governance_transactional_ledger' => 'Transactional ledger writes with row locks under database transactions.',
    'governance_immutable_audit' => 'Append-only audit trail for financial and privileged actions.',
    'governance_source_status_labels' => 'Source-status labels on results (verified, reconciled, fixture, not configured, unavailable).',
    'governance_server_authorization' => 'Server-side authorization on every financial action — never trust client-supplied roles or amounts.',
    'governance_kyc_gates' => 'KYC gates on withdrawals above the configured threshold (WithdrawalKycGateService).',
    'governance_payout_holds' => 'Payout holds and freeze history for claim eligibility — frozen winning tickets are never auto-paid.',
    'governance_sales_reconciliation' => 'Sales reconciliation for ticket sales seats and draw settlement.',
    'governance_disclaimer' => 'These are engineering controls, not a promise of absolute safety or invulnerability, and not a guarantee of zero operational risk.',

    // ------------------------------------------------------------------ terms
    'terms_title' => 'Terms of Use',
    'terms_version_label' => 'Version',
    'terms_effective_label' => 'Effective date',
    'terms_updated_label' => 'Last updated',
    'terms_not_configured' => 'NOT_CONFIGURED',

    'terms_intro_title' => '1. Scope and platform identity',
    'terms_intro_text' => 'These Terms govern your use of this independent lottery information and wagering platform. We are not the Government Lottery Office (GLO), not an official agent, and not a government portal. GLO product rules are referenced only for prize-rule compatibility.',

    'terms_products_title' => '2. Products and prize rules',
    'terms_products_l6_text' => 'GLO-compatible L6: ticket price {l6_price} THB; full-sale allocation {l6_allocation} THB across {l6_units} units; {l6_prize_count} prizes in the full-sale schedule; prizes scale proportionally when sales are below full sale.',
    'terms_products_n3_text' => 'GLO-compatible N3: ticket price {n3_price} THB; prize pool is {n3_pool_rate} of N3 gross sales; payouts are variable and draw-calculated — never fixed historical amounts.',
    'terms_products_separation_text' => 'Operator markets (3D, TOD, 2D, Run) are platform products. They are never described as GLO N3 or as government lottery products.',

    'terms_stamp_title' => '3. Prize duty and tax treatment',
    'terms_stamp_text' => 'Stamp duty: {stamp_unit_baht} THB for every {stamp_divisor} THB of gross prize or fraction thereof (ceil(gross/{stamp_divisor}) × {stamp_unit_baht} THB). Prize income under this rule is treated as stamp-duty only; income tax is exempt on the displayed calculation. No 0.5% or 1% withholding model is used on this platform.',

    'terms_accounts_title' => '4. Accounts and verification',
    'terms_accounts_one_text' => 'One account per verified user where enforced: email, username, and phone are unique at registration.',
    'terms_accounts_verification_text' => 'Identity verification requirements may vary by account, product, and channel. We never promise a single fixed verification path for every user.',

    'terms_age_title' => '5. Age rules',
    'terms_age_registration_text' => 'Registration: you confirm you can enter into a binding agreement under applicable law and that the information you provide is accurate. A date of birth may be collected for identity verification and is stored for server-side age checks.',
    'terms_age_purchase_text' => 'Purchase: eligibility to purchase or wager may be checked against product rules, responsible-gaming settings, and account status before an order is accepted.',
    'terms_age_claim_text' => 'Claim: prize claimants must be {claim_min_age} years of age or older, calculated from the verified date of birth on file — never from a client-supplied age.',

    'terms_ticket_title' => '6. Ticket ownership and transfers',
    'terms_ticket_text' => 'Lottery ticket numbers are digit strings with leading zeros preserved. For GLO-style L6 prizes, a claim follows the authorized claimant path for the verified holder; tickets are not freely transferable instruments for claiming purposes on this platform.',

    'terms_responsible_title' => '7. Responsible gaming',
    'terms_responsible_text' => 'Where enabled on your account, you can use self-exclusion, deposit/loss limits, and reality checks from your profile and the responsible-gaming settings. These are player-protection tools, not a statement of regulatory approval.',

    'terms_claim_title' => '8. Claims and payouts',
    'terms_claim_text' => 'There is no promise of immediate payment. Claims are subject to result verification, eligibility review, applicable holds, KYC where required, stamp duty calculation, and the approved payout channel. Claim window is {claim_window_years} years from the draw under configured GLO-compatible rules.',

    'terms_disclaimer_title' => '9. Legal disclaimer',
    'terms_disclaimer_text' => 'This is an independent platform. References to the Government Lottery Office and its prize rules are for compatibility and information only. We do not claim government ownership, operation, endorsement, or agency status. No page on this site should be read as an official GLO website.',

    'terms_prohibited_title' => '10. Prohibited conduct',
    'terms_prohibited_text' => 'Automated abuse of public endpoints, attempted circumvention of rate limits, fraudulent claim or identity information, and attempts to manipulate finalized results may result in account restriction.',

    'terms_contact_title' => '11. Contact and changes',
    'terms_contact_text' => 'Questions about these Terms: use the contact page. Material changes will publish a new version number and updated effective date on this page; historical versions are never silently rewritten in place without a version bump.',

    // ------------------------------------------------------------------ useful link labels
    'useful_links_results' => 'Draw results',
    'useful_links_ticket_check' => 'Check a ticket',
    'useful_links_sales_points' => 'Sales points',
    'useful_links_contact' => 'Contact',
    'useful_links_privacy' => 'Privacy policy',
    'useful_links_terms' => 'Terms of use',

    // ------------------------------------------------------------------ shared
    'not_configured' => 'NOT_CONFIGURED',
    'skip_to_content' => 'Skip to main content',
    'back_to_home' => 'Back to home',

    // ------------------------------------------------- PROMPT 3: member auth
    'login_meta_title' => 'Member Sign In',
    'login_meta_description' => 'Sign in to your member account.',
    'login_heading' => 'Member Sign In',
    'login_lead' => 'Sign in to your member account to continue.',
    'login_identifier' => 'Account ID or Email Address',
    'login_identifier_placeholder' => 'Account ID, username or email address',
    'login_identifier_hint' => 'Use your Account ID, username or the email address on your account.',
    'login_password' => 'Password',
    'login_remember' => 'Remember me',
    'login_submit' => 'Login',
    'login_register_link' => 'Register',
    'login_forgot_link' => 'Forgot Password',
    'captcha_label' => 'CAPTCHA',
    'captcha_placeholder' => 'Answer',
    'captcha_hint' => 'Solve the challenge above to prove you are human.',
    'identifier_invalid' => 'Please provide a valid Account ID, username or email address.',

    'register_meta_title' => 'Create a Member Account',
    'register_meta_description' => 'Register a new member account.',
    'register_heading' => 'Member Registration',
    'register_lead' => 'Create your member account in a few steps.',
    'register_section_account' => 'Accounts Information',
    'register_section_personal' => 'Personal Details',
    'register_section_birth' => 'Birth Information',
    'register_referral' => 'Referral ID',
    'register_mobile' => 'A.C. / Mobile Number',
    'register_mobile_hint' => 'Digits only, 5-15 numbers.',
    'register_password' => 'Password',
    'register_password_confirm' => 'Confirm Password',
    'register_first_name' => 'First Name',
    'register_last_name' => 'Last Name',
    'register_gender' => 'Gender',
    'register_gender_male' => 'Male',
    'register_gender_female' => 'Female',
    'register_gender_unspecified' => 'Unspecified',
    'register_select' => 'Please select',
    'register_city' => 'City',
    'register_country' => 'Country',
    'register_email' => 'Active Email',
    'register_dob' => 'Date of Birth',
    'register_nationality' => 'Nationality',
    'register_terms' => 'I have read and accept the Terms and Conditions.',
    'register_terms_hint' => 'Your acceptance is timestamped and recorded with your account.',
    'register_submit' => 'Register',
    'register_back_to_login' => 'Back to Login',
    'register_welcome' => 'Welcome! Your member account is ready.',
    'register_error_email_taken' => 'This email address is already registered.',
    'register_error_mobile_taken' => 'This mobile number is already registered.',

    'reset_meta_title' => 'Set a New Password',
    'reset_meta_description' => 'Choose a new password for your member account.',
    'forgot_meta_title' => 'Password Recovery',
    'forgot_meta_description' => 'Recover access to your member account.',
    'forgot_heading' => 'Forgot Password',
    'forgot_lead' => 'Enter your account details and we will send recovery instructions.',
    'forgot_identifier' => 'Account No. or Email',
    'forgot_identifier_placeholder' => 'Account number or email address',
    'forgot_identifier_hint' => 'Use your Account No., username or the email address on your account.',
    'forgot_submit' => 'Submit',
    'forgot_back_to_login' => 'Back to Login',
    'forgot_captcha_label' => 'CAPTCHA',
    'reset_requested' => 'If the account exists, password recovery instructions have been sent.',
    'reset_new_password' => 'New Password',
    'reset_confirm_password' => 'Confirm New Password',
    'reset_password_hint' => 'At least 8 characters with letters and numbers.',
    'reset_submit' => 'Reset Password',
    'reset_completed' => 'Your password has been reset. Please sign in with your new password.',
    'reset_invalid_token' => 'This password reset link is invalid or has expired.',
    'reset_mail_subject' => 'Password Recovery',
    'reset_mail_line1' => 'You are receiving this email because a password recovery was requested for your account.',
    'reset_mail_action' => 'Reset Password',
    'reset_mail_line2' => 'This link expires in :minutes minutes.',
    'reset_mail_line3' => 'If you did not request a password recovery, no further action is required.',
    'logged_out' => 'You have been logged out.',

    'password_required' => 'A password is required.',
    'password_min_length' => 'The password must be at least :min characters.',
    'password_invalid' => 'The password is invalid.',
    'password_letters_numbers' => 'The password must contain at least one letter and one number.',
    'password_common' => 'This password is too common. Please choose a stronger one.',
];
