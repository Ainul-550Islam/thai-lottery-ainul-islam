<?php

declare(strict_types=1);

namespace Tests\Support\LotteryGames;

/**
 * A cashback service whose reverse() does not match the call the listener makes: it
 * demands four values where the listener passes three. Used to prove the hook check
 * refuses a service that would fail at the first real cancellation.
 */
final class MisshapenCashbackService
{
    public function reverse(int $userId, int $betId, string $reason, string $extra): void
    {
    }
}
