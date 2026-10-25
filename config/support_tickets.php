<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Support ticket lookup and the staff inbox
|--------------------------------------------------------------------------
|
| THE LANE IS OFF. While SUPPORT_TICKET_STATUS_ENABLED is false, the public
| status endpoint answers the exact 501 body it answered before this lane
| existed. The staff inbox in the operator panel is gated by permissions, not
| by this flag: it is an internal surface and hiding it would leave real
| contact messages with nowhere to be worked.
|
| WHAT THE PUBLIC LOOKUP MAY RETURN. A visitor who knows their own reference
| and address may learn the state of THEIR ticket. Nothing else: not the
| subject, not the message, not the name, not an internal note, not whether
| the reference exists at all when the address does not match it. The answer
| for "wrong address" and "no such reference" is identical, and it is shaped
| exactly like a success so it cannot be told apart by a client either.
|
| NO ADDRESS LIVES HERE. reply_from_address has no default: an operator sets
| the mailbox replies come from, and until they do the reply action reports
| NOT_CONFIGURED instead of sending mail that would fail SPF or arrive from an
| address nobody monitors.
|
*/

return [

    // Master switch for the PUBLIC status lookup only.
    'status_lookup_enabled' => (bool) env('SUPPORT_TICKET_STATUS_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Outbound replies
    |--------------------------------------------------------------------------
    |
    | FROM IS OURS. A reply is sent from the operator's own mailbox with the
    | visitor's ticket reference in the subject; the visitor's address is the
    | recipient. It is never placed in From, because that turns the platform
    | into a spoofing relay and fails DMARC on the platform's own domain.
    |
    */
    'reply_from_name' => env('SUPPORT_REPLY_FROM_NAME'),
    'reply_from_address' => env('SUPPORT_REPLY_FROM_ADDRESS'),
    'reply_subject_prefix' => (string) env('SUPPORT_REPLY_SUBJECT_PREFIX', '[Support]'),

    /*
    |--------------------------------------------------------------------------
    | Bounds
    |--------------------------------------------------------------------------
    |
    | An operator's reply and an internal note are both prose with a ceiling.
    | The note is never sent anywhere; it exists so the next operator can read
    | what was already tried.
    |
    */
    'reply_max_length' => max(50, (int) env('SUPPORT_REPLY_MAX_LENGTH', 4000)),
    'note_max_length' => max(10, (int) env('SUPPORT_NOTE_MAX_LENGTH', 2000)),

    /*
    |--------------------------------------------------------------------------
    | Public lookup ceilings
    |--------------------------------------------------------------------------
    |
    | Keyed on the IP and on a hash of the reference, never on the address in
    | clear: a limiter store is a cache, and caches get dumped.
    |
    */
    'rate_limits' => [
        'lookup_per_minute' => max(1, (int) env('SUPPORT_LOOKUP_PER_MINUTE', 5)),
        'lookup_per_hour' => max(1, (int) env('SUPPORT_LOOKUP_PER_HOUR', 20)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Public status labels
    |--------------------------------------------------------------------------
    |
    | The public vocabulary is NARROWER than the internal one on purpose. SPAM
    | and ARCHIVED are internal outcomes; a visitor is told their ticket is
    | closed, not that it was classified as spam. Every value below is a
    | translation key, so no user-facing sentence is written in PHP or SQL.
    |
    */
    'status_label_keys' => [
        'RECEIVED' => 'support_tickets.status.received',
        'IN_REVIEW' => 'support_tickets.status.in_review',
        'RESOLVED' => 'support_tickets.status.resolved',
        'SPAM' => 'support_tickets.status.closed',
        'ARCHIVED' => 'support_tickets.status.closed',
    ],

    /*
    |--------------------------------------------------------------------------
    | Staff transitions
    |--------------------------------------------------------------------------
    |
    | The only legal moves an operator may make. Enforced inside
    | SupportTicketService with a row lock, so two operators clicking at once
    | cannot produce a transition neither of them was allowed to make. A status
    | that is absent here can never be reached.
    |
    */
    'transitions' => [
        'RECEIVED' => ['IN_REVIEW', 'RESOLVED', 'SPAM', 'ARCHIVED'],
        'IN_REVIEW' => ['RESOLVED', 'SPAM', 'ARCHIVED', 'RECEIVED'],
        'RESOLVED' => ['IN_REVIEW', 'ARCHIVED'],
        'SPAM' => ['IN_REVIEW', 'ARCHIVED'],
        'ARCHIVED' => ['IN_REVIEW'],
    ],

];
