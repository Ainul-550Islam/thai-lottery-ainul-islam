<?php

declare(strict_types=1);

namespace App\Services\ResponsibleGaming;

use App\Exceptions\BetPurchaseException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Shared player mutex for supported wager admission and versioned policy writers. */
final class ResponsibleGamingPlayerLock
{
    public function lock(int $userId): User
    {
        if (DB::transactionLevel() < 1 || ! DB::connection()->getPdo()->inTransaction()) {
            throw BetPurchaseException::invariantViolated('Player policy locking requires the caller transaction.');
        }
        $user = User::query()->whereKey($userId)->lockForUpdate()->first();
        if (! $user) {
            throw BetPurchaseException::userUnavailable($userId, 'the current account is unavailable');
        }

        return $user;
    }

    /** An unlocked owner hint never authorizes a policy mutation; recheck under its row lock. */
    public function forRecord(string $table, int $id): int
    {
        if (! in_array($table, ['self_exclusions', 'responsible_gaming_limit_versions'], true)) {
            throw BetPurchaseException::invariantViolated('Unsupported player policy record.');
        }
        $owner = DB::table($table)->useWritePdo()->where('id', $id)->value('user_id');
        $this->lock((int) $owner);

        return (int) $owner;
    }

    public function assertOwner(int $expected, int $actual): void
    {
        if ($expected !== $actual) {
            throw BetPurchaseException::invariantViolated('Player policy ownership changed while waiting.');
        }
    }
}
