<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Payout illustration — EN
|--------------------------------------------------------------------------
|
| Copy for the public payout illustration endpoint (how-to-play → simulate).
| An illustration states what a stake returns IF the selection matched, at the
| multiplier the platform publishes. It is never a prediction, never a
| probability, never a promise of winnings, and it never invents a rate: a bet
| type without a published rate answers NOT_CONFIGURED instead.
|
*/

return [
    // The mandatory sentence that must accompany every illustration.
    'disclaimer' => 'Illustration only: this shows what a matched selection would return at the published payout rate. It is not a prediction, not a probability and not a promise of winnings.',

    // A bet type whose published multiplier cannot be resolved right now.
    'not_configured_multiplier' => 'No single published payout rate exists for this bet type in this environment, so no illustration is shown. The rate must be published before a figure can be illustrated.',

    // What "net" means in the response.
    'net_basis_no_deduction' => 'No deduction is applied to this figure. Stamp duty and prize withholding apply only to GLO-compatible prize claims, not to a platform-market illustration.',

    // States.
    'state_ready' => 'Illustration available',
    'state_not_configured' => 'Not configured',

    // Derivation notes.
    'basis_multiplier_source' => 'Payout rate source: :source',
    'basis_rounding' => 'Calculated in exact decimal arithmetic and rounded half-up to :scale decimals.',

    // Validation messages.
    'invalid_amount' => 'Enter the stake as an amount with at most two decimal places.',
    'stake_above_maximum' => 'The stake must not exceed :max :currency.',
    'stake_below_minimum' => 'The stake must be at least :min :currency.',
    'stake_not_positive' => 'The stake must be greater than zero.',
    'unsupported_bet_type' => 'That bet type is not published on this platform.',
    'unsupported_locale' => 'That language is not published for this illustration.',

    // Response labels.
    'field_stake' => 'Stake',
    'field_multiplier' => 'Published payout rate',
    'field_gross_return' => 'Return if matched (gross)',
    'field_net_return' => 'Return if matched (net)',
    'field_currency' => 'Currency',
];
