<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Payout illustration
|--------------------------------------------------------------------------
|
| An ILLUSTRATION, not a simulator and not a prediction: the endpoint states
| what a stake would return IF the selection matched, using the multiplier the
| platform publishes for that bet type. There is no randomness, no drawn
| number, no probability figure and no promise of winnings anywhere in it.
|
| WHERE THE MULTIPLIER COMES FROM
| Never from this file. config('lottery.payouts.multiplier_source') declares
| where the project keeps its authoritative rates ('lottery.markets'), and
| App\Services\Betting\PayoutMultiplierService resolves them. Where those
| declared sources contradict one another (Run is sold at two configured rates:
| run_top = 3 and run_bottom = 4), no rate is chosen: the bet type answers
| NOT_CONFIGURED instead of illustrating a number nobody signed off.
|
| The lane ships ON, because App\Services\Lottery\PayoutIllustrationService is
| complete: it reads the multiplier from the declared payout source, refuses a
| bet type whose configured rates contradict one another, computes in bcmath at
| a fixed scale, and attaches a mandatory disclaimer key to every READY figure.
| Setting PAYOUT_ILLUSTRATION_ENABLED=false returns exactly what the endpoint
| answered before the lane existed (HTTP 501 + state NOT_CONFIGURED).
|
| EVERY OUTPUT IS LABELLED ILLUSTRATIVE. The response carries `illustrative:
| true` and the disclaimer key, and no surface may render the figure without it.
| Nothing here predicts a number, states a probability or promises a win.
|
*/

return [

    // Master switch. ON by default; see the header for why that is safe.
    'enabled' => filter_var(env('PAYOUT_ILLUSTRATION_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    // Stamped onto every response so a client cannot render the figure as a
    // promise. The value is part of the contract; do not remove it.
    'illustrative' => true,

    // Bet types a caller may illustrate (values of App\Enums\BetType).
    'supported_bet_types' => ['2d', '3d', 'tod', 'run'],

    /*
    |--------------------------------------------------------------------------
    | Stake bounds
    |--------------------------------------------------------------------------
    |
    | max_stake defaults to the published maximum single-bet amount so an
    | illustration can never exceed what a real bet would accept. A stake above
    | it is rejected as a client error, never silently clamped.
    |
    */

    'min_stake' => (string) env('PAYOUT_ILLUSTRATION_MIN_STAKE', '1.00'),
    'max_stake' => (string) env('PAYOUT_ILLUSTRATION_MAX_STAKE', (string) env('LOTTERY_MAX_BET_AMOUNT', '100000.00')),

    // Stake strings accepted from a client: up to 13 integer digits, 0-2 decimals.
    'stake_pattern' => '/^\d{1,13}(\.\d{1,2})?$/',

    /*
    |--------------------------------------------------------------------------
    | Arithmetic
    |--------------------------------------------------------------------------
    |
    | Exact decimal only: bcmath, fixed scale, one half-up rounding step at the
    | end. No binary floating point anywhere in the calculation.
    |
    */

    'rounding' => [
        'engine' => 'bcmath',
        'scale' => 2,
        'mode' => 'half_up',
    ],

    // Displayed figures use the platform currency, never a client-supplied one.
    'currency' => (string) env('FINANCE_DEFAULT_CURRENCY', 'THB'),

    // Translation key carrying the mandatory "not a prediction" sentence.
    'disclaimer_key' => 'payout_illustration.disclaimer',

    // Translation key explaining a bet type that cannot be priced right now.
    'not_configured_key' => 'payout_illustration.not_configured_multiplier',

    // Translation key explaining that no deduction is applied to the figure.
    'net_basis_key' => 'payout_illustration.net_basis_no_deduction',

];
