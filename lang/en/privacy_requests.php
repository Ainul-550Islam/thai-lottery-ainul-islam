<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Data-subject (privacy) request lane — English
|--------------------------------------------------------------------------
|
| EVERY sentence a requester, an operator or a mail recipient reads lives
| here. Nothing in this lane writes user-facing prose in PHP: the intake
| response, the verification page, the four mails, the player area and the
| operator panel all resolve keys from this file, so a new locale is a
| translation and not a code change.
|
| NO PROMISE OF AN OUTCOME. The wording says a request will be REVIEWED and
| answered within the stated window. It never says the data will be deleted,
| because an erasure request can lawfully be refused where a retention duty
| applies - and the platform must not promise what a reviewer may not deliver.
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | Request types
    |----------------------------------------------------------------------
    */
    'types' => [
        'access' => 'Access to my personal data',
        'portability' => 'A portable copy of my data',
        'rectification' => 'Correction of my personal data',
        'erasure' => 'Deletion of my personal data',
        'restriction' => 'Restriction of processing',
        'objection' => 'Objection to processing',
        'withdraw_consent' => 'Withdrawal of consent',
    ],

    /*
    |----------------------------------------------------------------------
    | Request states
    |----------------------------------------------------------------------
    */
    'statuses' => [
        'pending_verification' => 'Awaiting email verification',
        'received' => 'Received',
        'in_review' => 'In review',
        'needs_information' => 'More information needed',
        'in_progress' => 'In progress',
        'completed' => 'Completed',
        'rejected' => 'Refused',
        'expired' => 'Expired',
        'cancelled' => 'Cancelled',
    ],

    /*
    |----------------------------------------------------------------------
    | Timeline events
    |----------------------------------------------------------------------
    */
    'events' => [
        'submitted' => 'Request submitted',
        'verified' => 'Requester verified',
        'status_change' => 'Status changed',
        'note' => 'Internal note',
        'export_built' => 'Personal-data export built',
        'export_released' => 'Export released to the requester',
        'export_failed' => 'Export build failed',
        'sla_reminder' => 'Due-date reminder sent',
        'overdue' => 'Marked overdue',
        'retention_check' => 'Retention check run',
    ],

    /*
    |----------------------------------------------------------------------
    | Public intake
    |----------------------------------------------------------------------
    |
    | ONE sentence for every outcome. Whether the address belongs to an
    | account, whether an open request already exists, whether the honeypot
    | was tripped - all of them read exactly like this, because the response
    | must not be an oracle for "does this person have an account here".
    |
    */
    'intake' => [
        'accepted' => 'If the details are valid and the address can be verified, we have sent a message with a confirmation link. Nothing else is disclosed here.',
        'invalid' => 'The request could not be accepted. Check the address, the request type and the description, then try again.',
        'attachments_not_accepted' => 'This form does not accept attachments or identity documents. Describe the request in the text field instead; we will ask for anything else we need through a verified channel.',
        'rate_limited' => 'Too many requests from this connection. Please wait and try again later.',
        'disabled' => 'Privacy requests cannot be submitted here in this environment. Use the contact page.',
        'type_unsupported' => 'That request type is not accepted here.',
        'details_too_long' => 'The description is longer than this form accepts.',
        'email_invalid' => 'Enter a valid email address.',
        'honeypot' => 'This field must be left empty.',
    ],

    /*
    |----------------------------------------------------------------------
    | Verification landing page
    |----------------------------------------------------------------------
    */
    'verify' => [
        'title' => 'Privacy request verification',
        'reference_label' => 'Request reference',
        'success_heading' => 'Your request is now verified',
        'success_body' => 'Thank you. The request has been recorded and its due date is now running. We will write to you at the address you used when its state changes.',
        'used_heading' => 'This link has already been used',
        'used_body' => 'A verification link works once. If you need to reach us about this request, quote the reference below through the contact page.',
        'expired_heading' => 'This link has expired',
        'expired_body' => 'The verification window closed before the link was opened, so the request was not accepted. Submit a new request to start again.',
        'invalid_heading' => 'This link cannot be used',
        'invalid_body' => 'The link is incomplete or has been altered. Open the link exactly as it arrived in the email, or submit a new request.',
        'no_store' => 'This page is not cached.',
    ],

    /*
    |----------------------------------------------------------------------
    | Mails
    |----------------------------------------------------------------------
    */
    'mail' => [
        'verify' => [
            'subject' => 'Confirm your privacy request :reference',
            'intro' => 'Someone used this address to submit a privacy request to :app.',
            'type_label' => 'Requested',
            'link_intro' => 'Open the link below to confirm that the request comes from you:',
            'link_expiry' => 'The link can be used once and expires at :expires (UTC).',
            'ignore' => 'If this was not you, ignore this message. Nothing happens until the link is opened, and the request expires on its own.',
            'no_documents' => 'We will never ask you to send identity documents in reply to this email.',
        ],
        'received' => [
            'subject' => 'Your privacy request :reference has been received',
            'intro' => 'We have received your request and the statutory clock has started.',
            'type_label' => 'Requested',
            'received_label' => 'Received',
            'due_label' => 'Answer due by',
            'next' => 'A reviewer will contact you at this address if more information is needed.',
            'no_documents' => 'Please do not send identity documents by email. If verification is required, you will be told through the account area.',
        ],
        'update' => [
            'subject' => 'Update on your privacy request :reference',
            'intro' => 'The state of your privacy request has changed.',
            'status_label' => 'New state',
            'due_label' => 'Answer due by',
            'portal_intro' => 'You can follow the request in your account area:',
            'note_label' => 'Message from the reviewer',
        ],
        'export_ready' => [
            'subject' => 'Your personal-data export :reference is ready',
            'intro' => 'The copy of your personal data you asked for has been prepared.',
            'link_intro' => 'Download it here:',
            'link_expiry' => 'The link expires at :expires (UTC).',
            'limit' => 'The archive can be downloaded :count times, after which it is deleted.',
            'warning' => 'The archive contains personal data. Store it somewhere private.',
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Player account area
    |----------------------------------------------------------------------
    */
    'account' => [
        'title' => 'Privacy requests',
        'heading' => 'Your privacy requests',
        'intro' => 'Ask for a copy of your data, a correction, a restriction or a deletion. Every request is reviewed and answered within the stated window.',
        'empty' => 'You have not submitted a privacy request.',
        'submit_heading' => 'Submit a request',
        'type_label' => 'What do you want to ask for?',
        'details_label' => 'Details (optional)',
        'details_hint' => 'Describe what the request concerns. Do not include identity document numbers.',
        'submit' => 'Submit request',
        'cancel' => 'Cancel request',
        'cancel_confirm' => 'Cancel this request? This cannot be undone.',
        'reference_label' => 'Reference',
        'status_label' => 'State',
        'due_label' => 'Answer due by',
        'created_label' => 'Submitted',
        'download' => 'Download personal-data export',
        'download_unavailable' => 'No export is ready to download yet.',
        'download_expired' => 'That export has expired and has been removed.',
        'download_limit_reached' => 'That export has reached its download limit.',
        'not_owner' => 'That request is not available.',
        'created' => 'Your privacy request has been recorded.',
        'cancelled' => 'The request has been cancelled.',
        'cannot_cancel' => 'This request can no longer be cancelled.',
        'cannot_submit_open_limit' => 'You already have the maximum number of open privacy requests. Wait for one to be answered or cancel one first.',
        'store_failed' => 'The request could not be recorded. Please try again later.',
        'disabled' => 'Privacy requests are not available in this environment.',
        'export_signed_url_required' => 'Use the download link from your email or from this page.',
        'retention_note' => 'A deletion request is reviewed before it is actioned: records the platform must keep for financial, anti-money-laundering or dispute reasons are retained for their legal period, and the answer to your request explains what was removed and what was kept, and why.',
    ],

    /*
    |----------------------------------------------------------------------
    | Refusal and retention vocabulary (operator-facing)
    |----------------------------------------------------------------------
    */
    'reasons' => [
        'identity_not_verified' => 'Identity could not be verified',
        'insufficient_information' => 'Insufficient information to locate the data',
        'retention_obligation' => 'A retention obligation applies',
        'manifestly_unfounded' => 'Manifestly unfounded or excessive',
        'not_applicable' => 'The request does not apply to this platform',
        'duplicate' => 'A request for the same matter is already open',
    ],

    'retention' => [
        'heading' => 'Retention check',
        'clear' => 'No retention obligation was found. Nothing in this table is blocking a reviewed decision.',
        'blocked' => 'The following records must be preserved. An erasure or restriction request cannot be executed against them without a documented override.',
        'financial_records' => 'Financial records inside the financial retention window',
        'aml_records' => 'Anti-money-laundering records inside their retention window',
        'open_withdrawal' => 'A withdrawal is still open',
        'open_dispute' => 'An open compliance case or dispute relates to this account',
        'self_exclusion' => 'An active self-exclusion is in force',
        'legal_hold' => 'A financial hold is active on this account',
        'legal_hold_reason' => 'A legal hold has been recorded on the request',
        'unknown_user' => 'No single account matches this request, so retention cannot be evaluated',
    ],

    /*
    |----------------------------------------------------------------------
    | Operator panel
    |----------------------------------------------------------------------
    */
    'filament' => [
        'filters' => [
            'due_soon' => 'Due soon',
            'overdue' => 'Overdue',
            'open' => 'Still open',
        ],
        'navigation_label' => 'Privacy requests',
        'navigation_group' => 'Compliance',
        'badge_tooltip' => 'Overdue privacy requests',
        'sections' => [
            'request' => 'Request',
            'requester' => 'Requester',
            'timeline' => 'Timeline',
            'exports' => 'Exports',
            'retention' => 'Retention',
        ],
        'fields' => [
            'reference' => 'Reference',
            'type' => 'Type',
            'status' => 'State',
            'locale' => 'Language',
            'email' => 'Address',
            'account' => 'Account',
            'details' => 'What the requester wrote',
            'received_at' => 'Received',
            'verified_at' => 'Verified',
            'due_at' => 'Due',
            'days_remaining' => 'Days remaining',
            'days_remaining_suffix' => 'day(s) left',
            'overdue_by' => ':days day(s) overdue',
            'completed_at' => 'Completed',
            'assigned_to' => 'Assigned to',
            'rejected_reason_code' => 'Refusal reason',
            'legal_hold_reason' => 'Legal hold',
            'export_disk' => 'Disk',
            'export_status' => 'State',
            'export_size' => 'Size',
            'export_downloads' => 'Downloads',
            'export_expires' => 'Expires',
            'export_released' => 'Released',
        ],
        'actions' => [
            'acknowledge' => 'Acknowledge',
            'request_information' => 'Request more information',
            'start_review' => 'Start review',
            'resume_review' => 'Resume review',
            'retention_check' => 'Run retention check',
            'build_export' => 'Build export',
            'release_export' => 'Release export',
            'complete' => 'Complete',
            'reject' => 'Refuse',
            'assign_to_me' => 'Assign to me',
            'note' => 'Add internal note',
        ],
        'action_fields' => [
            'retention_override' => 'Justification for completing against the retention report',
            'retention_override_hint' => 'Leave empty unless the retention report blocks this request and you are deliberately completing it anyway. At least 20 characters; it is stored on the request timeline.',
            'note' => 'Note',
            'note_required' => 'A note is required for this action.',
            'reason' => 'Refusal reason',
            'completion_note' => 'Completion note',
            'override' => 'Override reason',
            'override_help' => 'Required when records must be preserved. It is recorded in the timeline and the audit log.',
        ],
        'notifications' => [
            'retention_refused' => 'A retention obligation blocked this change',
            'transitioned' => 'Request moved to :status.',
            'invalid_transition' => 'That change is not allowed from the current state.',
            'note_added' => 'Internal note added.',
            'assigned' => 'Request assigned to you.',
            'retention_report' => 'Retention check completed.',
            'export_queued' => 'Export build queued.',
            'export_released' => 'Export released and the requester notified.',
            'export_not_ready' => 'There is no ready export to release.',
            'completed' => 'Request completed.',
            'rejected' => 'Request refused.',
            'mail_sent' => 'The requester has been notified.',
            'mail_not_configured' => 'The state changed, but no mail could be sent: the mail lane is not configured.',
        ],
    ],


    /*
    |----------------------------------------------------------------------
    | Export archive (README.txt written into the ZIP)
    |----------------------------------------------------------------------
    |
    | The archive explains itself to the person who opens it. The wording names
    | what is inside and - importantly - what was deliberately left out, so a
    | missing dataset reads as a decision rather than an error.
    |
    */
    'export' => [
        'readme_title' => 'Your personal data archive',
        'readme_reference' => 'Request reference: :reference',
        'readme_generated' => 'Generated at: :generated',
        'readme_contents' => 'This archive contains machine-readable JSON files with the following datasets:',
        'readme_excluded' => 'Deliberately not included: identity documents, images, internal risk assessments, anti-money-laundering records, compliance case notes, internal staff notes and audit records about staff actions. Where a retention obligation applies, those records are kept by the platform and are described in the answer to your request.',
        'readme_account' => 'Account username: :username',
    ],


    /*
    |----------------------------------------------------------------------
    | Compliance-desk SLA digest (internal mail)
    |----------------------------------------------------------------------
    |
    | Sent to the address in privacy_requests.compliance_email. It names
    | references and dates only - never a requester's address, never what they
    | wrote - so the digest can sit in an operations mailbox.
    |
    */
    'sla' => [
        'subject' => 'Privacy requests needing attention: :overdue overdue, :due_soon due soon',
        'line_overdue' => '- :reference (:type) was due on :due',
        'line_due_soon' => '- :reference (:type) is due on :due',
        'footer' => 'Open the compliance panel to review these requests.',
        'not_configured' => 'No compliance address is configured, so no digest was sent.',
    ],


    /*
    |----------------------------------------------------------------------
    | Export archive (README.txt written into the ZIP)
    |----------------------------------------------------------------------
    |
    | The archive explains itself to the person who opens it. The wording names
    | what is inside and - importantly - what was deliberately left out, so a
    | missing dataset reads as a decision rather than an error.
    |
    */

    'sla' => [
        'subject' => 'Privacy requests needing attention: :overdue overdue, :due_soon due soon',
        'line_overdue' => '- :reference (:type) was due on :due',
        'line_due_soon' => '- :reference (:type) is due on :due',
        'footer' => 'Open the compliance panel to review these requests.',
        'not_configured' => 'No compliance address is configured, so no digest was sent.',
    ],
];
