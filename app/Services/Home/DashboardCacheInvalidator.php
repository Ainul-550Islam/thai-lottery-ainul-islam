<?php

declare(strict_types=1);

namespace App\Services\Home;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * SOLE owner of the dashboard-summary cache key and its invalidation.
 *
 * Invalidation is deferred until the surrounding transaction commits so a
 * concurrent reader can never re-cache pre-commit (stale) state, and a rolled
 * back transaction never evicts a still-valid entry. Outside a transaction,
 * DB::afterCommit runs the callback immediately.
 */
final class DashboardCacheInvalidator
{
    public function keyFor(User|int $member): string
    {
        $memberId = $member instanceof User ? (int) $member->getKey() : $member;
        $prefix = (string) config('rewards.dashboard.cache_prefix', 'player_dashboard_summary:v1');

        return sprintf('%s:member:%d', $prefix, $memberId);
    }

    public function forget(User|int $member): void
    {
        $key = $this->keyFor($member);

        DB::afterCommit(static function () use ($key): void {
            Cache::forget($key);
        });
    }
}
