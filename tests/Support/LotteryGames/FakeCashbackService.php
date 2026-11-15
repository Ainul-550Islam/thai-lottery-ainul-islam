<?php

declare(strict_types=1);

namespace Tests\Support\LotteryGames;

/**
 * A stand-in for the Batch-1 cashback service, with the one method the cancellation
 * listener calls. It records calls so a test can assert what was reversed.
 */
final class FakeCashbackService
{
    /** @var list<array{user_id: int, bet_id: int, reason: string}> */
    public static array $reversals = [];

    public function reverse(int $userId, int $betId, string $reason): void
    {
        self::$reversals[] = ['user_id' => $userId, 'bet_id' => $betId, 'reason' => $reason];
    }
}
