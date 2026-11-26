<?php

declare(strict_types=1);

namespace App\Services\Ops;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * A one-minute "the scheduler is alive" stamp, and its reader.
 *
 * WHY THE CACHE AND NOT A TABLE
 * The heartbeat is worthless if writing it can fail while the database is down —
 * which is exactly the incident it must survive. The cache store is a different
 * dependency, and a stale heartbeat there is a true signal. It also costs nothing:
 * one key, overwritten every minute.
 *
 * WHAT IT CARRIES
 * A UTC ISO-8601 timestamp and the hostname. No member id, no balance, no
 * configuration value, no secret. The payload is deliberately boring because
 * /health/ready returns part of it to an unauthenticated caller.
 *
 * FAIL-OPEN ON WRITE, FAIL-CLOSED ON READ
 * If the cache is unavailable the write throws where the scheduled command can see
 * it (an operator must know the heartbeat stopped). The read, by contrast, returns
 * null and the readiness probe treats null as NOT ready — an unreadable heartbeat
 * is not evidence that the scheduler is running.
 */
final class SchedulerHeartbeat
{
    public function beat(?Carbon $at = null): void
    {
        $key = $this->key();
        $ttl = max(60, (int) config('player_experience.health.heartbeat_ttl_seconds', 900));

        Cache::put($key, [
            'at' => ($at ?? Carbon::now())->toIso8601String(),
            'host' => gethostname() ?: 'unknown',
        ], $ttl);
    }

    /**
     * The stored heartbeat, or null when there is none.
     *
     * @return array{at:string, host:string}|null
     */
    public function read(): ?array
    {
        try {
            $value = Cache::get($this->key());
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($value) || ! isset($value['at']) || ! is_string($value['at'])) {
            return null;
        }

        return [
            'at' => $value['at'],
            'host' => is_string($value['host'] ?? null) ? $value['host'] : 'unknown',
        ];
    }

    /**
     * Age in seconds, or null when there is no heartbeat to age.
     */
    public function ageSeconds(?Carbon $at = null): ?int
    {
        $beat = $this->read();

        if ($beat === null) {
            return null;
        }

        try {
            $moment = Carbon::parse($beat['at']);
        } catch (\Throwable) {
            return null;
        }

        return max(0, ($at ?? Carbon::now())->getTimestamp() - $moment->getTimestamp());
    }

    public function isFresh(?Carbon $at = null): bool
    {
        $age = $this->ageSeconds($at);

        if ($age === null) {
            return false;
        }

        return $age <= max(1, (int) config('player_experience.health.scheduler_stale_seconds', 180));
    }

    public function key(): string
    {
        return (string) config('player_experience.health.heartbeat_cache_key', 'ops:scheduler:heartbeat');
    }
}
