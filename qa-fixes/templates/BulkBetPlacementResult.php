<?php

declare(strict_types=1);

namespace App\DTOs\Betting;

use App\Models\Bet;
use Illuminate\Support\Collection;

/**
 * Result of placing one bulk slip through BulkBetService::placeBulkBet().
 *
 * `bets` holds the rows the existing purchase pipeline created or replayed.
 * `totalStake` counts only newly charged stakes, so an idempotent replay of the
 * same slip reports 0.00 and never debits the wallet twice.
 * `summary` is the full per-item breakdown returned by BulkBetService::purchase().
 */
final readonly class BulkBetPlacementResult
{
    /**
     * @param  Collection<int, Bet>  $bets
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public Collection $bets,
        public string $totalStake,
        public array $summary,
    ) {}
}
