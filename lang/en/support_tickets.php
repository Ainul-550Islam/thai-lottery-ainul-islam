<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Support ticket lookup and staff inbox — English
|--------------------------------------------------------------------------
|
| THE PUBLIC VOCABULARY IS DELIBERATELY SMALLER THAN THE INTERNAL ONE. A
| visitor is told their ticket was received, is being looked at, or is
| closed. They are not told it was classified as spam, they never see an
| internal note, and there is no wording here that could reveal whether the
| reference belongs to somebody else's ticket.
|
| The operator panel uses the same status keys, so the two surfaces can never
| describe one ticket two different ways.
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | Public status vocabulary
    |----------------------------------------------------------------------
    */
    'status' => [
        'received' => 'Received',
        'in_review' => 'Being reviewed',
        'resolved' => 'Answered',
        'closed' => 'Closed',
        'unknown' => 'No matching ticket',
    ],

    /*
    |----------------------------------------------------------------------
    | Public lookup
    |----------------------------------------------------------------------
    */
    'lookup' => [
        'title' => 'Ticket status',
        'heading' => 'Check a support ticket',
        'intro' => 'Enter the reference from your confirmation screen and the email address you used. Only the state of the ticket is shown.',
        'reference_label' => 'Ticket reference',
        'email_label' => 'Email address',
        'submit' => 'Check status',
        'result_heading' => 'Status of :reference',
        'updated_label' => 'Last updated',
        'delivery_label' => 'Confirmation email accepted by a provider',
        'delivery_yes' => 'yes',
        'delivery_no' => 'not confirmed',
        'neutral' => 'If a ticket matches that reference and address, its state is shown above. Nothing else is disclosed, and this answer is the same whether or not a ticket exists.',
        'invalid' => 'Enter a ticket reference and the email address you used.',
        'rate_limited' => 'Too many checks from this connection. Please wait and try again later.',
        'disabled' => 'Public ticket-status lookup is not configured. Use the reference shown by the canonical contact submission workflow.',
        'not_configured' => 'Public ticket-status lookup is not configured.',
    ],

    /*
    |----------------------------------------------------------------------
    | Timeline event labels
    |----------------------------------------------------------------------
    */
    'events' => [
        'status_change' => 'Status changed',
        'note' => 'Internal note',
        'reply' => 'Reply sent',
    ],

    /*
    |----------------------------------------------------------------------
    | Reply mail
    |----------------------------------------------------------------------
    */
    'mail' => [
        'reply' => [
            'subject' => 'Re: :subject (:reference)',
            'intro' => 'You wrote to support about ":subject". Here is the answer:',
            'reference_label' => 'Your ticket reference',
            'footer' => 'Reply through the contact page quoting the reference above. Please do not send identity documents by email.',
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Operator inbox
    |----------------------------------------------------------------------
    */
    'delivery' => [
        'NOT_ATTEMPTED' => 'Not attempted',
        'PENDING' => 'Pending',
        'SENT' => 'Sent',
        'NOT_CONFIGURED' => 'Not configured',
        'FAILED' => 'Failed',
    ],

    'filament' => [
        'filters' => [
            'received_between' => 'Received between',
            'from' => 'From',
            'until' => 'Until',
        ],
        'navigation_label' => 'Support inbox',
        'navigation_group' => 'People',
        'badge_tooltip' => 'Support messages waiting for a human',
        'sections' => [
            'message' => 'Message',
            'sender' => 'Sender',
            'delivery' => 'Delivery',
            'timeline' => 'Timeline',
        ],
        'fields' => [
            'reference' => 'Reference',
            'status' => 'Status',
            'delivery_state' => 'Delivery state',
            'locale' => 'Language',
            'name' => 'Name',
            'email' => 'Address',
            'subject' => 'Subject',
            'message' => 'Message',
            'created_at' => 'Received',
            'resolved_at' => 'Resolved',
            'event_type' => 'Event',
            'actor' => 'Operator',
            'body' => 'Detail',
        ],
        'actions' => [
            'change_status' => 'Change status',
            'add_note' => 'Add internal note',
            'reply' => 'Reply by email',
            'mark_spam' => 'Mark as spam',
            'archive' => 'Archive',
            'reopen' => 'Reopen',
        ],
        'action_fields' => [
            'status' => 'New status',
            'note' => 'Internal note',
            'reply_body' => 'Reply text',
            'status_help' => 'Only transitions the workflow allows are offered.',
        ],
        'notifications' => [
            'status_changed' => 'Ticket moved to :status.',
            'invalid_transition' => 'That change is not allowed from the current state.',
            'note_added' => 'Internal note added.',
            'reply_queued' => 'The reply has been queued for delivery.',
            'reply_not_configured' => 'No reply mailbox is configured, so nothing was sent. Set the support reply address first.',
            'reply_body_required' => 'Write the reply text.',
            'no_delete' => 'A support message cannot be deleted. Mark it spam or archive it instead.',
        ],
    ],

];
