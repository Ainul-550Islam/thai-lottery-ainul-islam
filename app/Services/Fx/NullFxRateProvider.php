<?php

declare(strict_types=1);

namespace App\Services\Fx;

use App\DTOs\Fx\FxRate;

/**
 * The default provider: no rate, no network, no surprises.
 *
 * WHY THE DEFAULT IS "NOTHING"
 * Batch 3 forbids outbound calls by default. A provider that silently reached
 * the internet would make the wallet screen depend on a third party's uptime and
 * would put a foreign number in front of a player without the operator ever
 * choosing a source. Returning null makes the feature visibly absent instead of
 * quietly wrong — the UI renders "no display rate configured", which is the
 * truth.
 *
 * Switching to a real source is a deliberate act: set PLAYER_FX_DRIVER=manual and
 * enter a rate in the Filament resource, which records who entered it and when.
 */
final class NullFxRateProvider implements FxRateProvider
{
    public function fetch(string $base, string $quote): ?FxRate
    {
        return null;
    }

    public function source(): string
    {
        return 'null';
    }
}
