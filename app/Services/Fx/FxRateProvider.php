<?php

declare(strict_types=1);

namespace App\Services\Fx;

use App\DTOs\Fx\FxRate;

/**
 * The one interface a display exchange rate may arrive through.
 *
 * WHY AN INTERFACE FOR A NUMBER NOBODY ACTS ON
 * Because the alternative is an `env()` read scattered through a service, and
 * the next person to need a rate would add a second one. With a driver, "where
 * does this number come from" has exactly one answer, and swapping the answer is
 * a config change rather than an edit on the display path.
 *
 * CONTRACT
 *   - fetch() is a pure read. It performs no write and, in the shipped drivers,
 *     no network call.
 *   - It returns null when it has no rate. It NEVER returns a guessed, defaulted
 *     or remembered number: a null renders as "no rate available", which is true.
 *   - Implementations must be safe to call on every request.
 *
 * NOT FOR MONEY
 * No wallet, ledger, conversion or payout code may depend on this interface.
 * The rate it returns is approximate by construction and is labelled
 * `approximate: true` in every payload that carries it.
 */
interface FxRateProvider
{
    /**
     * @return FxRate|null null when no rate is known
     */
    public function fetch(string $base, string $quote): ?FxRate;

    /**
     * A stable identifier for the driver, reported in the payload so a client
     * (and an incident review) can see where a number came from.
     */
    public function source(): string;
}
