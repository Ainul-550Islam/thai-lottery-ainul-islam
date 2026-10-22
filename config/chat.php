<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Live Chat Support — canonical configuration
|--------------------------------------------------------------------------
|
| WHAT THIS FEATURE IS
| ----------------------------------------------------------------------
| Live Chat is the REAL-TIME conversational support channel. It is a
| different product from the asynchronous support-ticket surface
| (support_cases / support_messages, served by SupportPortalController):
|
|   Ticket  -> a case with a subject, a category, an owner, answered when
|              an agent gets to it. Requires a signed-in account.
|   Chat    -> a live conversation with an operator, where BOTH parties
|              expect an answer inside seconds. Must work for a visitor
|              who has not signed in, because losing a sale to a sign-up
|              wall is the entire reason live chat exists.
|
| The two surfaces share vocabulary and house style, never a table. The
| ticket table has `owner_user_id NOT NULL`; a chat needs an anonymous
| identity, so reusing that table would have required weakening a
| constraint that a support case legitimately depends on.
|
| TRANSPORT — HONEST BY DEFAULT
| ----------------------------------------------------------------------
| 'transport' is the ONE place the delivery mechanism is declared.
|
|   'poll'      the widget asks the server for new messages on a fixed
|               interval. Works on every deployment this repository can
|               actually describe today: no Redis, no Reverb, no websocket
|               process. This is the default because it is the honest
|               default — `BROADCAST_CONNECTION=log` in .env.example means
|               a broadcast event currently reaches a log file.
|
|   'broadcast' the same endpoints PLUS a broadcast event, for a
|               deployment that has installed laravel/reverb and set
|               BROADCAST_CONNECTION. The polling client keeps working
|               either way; broadcasting only makes it faster. Nothing in
|               this feature degrades to a broken state when a websocket
|               is configured, and nothing pretends one exists when it is
|               not.
|
| A deployment that sets 'broadcast' without a working broadcaster is
| refused at boot by ChatTransportGuard rather than served silently — the
| same fail-closed posture as ProductionSafetyServiceProvider.
|
| WHAT IS NEVER INVENTED HERE
| ----------------------------------------------------------------------
| There is no fabricated SLA, no invented business-hours claim and no
| default agent. If business hours are not configured the widget says so
| and offers the asynchronous ticket form instead; it never renders
| "We're online!" over an empty queue.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    | Disabled means the widget renders its honest unavailable state and the
    | operator console reports NOT_CONFIGURED. It never means "silently
    | accept messages nobody will read".
    */
    'enabled' => (bool) env('CHAT_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Transport
    |--------------------------------------------------------------------------
    | 'poll' | 'broadcast'. See the header note. Validated at boot.
    */
    'transport' => (string) env('CHAT_TRANSPORT', 'poll'),

    /*
    |--------------------------------------------------------------------------
    | Polling cadence (seconds)
    |--------------------------------------------------------------------------
    | The visitor client is the one that must feel immediate, so it polls
    | fastest while a conversation is open and slower while it is idle.
    |
    | These are DELIBERATELY different from the operator cadence: one agent
    | watching a queue of conversations costs the queue more than one
    | visitor watching one conversation, so the operator interval is the
    | more conservative of the two.
    */
    'poll' => [
        // Visitor, conversation open and visible.
        'visitor_interval_seconds' => (int) env('CHAT_POLL_VISITOR_SECONDS', 4),
        // Visitor, tab hidden or widget collapsed. Still cheap, still honest.
        'visitor_idle_interval_seconds' => (int) env('CHAT_POLL_VISITOR_IDLE_SECONDS', 20),
        // Operator console.
        'operator_interval_seconds' => (int) env('CHAT_POLL_OPERATOR_SECONDS', 5),
        // Upper bound the server will ever promise a client; the client may
        // not ask for a faster cadence than this.
        'min_interval_seconds' => (int) env('CHAT_POLL_MIN_SECONDS', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Anonymous (guest) chat
    |--------------------------------------------------------------------------
    | When true a visitor may open a conversation before signing in, giving
    | only a display name (email is optional and used for the transcript).
    | The conversation is CLAIMED by the account if that visitor signs in
    | during the same browser session — see ChatConversationService::claim.
    */
    'guest' => [
        'enabled' => (bool) env('CHAT_GUEST_ENABLED', true),
        'require_email' => (bool) env('CHAT_GUEST_REQUIRE_EMAIL', false),
        'display_name_max' => 60,
        'email_max' => 190,
        // How long an unused guest identity may be reused from the same
        // browser before it is treated as a new visitor.
        'identity_ttl_hours' => (int) env('CHAT_GUEST_IDENTITY_TTL_HOURS', 72),
    ],

    /*
    |--------------------------------------------------------------------------
    | Message rules
    |--------------------------------------------------------------------------
    */
    'message' => [
        'max_length' => (int) env('CHAT_MESSAGE_MAX_LENGTH', 2000),
        'min_length' => 1,
        // A visitor may not post more than this many messages inside one
        // conversation per minute. Exceeding it returns 429 with a retry
        // hint; it never silently drops the message.
        'visitor_per_minute' => (int) env('CHAT_VISITOR_MESSAGES_PER_MINUTE', 20),
        'agent_per_minute' => (int) env('CHAT_AGENT_MESSAGES_PER_MINUTE', 120),
        // How many messages one poll response may carry. Bounded so a
        // backlog cannot turn a poll into a megabyte of JSON.
        'page_size' => (int) env('CHAT_MESSAGE_PAGE_SIZE', 50),
        'max_page_size' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    | Stored on a PRIVATE disk and streamed through an authorised controller.
    | Never a public URL: a support screenshot can contain an ID document,
    | a bank slip or a player's address.
    */
    'attachments' => [
        'enabled' => (bool) env('CHAT_ATTACHMENTS_ENABLED', true),
        'disk' => (string) env('CHAT_ATTACHMENT_DISK', 'local'),
        'prefix' => 'chat_attachments',
        'max_kb' => (int) env('CHAT_ATTACHMENT_MAX_KB', 5120),
        'max_per_message' => (int) env('CHAT_ATTACHMENT_MAX_PER_MESSAGE', 3),
        'allowed_mimes' => [
            'image/jpeg',
            'image/png',
            'image/webp',
            'application/pdf',
            'text/plain',
        ],
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'txt'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue behaviour, SLA and idle close
    |--------------------------------------------------------------------------
    | Waiting is a STATE, and an operator can see how long it has lasted.
    | 'first_response_warning_seconds' drives an alert; it is not a promise
    | to the visitor, and the widget never displays a countdown it cannot
    | honour.
    */
    'queue' => [
        // Seconds a conversation may sit in Waiting before the alert job
        // raises it. Not a public claim.
        'first_response_warning_seconds' => (int) env('CHAT_FIRST_RESPONSE_WARN_SECONDS', 180),
        // Seconds of silence (either side) after which a conversation is
        // auto-closed by the idle job. The transcript is still sent.
        'idle_close_minutes' => (int) env('CHAT_IDLE_CLOSE_MINUTES', 30),
        // Warning shown to the visitor before an idle close fires.
        'idle_warning_minutes' => (int) env('CHAT_IDLE_WARNING_MINUTES', 5),
        // One agent may hold at most this many ACTIVE conversations at once.
        // Above it, claiming returns a refusal instead of overloading them.
        'max_concurrent_per_agent' => (int) env('CHAT_MAX_CONCURRENT_PER_AGENT', 8),
        'auto_assign' => (bool) env('CHAT_AUTO_ASSIGN', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent presence
    |--------------------------------------------------------------------------
    | Presence is OBSERVED, never asserted by the visitor. An agent is online
    | only while their console is heartbeating; the moment the heartbeat
    | lapses they become Away, then Offline. The widget reads the resulting
    | COUNTER of online agents, so it can say "no agent online right now"
    | truthfully instead of showing a green dot nobody is behind.
    */
    'presence' => [
        'heartbeat_seconds' => (int) env('CHAT_AGENT_HEARTBEAT_SECONDS', 30),
        'away_after_seconds' => (int) env('CHAT_AGENT_AWAY_AFTER_SECONDS', 90),
        'offline_after_seconds' => (int) env('CHAT_AGENT_OFFLINE_AFTER_SECONDS', 240),
    ],

    /*
    |--------------------------------------------------------------------------
    | Business hours
    |--------------------------------------------------------------------------
    | 'timezone' null means the platform timezone (config('app.timezone')).
    | 'schedule' empty means ALWAYS OPEN — which is the honest default for an
    | online product, and different from claiming 24/7 staffing.
    |
    | Set a 'notice' string to display when closed; the widget prints that
    | string verbatim. Leaving it null while the schedule is closed renders
    | the offline message instead of an invented promise.
    */
    'hours' => [
        'timezone' => env('CHAT_TIMEZONE'),
        'schedule' => [],
        'notice' => env('CHAT_CLOSED_NOTICE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention and privacy
    |--------------------------------------------------------------------------
    | Conversation bodies are personal data. 'retention_days' is the window
    | after which the purge job REDACTS the message bodies (replacing them
    | with a tombstone) while keeping the row, its reference and its
    | timestamps, so operational counts stay truthful after the content is
    | gone. Nothing is hard-deleted by default.
    */
    'retention' => [
        'days' => (int) env('CHAT_RETENTION_DAYS', 730),
        'redact_batch_size' => (int) env('CHAT_REDACT_BATCH_SIZE', 500),
        'tombstone' => '[redacted by retention policy]',
    ],

    /*
    |--------------------------------------------------------------------------
    | Transcript email
    |--------------------------------------------------------------------------
    | Sent on close when the visitor supplied an email address. MAIL_MAILER
    | is 'log' in .env.example, so on a stock checkout this writes to the log
    | and the job reports DELIVERED: LOG — never "sent" when it was not.
    */
    'transcript' => [
        'enabled' => (bool) env('CHAT_TRANSCRIPT_ENABLED', true),
        'queue' => (string) env('CHAT_TRANSCRIPT_QUEUE', 'notifications'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits (named limiters are registered by ChatServiceProvider)
    |--------------------------------------------------------------------------
    */
    'rate_limits' => [
        'start_per_ip_per_hour' => (int) env('CHAT_START_PER_IP_HOUR', 10),
        'send_per_conversation_per_minute' => (int) env('CHAT_SEND_PER_CONVERSATION_MINUTE', 30),
        'poll_per_ip_per_minute' => (int) env('CHAT_POLL_PER_IP_MINUTE', 240),
        'operator_action_per_minute' => (int) env('CHAT_OPERATOR_ACTION_MINUTE', 180),
    ],

    /*
    |--------------------------------------------------------------------------
    | Widget presentation
    |--------------------------------------------------------------------------
    | Copy shown to the visitor. 'greeting' and 'offline_message' are
    | translation KEYS resolved by the view; the English fallback lives in
    | lang/en/chat.php so a missing translation degrades to readable text
    | rather than an empty bubble.
    */
    'widget' => [
        'position' => (string) env('CHAT_WIDGET_POSITION', 'bottom-right'),
        'title_key' => 'chat.widget_title',
        'greeting_key' => 'chat.widget_greeting',
        'offline_message_key' => 'chat.widget_offline',
        'composer_placeholder_key' => 'chat.composer_placeholder',
        // Show the launcher on public pages by default. Auth pages keep it
        // off so it cannot cover the login form on a small screen.
        'show_on' => [
            'public' => true,
            'auth' => false,
            'player' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Operator console
    |--------------------------------------------------------------------------
    */
    'operator' => [
        'queue_page_size' => (int) env('CHAT_QUEUE_PAGE_SIZE', 25),
        'transcript_page_size' => (int) env('CHAT_TRANSCRIPT_PAGE_SIZE', 100),
        // Permission names checked by the policies. They are declared here so
        // a deployment can rename them without touching code.
        'permissions' => [
            'view' => 'chat.view',
            'reply' => 'chat.reply',
            'assign' => 'chat.assign',
            'close' => 'chat.close',
            'manage_canned' => 'chat.manage_canned',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Observability
    |--------------------------------------------------------------------------
    | Surfaced by ChatHealthService on the operator console and by
    | `php artisan chat:health`. Read-only; never a fabricated percentage.
    */
    'health' => [
        'stale_waiting_seconds' => (int) env('CHAT_STALE_WAITING_SECONDS', 600),
        'unanswered_warning_count' => (int) env('CHAT_UNANSWERED_WARN_COUNT', 5),
    ],

];
