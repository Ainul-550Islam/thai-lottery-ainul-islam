<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PDPA data-subject requests (access / portability / erasure / ...)
|--------------------------------------------------------------------------
|
| THE LANE IS OFF. While PRIVACY_REQUESTS_ENABLED is false, every public
| endpoint that would open a request keeps answering the exact 501 body it
| answered before this lane existed, so a client written against the old
| contract sees no change at all. Nothing below is read while it is off.
|
| WHAT THIS CONFIGURATION IS FOR. A data-subject request is a legal promise
| with a clock attached: a verified requester is owed an answer within the
| statutory window, and the platform has to be able to prove when it received
| the request, when it verified the requester, what it did with it and why.
| The values here are the knobs an operator legitimately owns - the window,
| the reminder lead time, how long a verification link lives, how many open
| requests one address may hold, and where an export is written.
|
| WHAT IT IS NOT. There is no address, no company name and no regulator in
| this file. A fabricated compliance contact would be an address a requester
| writes to and nobody reads, so compliance_email has NO default: absent, the
| lane cannot send its own notifications and says so instead of pretending.
|
| NO IDENTITY DOCUMENTS. The intake accepts text only. 'rejected_upload_fields'
| names the field spellings a client might use to smuggle a document into the
| request, and any of them present is a refusal - not a silently ignored file.
|
*/

return [

    // Master switch. OFF by default, and off is the byte-for-byte old behaviour.
    'enabled' => (bool) env('PRIVACY_REQUESTS_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | The clock
    |--------------------------------------------------------------------------
    |
    | sla_days is the window between RECEIPT (verified intake, or an
    | authenticated submission, both of which set received_at) and the due date
    | written on the request row. reminder_days_before_due is how far ahead the
    | daily SLA sweep warns the compliance desk. Neither number claims to be a
    | statutory period: the statute sets the floor, the operator sets the
    | promise, and the value is stated in configuration so it can be audited.
    |
    */
    'sla_days' => max(1, (int) env('PRIVACY_SLA_DAYS', 30)),
    'reminder_days_before_due' => max(0, (int) env('PRIVACY_REMINDER_DAYS_BEFORE_DUE', 5)),

    /*
    |--------------------------------------------------------------------------
    | Verification
    |--------------------------------------------------------------------------
    |
    | A public intake proves nothing: anybody can type somebody else's address.
    | The request therefore starts in pending_verification and cannot progress
    | until the link sent to that address is opened. The link is single use,
    | expires, and only its SHA-256 hash is stored, so a database copy does not
    | let anyone verify a request they did not receive the mail for.
    |
    */
    'verification_ttl_minutes' => max(5, (int) env('PRIVACY_VERIFICATION_TTL_MINUTES', 1440)),

    /*
    |--------------------------------------------------------------------------
    | Abuse ceilings
    |--------------------------------------------------------------------------
    |
    | A public POST that creates rows AND sends mail is a relay unless it is
    | bounded. Three dimensions, all keyed on hashes: per address (how many open
    | requests one address may hold), per address per day, and per IP per day.
    | A submission over the ceiling is answered with the same neutral 202 as an
    | accepted one - an enumeration oracle made of error messages is still an
    | enumeration oracle.
    |
    */
    'max_open_per_email' => max(1, (int) env('PRIVACY_MAX_OPEN_PER_EMAIL', 3)),
    'max_submissions_per_email_per_day' => max(1, (int) env('PRIVACY_MAX_PER_EMAIL_PER_DAY', 5)),
    'max_submissions_per_ip_per_day' => max(1, (int) env('PRIVACY_MAX_PER_IP_PER_DAY', 10)),
    'details_max_length' => max(50, (int) env('PRIVACY_DETAILS_MAX_LENGTH', 2000)),

    /*
    |--------------------------------------------------------------------------
    | Request vocabulary
    |--------------------------------------------------------------------------
    |
    | The closed set of request types this deployment accepts. A type outside
    | this list is refused at validation, so a new enum case cannot become a
    | public promise simply by existing in code.
    |
    */
    'request_types' => [
        'access',
        'portability',
        'rectification',
        'erasure',
        'restriction',
        'objection',
        'withdraw_consent',
    ],

    // Languages an intake and an export README may be written in.
    'locales' => ['en', 'th', 'bn'],

    /*
    |--------------------------------------------------------------------------
    | Field names that mean "an attachment was sent"
    |--------------------------------------------------------------------------
    |
    | The intake is a text form. These spellings are checked explicitly and any
    | of them present - even empty - is a 422. Accepting a passport scan through
    | a public form would create a store of identity documents that no reviewed
    | process asked for, so the form does not have that capability at all.
    |
    */
    'rejected_upload_fields' => [
        'attachment',
        'attachments',
        'document',
        'documents',
        'document_file',
        'file',
        'files',
        'id_card',
        'id_document',
        'identity_document',
        'passport',
        'national_id',
        'upload',
    ],

    /*
    |--------------------------------------------------------------------------
    | Personal-data export
    |--------------------------------------------------------------------------
    |
    | Built on a PRIVATE disk, under a random name, with a recorded SHA-256 and
    | a hard expiry. The archive is never linked publicly: the mail that
    | announces it carries a signed, expiring URL, and the download endpoint
    | re-checks ownership, expiry and a download counter before streaming.
    |
    | max_rows_per_file bounds one machine-readable file so a very large history
    | is split into numbered parts instead of being buffered in memory.
    |
    */
    'export' => [
        'disk' => (string) env('PRIVACY_EXPORT_DISK', 'local'),
        'root' => (string) env('PRIVACY_EXPORT_ROOT', 'privacy-exports'),
        'ttl_days' => max(1, (int) env('PRIVACY_EXPORT_TTL_DAYS', 7)),
        'max_downloads' => max(1, (int) env('PRIVACY_EXPORT_MAX_DOWNLOADS', 3)),
        'signed_url_ttl_minutes' => max(5, (int) env('PRIVACY_EXPORT_URL_TTL_MINUTES', 60)),
        'max_rows_per_file' => max(100, (int) env('PRIVACY_EXPORT_MAX_ROWS_PER_FILE', 2000)),
    ],

    /*
    |--------------------------------------------------------------------------
    | The compliance desk
    |--------------------------------------------------------------------------
    |
    | Where the daily SLA sweep sends its reminders, and where a requester is
    | told to write if they cannot use the form. No default: an invented
    | mailbox is worse than a stated NOT_CONFIGURED.
    |
    */
    'compliance_email' => env('PRIVACY_COMPLIANCE_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | Retention facts the guard reports (it never deletes anything)
    |--------------------------------------------------------------------------
    |
    | When staff evaluate an erasure request, PrivacyRetentionGuard reads these
    | windows and enumerates what must be KEPT and why. The guard does not
    | delete - it answers the question - so these numbers are advisory text
    | shown to a reviewer, and the reviewer remains accountable for the answer.
    |
    */
    'retention' => [
        'financial_records_days' => max(1, (int) env('PRIVACY_RETENTION_FINANCIAL_DAYS', 2555)),
        'aml_records_days' => max(1, (int) env('PRIVACY_RETENTION_AML_DAYS', 2555)),
        'support_records_days' => max(1, (int) env('PRIVACY_RETENTION_SUPPORT_DAYS', 365)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Route throttles
    |--------------------------------------------------------------------------
    |
    | Registered as named limiters by PublicServicesServiceProvider. Kept here
    | so the ceiling is a deployment decision, not a code constant.
    |
    */
    'rate_limits' => [
        'submit_per_hour' => max(1, (int) env('PRIVACY_SUBMIT_PER_HOUR', 3)),
        'verify_per_minute' => max(1, (int) env('PRIVACY_VERIFY_PER_MINUTE', 6)),
    ],

];
