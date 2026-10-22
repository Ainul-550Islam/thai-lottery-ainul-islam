<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Lifecycle Email Campaigns (D4)
|--------------------------------------------------------------------------
|
| Configuration only. No query, no send and no eligibility decision lives in
| this file: the campaign service reads these values and applies them. Every
| number an operator may need to change in production is declared here and
| nowhere else.
|
| A 2026 audit found five transactional notifications and no lifecycle
| messaging whatsoever — no welcome, no first-deposit nudge, no draw reminder,
| no win-back, no grade promotion.
|
| THE MASTER KILL SWITCH
| 'enabled' is false by default. Nothing is sent until an operator turns this
| on, which is deliberate: a lifecycle campaign is a standing query against
| live player data, and an email sent in error cannot be unsent. Turning the
| system on should be a decision, not a deployment.
|
| WHY EVERY CAMPAIGN IS OFF BY DEFAULT TOO
| Because they should be turned on one at a time, watched, and then left
| alone. Flipping all five on at once means that if deliverability or tone is
| wrong, it is wrong across every player at once — and you will not know which
| campaign caused the complaint.
|
| ON THE NUMBERS BELOW
| The intervals are deliberately conservative. A win-back at 30 days is
| slower than most operators use, because this product takes real money and
| the reputational cost of messaging a dormant player too eagerly is higher
| than the revenue from messaging them a week sooner. Change them with that
| trade in mind, not to hit a quarterly number.
|
*/

return [

    'enabled' => env('LIFECYCLE_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Global send cap
    |--------------------------------------------------------------------------
    |
    | Hard ceiling on how many messages ONE run may send. This is a brake, not
    | a target: it bounds the blast radius of a bad eligibility query. If the
    | query is wrong, it is wrong for `cap` players rather than for the whole
    | table, and the next run stops before it compounds.
    |
    | It also protects the sending domain. A mail provider that sees a sudden
    | hundred-fold spike does not wait for an explanation; it throttles or
    | blocks, and that takes the transactional mail down with it.
    |
    */
    'max_sends_per_run' => env('LIFECYCLE_MAX_SENDS_PER_RUN', 200),

    /*
    |--------------------------------------------------------------------------
    | When the campaigns run
    |--------------------------------------------------------------------------
    |
    | Local time of the daily run, per config('app.timezone').
    |
    | Late morning rather than midnight: a "draw closes tomorrow" reminder that
    | arrives at 04:00 is read the next day, which is after the cut-off it was
    | warning about. Midnight is also when every other maintenance task on the
    | box wants to run.
    |
    */
    'daily_at' => env('LIFECYCLE_DAILY_AT', '10:00'),

    /*
    |--------------------------------------------------------------------------
    | The campaigns
    |--------------------------------------------------------------------------
    |
    | Each entry: whether it runs at all, and the interval that makes a player
    | eligible. `dry_run` sends nothing and reports who would have been
    | contacted — use it before enabling anything for real.
    |
    */
    'campaigns' => [

        /*
        | Registered, has not placed a first bet.
        |
        | delay_hours: how long after registration before this fires. Not zero:
        | emailing someone the second they sign up reads as a bot, and a
        | registration that is still being verified is not a player yet.
        |
        | max_age_hours: stop after this long. A welcome sent three weeks later
        | is not a welcome.
        */
        'welcome' => [
            'enabled' => env('LIFECYCLE_WELCOME_ENABLED', false),
            'dry_run' => env('LIFECYCLE_WELCOME_DRY_RUN', true),
            'delay_hours' => 6,
            'max_age_hours' => 72,
        ],

        /*
        | Registered and funded nothing.
        |
        | This is the largest single drop in any gaming funnel and the one
        | worth the most attention. It fires once, well after the welcome, so a
        | player never receives both messages on consecutive days.
        */
        'deposit_nudge' => [
            'enabled' => env('LIFECYCLE_DEPOSIT_NUDGE_ENABLED', false),
            'dry_run' => env('LIFECYCLE_DEPOSIT_NUDGE_DRY_RUN', true),
            'delay_hours' => 48,
            'max_age_hours' => 336, // 14 days, then they are a win-back case
        ],

        /*
        | A draw they could enter closes soon.
        |
        | This reuses the countdown the product already computes for the draw
        | pages, so there is no second source of truth for "when is the next
        | draw".
        |
        | lead_hours: send when the draw is this many hours away, not closer —
        | a reminder that arrives after the cut-off is worse than none, and
        | sending at the last minute invites a rush of failed payments.
        */
        'draw_reminder' => [
            'enabled' => env('LIFECYCLE_DRAW_REMINDER_ENABLED', false),
            'dry_run' => env('LIFECYCLE_DRAW_REMINDER_DRY_RUN', true),
            'lead_hours' => 24,
            'lookback_days' => 60, // has bet at least once in this window
        ],

        /*
        | Active once, silent since.
        |
        | The most delicate campaign here. dormant_days is long on purpose:
        | messaging a player who stopped a fortnight ago reads as pressure, and
        | on a real-money product that is a responsible-gaming concern, not
        | just a marketing one.
        */
        'winback' => [
            'enabled' => env('LIFECYCLE_WINBACK_ENABLED', false),
            'dry_run' => env('LIFECYCLE_WINBACK_DRY_RUN', true),
            'dormant_days' => 30,
            'max_dormant_days' => 365, // beyond a year, leave them alone
        ],

        /*
        | Reached a new account grade.
        |
        | min_grade: do not message the entry tier — everyone starts there, so
        | it is not news.
        */
        'grade_promotion' => [
            'enabled' => env('LIFECYCLE_GRADE_PROMOTION_ENABLED', false),
            'dry_run' => env('LIFECYCLE_GRADE_PROMOTION_DRY_RUN', true),
            'within_days' => 7,
        ],
    ],
];
