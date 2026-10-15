<?php

declare(strict_types=1);

namespace App\DTOs\Betting;

use App\Models\Bet;

/**
 * Object-shaped outcome retained for consumers that project a bulk purchase report.
 *
 * The array-shaped report of purchase() answers "what happened to each item"
 * for the API lane; this DTO answers the caller holding models: the bets that
 * now exist (whether purchased by THIS call or already present from an
 * idempotent replay) and the money THIS call moved. On a replay, bets is
 * still the full set — the caller asked for those bets and they exist — while
 * totalStake is '0.00', because a replay moves no money and reporting
 * otherwise would double-count revenue on every retry.
 */
final readonly class BulkBetPlacementResult
{
    /**
     * @param  list<Bet>  $bets  every bet the slip owns, purchased or replayed
     * @param  list<array<string, mixed>>  $items  the per-item report, in request order
     */
    public function __construct(
        public array $bets,
        public string $totalStake,
        public int $purchased,
        public int $replayed,
        public int $refused,
        public string $currency,
        public array $items = [],
    ) {}

    /**
     * Whether every selection the caller asked for exists as a bet.
     */
    public function isComplete(): bool
    {
        return $this->refused === 0 && count($this->bets) === ($this->purchased + $this->replayed);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'bets' => array_map(static fn (Bet $bet): int => (int) $bet->getKey(), $this->bets),
            'total_stake' => $this->totalStake,
            'purchased' => $this->purchased,
            'replayed' => $this->replayed,
            'refused' => $this->refused,
            'currency' => $this->currency,
            'items' => $this->items,
        ];
    }
}
