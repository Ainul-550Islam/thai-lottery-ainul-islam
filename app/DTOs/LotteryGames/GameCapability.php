<?php

declare(strict_types=1);

namespace App\DTOs\LotteryGames;

/**
 * The fail-closed sellability verdict for one game.
 *
 * Same shape as GloL6PurchaseCapabilityService's return value on purpose: an
 * operator already knows how to read status/enabled/reason/missing/gates, and
 * two different shapes for the same question is how a dashboard ends up
 * showing "enabled" for a game that cannot be sold.
 *
 * status is NOT_CONFIGURED unless every gate in `gates` is true. `missing`
 * names the human-readable requirement for each false gate, so the API and the
 * Filament page never have to invent an explanation.
 */
final readonly class GameCapability
{
    public const STATUS_NOT_CONFIGURED = 'NOT_CONFIGURED';

    public const STATUS_CONFIGURED = 'CONFIGURED';

    public const STATUS_SUSPENDED = 'SUSPENDED';

    /**
     * @param  list<string>  $missing
     * @param  array<string, bool>  $gates
     */
    public function __construct(
        public string $status,
        public bool $enabled,
        public string $reason,
        public array $missing = [],
        public array $gates = [],
    ) {}

    public function isSellable(): bool
    {
        return $this->enabled === true && $this->status === self::STATUS_CONFIGURED;
    }

    /**
     * @return array{status: string, enabled: bool, reason: string, missing: list<string>, gates: array<string, bool>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'enabled' => $this->enabled,
            'reason' => $this->reason,
            'missing' => $this->missing,
            'gates' => $this->gates,
        ];
    }
}
