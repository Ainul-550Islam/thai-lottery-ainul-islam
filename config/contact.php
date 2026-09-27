<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Contact / Support centre (PROMPT 10)
|--------------------------------------------------------------------------
|
| A PUBLIC SUPPORT SURFACE, NOT AN OPERATOR INBOX. Everything here concerns a
| visitor sending a message and being told honestly what happened to it. There
| is no ticket workflow, no agent assignment, no wallet, no payout and no
| lottery result data in this lane.
|
| THE IDENTITY IS OURS AND IT IS CONFIGURABLE. No support address, phone,
| location or wording was taken from any reference page. When nothing is
| configured the page says support contact is unavailable rather than
| inventing an address, because an invented address is a message a visitor
| believes they sent and nobody receives.
|
| THE HARD RULE OF THIS LANE: "we stored your message" and "an email reached
| our inbox" are DIFFERENT FACTS, and the page must never report the second
| when only the first is true. Every delivery state below exists to keep those
| two apart.
|
*/

return [

    'enabled' => (bool) env('CONTACT_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Public support identity
    |--------------------------------------------------------------------------
    |
    | Rendered on the page. Both may be blank: the component then shows the
    | honest unavailable state. A blank address is better than a wrong one.
    |
    | App\Services\Support\PublicSupportService is the single reader of these,
    | shared with the Home page and footer, so the site cannot show two
    | different support identities on two pages.
    |
    */

    'support' => [
        'name' => env('CONTACT_SUPPORT_NAME'),
        'email' => env('CONTACT_SUPPORT_EMAIL'),
        // Free text such as "Monday to Friday, 09:00-18:00". It is a LABEL,
        // never parsed, so it cannot promise a response time the platform has
        // not committed to.
        'hours' => env('CONTACT_SUPPORT_HOURS'),
    ],

    'timezone' => (string) env('CONTACT_TIMEZONE', env('APP_TIMEZONE', 'Asia/Bangkok')),

    /*
    |--------------------------------------------------------------------------
    | Field bounds
    |--------------------------------------------------------------------------
    |
    | Enforced SERVER SIDE. The same numbers drive the HTML maxlength, but the
    | attribute is a convenience for honest visitors; the validator is the
    | control, because maxlength is absent from every request that did not come
    | from the form.
    |
    */

    'limits' => [
        'name' => (int) env('CONTACT_MAX_NAME_LENGTH', 120),
        'email' => (int) env('CONTACT_MAX_EMAIL_LENGTH', 190),
        'subject' => (int) env('CONTACT_MAX_SUBJECT_LENGTH', 160),
        'message' => (int) env('CONTACT_MAX_MESSAGE_LENGTH', 4000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Anti-abuse
    |--------------------------------------------------------------------------
    |
    | A public POST that sends mail is a relay if it is unbounded. The limiter
    | keys on IP per minute and per hour, plus a HASHED email fingerprint per
    | hour - hashed because a limiter key is a cache entry, and a cache full of
    | visitors' addresses is a data store nobody agreed to.
    |
    | The honeypot is a field a human never sees and a naive bot always fills.
    | It costs nothing, needs no third party, and is the baseline that works
    | when no CAPTCHA provider is configured. No anti-bot dependency is added
    | here for appearance.
    |
    */

    'anti_spam' => [
        'honeypot_field' => 'website',
        'per_minute' => (int) env('CONTACT_SUBMIT_PER_MINUTE', 3),
        'per_hour' => (int) env('CONTACT_SUBMIT_PER_HOUR', 20),
        'email_per_hour' => (int) env('CONTACT_SUBMIT_EMAIL_PER_HOUR', 10),

        // How long an identical submission from the same sender is treated as
        // a repeat of the first rather than a new message. Long enough to
        // absorb a double-click, a browser retry or a mobile reconnect; short
        // enough that someone genuinely writing twice about the same subject
        // is not silenced.
        'duplicate_window_seconds' => (int) env('CONTACT_DUPLICATE_WINDOW_SECONDS', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbound delivery
    |--------------------------------------------------------------------------
    |
    | 'enabled' false, or no support address, means NOT_CONFIGURED - and the
    | page says so. It does not say "sent".
    |
    | FROM IS ALWAYS OURS, REPLY-TO IS THE VISITOR. Putting a visitor's address
    | in From would make the platform send mail claiming to be them: it fails
    | SPF and DMARC, it gets the domain's mail classified as spam, and it is
    | trivially abusable as a spoofing relay. Reply-To gives support the
    | one-click reply without any of that.
    |
    */

    'delivery' => [
        'enabled' => (bool) env('CONTACT_EMAIL_ENABLED', false),
        'from_address' => env('CONTACT_MAIL_FROM_ADDRESS'),
        'from_name' => env('CONTACT_MAIL_FROM_NAME'),
        'subject_prefix' => (string) env('CONTACT_MAIL_SUBJECT_PREFIX', '[Contact]'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Applies ONLY to messages an operator has finished with. An unresolved
    | message is never deleted by a scheduled job: a support request vanishing
    | because it was old is worse than keeping it.
    |
    | The number is a configuration value, not a legal opinion. Nothing here
    | claims a statutory retention period.
    |
    */

    'retention' => [
        'days' => (int) env('CONTACT_RETENTION_DAYS', 365),
        'purgeable_statuses' => ['RESOLVED', 'ARCHIVED', 'SPAM'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Closed vocabularies
    |--------------------------------------------------------------------------
    |
    | A value outside these lists never reaches a template, so a new internal
    | state cannot leak to the public by simply existing.
    |
    */

    'statuses' => ['RECEIVED', 'IN_REVIEW', 'RESOLVED', 'SPAM', 'ARCHIVED'],

    'delivery_states' => ['NOT_ATTEMPTED', 'SENT', 'PENDING', 'NOT_CONFIGURED', 'FAILED'],

    /*
    |--------------------------------------------------------------------------
    | SEO
    |--------------------------------------------------------------------------
    |
    | The contact page is ordinary public content and is indexable. Only the
    | POST endpoint is not, and robots.txt has no say over a POST anyway.
    |
    */

    'seo' => [
        'path' => 'contact',
        'robots' => 'index,follow',
    ],
];
