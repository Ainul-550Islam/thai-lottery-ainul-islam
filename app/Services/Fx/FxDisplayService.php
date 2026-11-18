<?php

declare(strict_types=1);

namespace App\Services\Fx;

use Illuminate\Support\Carbon;

/**
 * Converts an amount for DISPLAY ONLY. Never for money.
 *
 * THE THREE ANSWERS
 *   1. no provider / no rate            -> null
 *   2. rate older than the max age      -> null  (stale is not "nearly right")
 *   3. a usable rate                    -> {amount, rate, captured_at,
 *                                          approximate: true, source}
 *
 * WHY NULL AND NOT A ZERO
 * A null makes a UI hide the block. A zero would render "$0.00" next to a real
 * balance, which a player would read as "my money is gone". There is no case in
 * which inventing a number is safer than saying nothing.
 *
 * WHY THE DIVISION IS BCMATH
 * amount / rate at scale 2, then bcadd of '0.00' to normalise the string. A float
 * division of 12345.67 by 36.5 produces 338.2375342465753, and the two-decimal
 * value that reaches the screen must be a stated rounding, not an accident.
 */
final class FxDisplayService
{
    public function __construct(
        private readonly FxRateProvider $provider,
    ) {
    }

    /**
     * @return array{amount: string, rate: string, base: string, quote: string, captured_at: string, approximate: bool, source: string}|null
     */
    public function display(string $amount, ?string $base = null, ?string $quote = null): ?array
    {
        $from = strtoupper($base ?? (string) config('player_experience.wallet.fx_default_base', 'THB'));
        $to = strtoupper($quote ?? (string) config('player_experience.wallet.fx_display_quote', 'USD'));

        if ($from === $to) {
            return null;
        }

        $rate = $this->provider->fetch($from, $to);

        if ($rate === null) {
            return null;
        }

        $maxAge = (int) config('player_experience.wallet.fx_max_age_minutes', 1440);

        if ($maxAge > 0 && $rate->capturedAt->lessThan(Carbon::now()->subMinutes($maxAge))) {
            // Stale: the honest answer is "we do not know today's rate".
            return null;
        }

        if (bccomp($rate->rate, '0', 12) <= 0) {
            // A zero or negative rate is corrupt data, not a very good deal.
            return null;
        }

        $converted = bcdiv($amount, $rate->rate, 2);

        return [
            'amount' => bcadd($converted, '0.00', 2),
            'rate' => $rate->rate,
            'base' => $rate->base,
            'quote' => $rate->quote,
            'captured_at' => $rate->capturedAt->toIso8601String(),
            'approximate' => true,
            'source' => $rate->source,
        ];
    }

    /**
     * Whether the display block should be rendered at all. The controller uses
     * this so a disabled feature costs no query.
     */
    public function enabled(): bool
    {
        return (bool) config('player_experience.wallet.fx_display_enabled', false);
    }

    public function source(): string
    {
        return $this->provider->source();
    }
}
