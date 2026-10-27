<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Frontend operations vocabulary (English)
|--------------------------------------------------------------------------
|
| Every string a Batch 02 surface can print. The Thai file carries exactly the
| same key set (enforced by tests/js and tests/Feature/Frontend), so a missing
| translation is a build failure rather than an English sentence in a Thai UI.
|
| Keys are grouped by surface. Nothing here states an amount, a draw number, a
| prize, a reference or an ETA: those are server answers and are rendered from
| the response, never from a translation.
|
*/

return [

    // ── Shared chrome ──────────────────────────────────────────────────────
    'errors' => [
        'generic' => 'Something went wrong. Please try again.',
        'network' => 'The request could not be completed. Check your connection and try again.',
        'not_configured' => 'This feature is not enabled on this site yet.',
        'service_unavailable' => 'The service is temporarily unavailable. Please try again later.',
        'authentication_required' => 'Your session has ended. Please sign in again.',
        'session_expired' => 'Your session expired for security. Please sign in again.',
        'validation' => 'Please correct the highlighted fields.',
        'throttled' => 'Too many attempts. Please wait :seconds seconds and try again.',
        'forbidden' => 'You are not allowed to do that.',
        'not_found' => 'That record could not be found.',
        'conflict' => 'That change conflicts with a newer one. Reload and try again.',
        'server' => 'The server could not complete that request.',
        'reauth_required' => 'Confirm your password to continue.',
        'reauth_invalid' => 'That password is not correct. The change was not applied.',
        'unknown_field' => 'That field could not be accepted.',
    ],

    'toast' => [
        'dismiss' => 'Dismiss notification',
        'region' => 'Notifications',
        'success' => 'Success',
        'error' => 'Error',
        'warning' => 'Warning',
        'info' => 'Notice',
    ],

    'state' => [
        'loading' => 'Loading…',
        'saving' => 'Saving…',
        'saved' => 'Saved.',
        'not_configured' => 'Not configured',
        'unavailable' => 'Unavailable',
        'ready' => 'Available',
        'offline' => 'You appear to be offline. Actions will fail until the connection returns.',
        'online' => 'Connection restored.',
        'live' => 'Live',
    ],

    // ── Player limits and cool-off breaks ─────────────────────────────────
    'limits' => [
        'title' => 'Deposit, bet and wager limits',
        'lead' => 'Limits apply to your account immediately. A lower limit takes effect at once; a higher limit starts after its cooling-off period.',
        'daily_deposit' => 'Daily deposit limit',
        'single_bet' => 'Single bet limit',
        'daily_wagering' => 'Daily wager limit',
        'current' => 'Current limit',
        'none_set' => 'No limit set',
        'currency' => 'THB',
        'amount_label' => 'Limit amount in baht',
        'amount_help' => 'Up to two decimal places. Leave the field empty to keep the current limit.',
        'submit' => 'Save limits',
        'cross_currency' => 'Cross-currency: each limit line shows the currency the server holds it in.',
        'pending_increase' => 'A higher limit is waiting for its cooling-off period to end.',
        'pending_until' => 'Takes effect after :when',
        'effective_now' => 'In effect now.',
        'read_only' => 'Your limits are read-only while a self-exclusion is active.',
        'cool_off_heading' => 'Take a break',
        'cool_off_lead' => 'A break blocks deposits and betting for the period you choose. It cannot be shortened.',
        'cool_off_option' => ':hours hours',
        'cool_off_submit' => 'Start break',
        'cool_off_active' => 'A break is active until :when.',
        'cool_off_none' => 'No break is active.',
        'self_exclusion_heading' => 'Self-exclusion',
        'self_exclusion_lead' => 'Self-exclusion closes your account to deposits and play for the whole period. It cannot be lifted early.',
        'self_exclusion_duration' => 'Exclusion period',
        'self_exclusion_submit' => 'Start self-exclusion',
        'self_exclusion_active' => 'Self-exclusion is active until :when.',
        'confirm_heading' => 'Confirm with your password',
        'confirm_lead' => 'Protection changes need your current password.',
        'confirm_password' => 'Current password',
        'confirm_submit' => 'Confirm and apply',
        'confirm_cancel' => 'Cancel',
        'updated' => 'Your limits were updated.',
        'cool_off_started' => 'Your break started.',
        'self_exclusion_started' => 'Your self-exclusion started.',
        'duration_24h' => '24 hours',
        'duration_7d' => '7 days',
        'duration_30d' => '30 days',
        'duration_90d' => '90 days',
        'duration_365d' => '365 days',
        'hours_6' => '6 hours',
        'hours_12' => '12 hours',
        'hours_24' => '24 hours',
        'hours_48' => '48 hours',
        'hours_72' => '72 hours',
        'hours_168' => '168 hours',
    ],

    // ── GLO results checker and provenance ────────────────────────────────
    'results' => [
        'title' => 'Check a published result',
        'lead' => 'Enter the six digits printed on your ticket. The answer comes from the published draw record.',
        'ticket_label' => 'Ticket number',
        'ticket_help' => 'Six digits, leading zeros included.',
        'checking' => 'Checking…',
        'submit' => 'Check ticket',
        'draw_heading' => 'Published draw',
        'draw_number' => 'Draw number',
        'draw_date' => 'Draw date',
        'no_result' => 'No result has been published for the latest draw yet.',
        'not_published' => 'The latest draw has no published result, so no check can be made.',
        'provenance' => 'Source',
        'provenance_official' => 'Official source',
        'provenance_reconciled' => 'Reconciled internally',
        'provenance_operator' => 'Operator entry (two-person approved)',
        'provenance_unavailable' => 'Source unavailable',
        'published_at' => 'Published',
        'checked' => 'Checked against the published draw.',
        'winning' => 'This ticket matches a published prize tier.',
        'not_winning' => 'This ticket does not match any published prize tier.',
        'tier' => 'Tier',
        'prize_amount' => 'Prize amount',
        'gross' => 'Gross',
        'net' => 'Net of stamp duty',
        'stamp_duty' => 'Stamp duty',
        'claim_by' => 'Claim by',
        'claim_note' => 'Prizes are paid through the published claim process. This page does not pay anything.',
        'invalid_ticket' => 'Enter exactly six digits.',
        'first_prize' => 'First prize',
        'second_prize' => 'Second prize',
        'third_prize' => 'Third prize',
        'tier_fourth' => 'Fourth prize',
        'tier_fifth' => 'Fifth prize',
        'tier_adjacent_first' => 'Adjacent to first prize',
        'tier_front_three' => 'Front three digits',
        'tier_last_three' => 'Last three digits',
        'tier_last_two' => 'Last two digits',
        'tier_none' => 'No prize tier',
    ],

    // ── Ticket status lookup ──────────────────────────────────────────────
    'ticket_status' => [
        'title' => 'Where is my ticket?',
        'lead' => 'Enter the reference from your confirmation message and the address you wrote from.',
        'reference_label' => 'Ticket reference',
        'email_label' => 'Email address',
        'submit' => 'Look up ticket',
        'neutral' => 'If that reference matches the address you entered, its status is shown. No other details are disclosed.',
        'matched_heading' => 'Ticket found',
        'status_label' => 'Status',
        'updated_label' => 'Last updated',
        'delivery_acknowledged' => 'A delivery receipt was recorded for the reply.',
        'delivery_pending' => 'No delivery receipt has been recorded yet.',
        'rate_limited' => 'Too many lookups. Please try again later.',
        'disabled' => 'The ticket lookup is not enabled on this site yet.',
        'status_received' => 'Received',
        'status_in_review' => 'In review',
        'status_resolved' => 'Resolved',
        'status_closed' => 'Closed',
    ],

    // ── Download page ─────────────────────────────────────────────────────
    'download' => [
        'builds_heading' => 'Available builds',
        'no_builds' => 'No verified build is published yet, so no download is offered.',
        'version' => 'Version',
        'published_at' => 'Published',
        'size' => 'Size',
        'checksum_label' => 'SHA-256 checksum',
        'checksum_submit' => 'Verify checksum',
        'checksum_match' => 'The checksum matches the published build.',
        'checksum_mismatch' => 'The checksum does NOT match the published build. Do not install this file.',
        'checksum_invalid' => 'Enter the 64-character SHA-256 hex digest.',
        'download_started' => 'Preparing your download…',
        'track_failed' => 'The download could not be recorded. The file link still works.',
        'not_configured' => 'No verified build is published, so there is nothing to download.',
        'verified_build' => 'Verified build',
        'signature' => 'Signing certificate fingerprint',
    ],

    // ── Payout illustration ───────────────────────────────────────────────
    'illustration' => [
        'label' => 'Illustration only',
        'disclaimer' => 'Illustration only. It is not a prediction, not a probability and not a promise of winnings.',
        'stake_label' => 'Stake',
        'multiplier_label' => 'Published payout rate',
        'gross_label' => 'Return if matched (gross)',
        'net_label' => 'Return if matched (net)',
        'submit' => 'Illustrate return',
        'bet_type_label' => 'Bet type',
        'no_rate' => 'No single published payout rate exists for that bet type, so no illustration is shown.',
        'result_heading' => 'Illustrated return',
    ],

    // ── Fees schedule ─────────────────────────────────────────────────────
    'fees' => [
        'download_pdf' => 'Download the fee schedule (PDF)',
        'pdf_not_configured' => 'The PDF export is not enabled on this site yet.',
        'pdf_failed' => 'The fee schedule could not be generated. Please try again later.',
        'pdf_ready' => 'Your fee schedule is ready.',
        'effective_from' => 'Effective from',
        'category' => 'Category',
        'provider' => 'Provider',
        'amount' => 'Amount',
        'basis' => 'Basis',
        'source' => 'Source',
        'illustrative' => 'Illustrative schedule — figures are the platform’s published fees.',
    ],

    // ── Contact page ──────────────────────────────────────────────────────
    'contact' => [
        'sent' => 'Your message was received.',
        'failed' => 'Your message could not be sent. Please review the fields and try again.',
        'reference' => 'Your reference',
        'not_configured' => 'Contact delivery is not configured on this site yet, so nothing was sent.',
        'spam_blocked' => 'The submission was refused by the anti-spam check.',
    ],

    // ── Account verification ──────────────────────────────────────────────
    'verification' => [
        'provider_not_configured' => 'No identity provider is configured, so submissions are reviewed manually.',
        'submitted' => 'Your documents were submitted for review.',
        'failed' => 'Your documents could not be submitted.',
        'manual_review_notice' => 'Documents are reviewed by a person. No automated provider decision is made.',
    ],

    // ── Real-time ─────────────────────────────────────────────────────────
    'realtime' => [
        'connecting' => 'Connecting to live updates…',
        'connected' => 'Live updates connected.',
        'disconnected' => 'Live updates disconnected. Reconnecting…',
        'unavailable' => 'Live updates are not configured on this site.',
        'reconnecting' => 'Reconnecting in :seconds seconds…',
    ],

    // ── Notification preferences matrix (player security page) ─────────────
    'notification_preferences' => [
        'title' => 'Notification preferences',
        'lead' => 'Choose which channels deliver each type of account notice.',
        'event_column' => 'Notice',
        'on' => 'On',
        'off' => 'Off',
        'mandatory_help' => 'Security, compliance and withdrawal-result notices are always delivered and cannot be switched off.',
        'always_delivered' => 'Always delivered',
        'loading' => 'Loading notification preferences…',
        'saving' => 'Saving notification preferences…',
        'saved' => 'Notification preferences saved.',
        'failed' => 'Notification preferences could not be saved. Your previous settings were restored.',
        'rollback' => 'Your previous settings were restored.',
        'not_configured' => 'Notification preferences are not available yet.',
        'sign_in_required' => 'Sign in to manage notification preferences.',
        'network_error' => 'The request could not be completed. Check your connection and try again.',
        'invalid_preference' => 'That preference is not published on this platform.',
        'channel_in_app' => 'In-app',
        'channel_sms' => 'SMS',
        'channel_email' => 'Email',
        'channel_push' => 'Push',
        'empty' => 'No notification type is published right now.',
        'event_kyc_verified' => 'Identity verification approved',
        'event_kyc_rejected' => 'Identity verification rejected',
        'event_deposit_confirmed' => 'Deposit confirmed',
        'event_deposit_failed' => 'Deposit failed',
        'event_withdrawal_approved' => 'Withdrawal approved',
        'event_withdrawal_paid' => 'Withdrawal paid',
        'event_withdrawal_rejected' => 'Withdrawal rejected',
        'event_bet_placed' => 'Bet placed',
        'event_bet_won' => 'Bet won',
        'event_prize_claim_update' => 'Prize claim update',
        'event_compliance_hold' => 'Compliance hold',
        'event_compliance_released' => 'Compliance hold released',
        'event_self_exclusion_started' => 'Self-exclusion started',
        'event_limit_changed' => 'Limit changed',
        'event_session_revoked' => 'Session revoked',
        'event_reality_check' => 'Reality check',
        'event_manual' => 'Account notice',
        'event_glo_result_available' => 'GLO result available',
        'event_dealer_request_update' => 'Dealer request update',
    ],

    // ── Status/limit vocabulary used by portal scripts ────────────────────
    'field' => [
        'required' => 'This field is required.',
        'invalid_amount' => 'Enter an amount in baht with at most two decimal places.',
        'too_large' => 'That amount is above the maximum this field allows.',
        'too_small' => 'That amount is below the minimum this field allows.',
    ],
];
