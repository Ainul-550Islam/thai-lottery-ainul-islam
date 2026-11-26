<?php

declare(strict_types=1);

namespace App\Services\Fx;

use App\DTOs\Fx\FxRate;
use App\Models\FxRateSnapshot;
use Illuminate\Support\Carbon;

/**
 * Reads the newest admin-entered rate for a pair.
 *
 * WHAT "MANUAL" MEANS HERE
 * A human with a contractual rate types it into the Filament resource. This
 * provider surfaces it with its capture time and the identifier of the source
 * row, so the screen can say how old the number is. It does not interpolate, it
 * does not average, and it does not fall back to a hardcoded rate.
 *
 * FRESHNESS IS NOT THIS CLASS'S JOB
 * It returns the row it found; FxDisplayService applies the configured maximum
 * age and returns null when the row is too old. Splitting the two means the
 * provider can be swapped without moving the staleness policy, and the policy
 * lives in one place (config + one service) instead of two.
 */
final class ManualFxRateProvider implements FxRateProvider
{
    public function __construct(
        private readonly ?Carbon $now = null,
    ) {
    }

    public function fetch(string $base, string $quote): ?FxRate
    {
        $moment = $this->now ?? Carbon::now();

        /** @var FxRateSnapshot|null $snapshot */
        $snapshot = FxRateSnapshot::query()
            ->forPair($base, $quote)
            ->usableAt($moment)
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->first();

        if (! $snapshot instanceof FxRateSnapshot) {
            return null;
        }

        return new FxRate(
            base: strtoupper($snapshot->base),
            quote: strtoupper($snapshot->quote),
            rate: (string) $snapshot->rate,
            source: $snapshot->source,
            capturedAt: $snapshot->captured_at,
        );
    }

    public function source(): string
    {
        return 'manual';
    }
}
