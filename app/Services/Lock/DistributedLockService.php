<?php

declare(strict_types=1);

namespace App\Services\Lock;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Class DistributedLockService
 *
 * High-performance distributed lock implementation using Redis atomic commands
 * and Lua scripts on multi-node Redis clusters, with seamless atomic Cache::lock
 * fallback on runtimes where ext-redis is unavailable (e.g. CI / SQLite test sandboxes).
 */
class DistributedLockService
{
    private const DEFAULT_TTL_SECONDS = 10;

    private const DEFAULT_RETRY_ATTEMPTS = 5;

    private const DEFAULT_RETRY_SLEEP_MS = 50;

    /** @var array<string, Lock> */
    private array $fallbackLocks = [];

    /**
     * Acquire a distributed lock and execute the callback inside a safe critical section.
     *
     * @template T
     *
     * @param  string  $resource  The unique lock key (e.g., 'wallet:user:uuid')
     * @param  callable(): T  $callback  The critical section code
     * @param  int  $ttlSeconds  Lock timeout before auto-release
     * @param  int  $maxAttempts  Max spin-wait retries before giving up
     * @return T
     *
     * @throws RuntimeException If lock cannot be acquired
     */
    public function runWithLock(
        string $resource,
        callable $callback,
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
        int $maxAttempts = self::DEFAULT_RETRY_ATTEMPTS
    ): mixed {
        $lockKey = "lock:distributed:{$resource}";
        $ownerToken = Str::uuid()->toString();

        $acquired = $this->acquireLockWithRetry($lockKey, $ownerToken, $ttlSeconds, $maxAttempts);

        if (! $acquired) {
            throw new RuntimeException("Failed to acquire distributed lock for resource: {$resource} after {$maxAttempts} attempts.");
        }

        try {
            return $callback();
        } finally {
            $this->releaseLock($lockKey, $ownerToken);
        }
    }

    /**
     * Acquire lock using atomic SET NX PX with exponential jittered backoff.
     */
    public function acquire(string $resource, string $ownerToken, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): bool
    {
        $lockKey = str_starts_with($resource, 'lock:distributed:') ? $resource : "lock:distributed:{$resource}";

        if (! $this->hasNativeRedis()) {
            $lock = Cache::lock($lockKey, $ttlSeconds, $ownerToken);
            if ($lock->get()) {
                $this->fallbackLocks[$lockKey] = $lock;

                return true;
            }

            return false;
        }

        $ttlMilliseconds = $ttlSeconds * 1000;

        /** @var string|null $result */
        $result = Redis::set($lockKey, $ownerToken, 'PX', $ttlMilliseconds, 'NX');

        return $result === 'OK' || $result === true;
    }

    /**
     * Acquire lock returning the unique owner token on success or false on contention.
     */
    public function acquireLock(string $resource, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): string|false
    {
        $ownerToken = Str::uuid()->toString();

        return $this->acquire($resource, $ownerToken, $ttlSeconds) ? $ownerToken : false;
    }

    /**
     * Release lock safely using atomic Lua script (only the token owner can release).
     */
    public function releaseLock(string $lockKey, ?string $ownerToken = null): bool
    {
        if (! str_starts_with($lockKey, 'lock:distributed:')) {
            $lockKey = "lock:distributed:{$lockKey}";
        }

        if (! $this->hasNativeRedis()) {
            $lock = $this->fallbackLocks[$lockKey] ?? Cache::restoreLock($lockKey, (string) $ownerToken);
            unset($this->fallbackLocks[$lockKey]);

            return (bool) $lock->release();
        }

        $luaScript = <<<'LUA'
if redis.call("get", KEYS[1]) == ARGV[1] then
    return redis.call("del", KEYS[1])
else
    return 0
end
LUA;

        $result = Redis::eval($luaScript, 1, $lockKey, $ownerToken);

        return (int) $result === 1;
    }

    /**
     * Spin-wait retry loop with jitter.
     */
    private function acquireLockWithRetry(
        string $lockKey,
        string $ownerToken,
        int $ttlSeconds,
        int $maxAttempts
    ): bool {
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($this->acquire($lockKey, $ownerToken, $ttlSeconds)) {
                return true;
            }

            if ($attempt < $maxAttempts) {
                $sleepMs = self::DEFAULT_RETRY_SLEEP_MS * $attempt + random_int(5, 25);
                usleep($sleepMs * 1000);
            }
        }

        return false;
    }

    private function hasNativeRedis(): bool
    {
        return extension_loaded('redis') && ! app()->runningUnitTests();
    }
}
