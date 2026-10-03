<?php

declare(strict_types=1);

namespace App\DTOs\Betting;

use App\Models\Bet;

/** Compatibility projection over the canonical bulk purchase report. */
final readonly class LegacyBulkBetResult
{
    /** @param list<Bet> $bets */
    public function __construct(public array $bets, public string $totalStake) {}
}
