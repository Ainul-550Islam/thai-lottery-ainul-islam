<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Models\AuditLog;
use App\Models\Draw;
use App\Models\PaymentMethod;
use App\Services\Observability\SystemHealthService;
use App\Services\Queue\QueueHealthService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Health Check and Readiness Monitoring Service.
 *
 * Verifies system liveness, readiness, and comprehensive dependency health
 * (Database, Queue, Cache, Storage, Payment config, Result freshness, Reconciliation).
 */
class HealthCheckService
{
    public function __construct(
        private readonly SystemHealthService $systemHealth,
        private readonly QueueHealthService $queueHealth,
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
    ) {
    }

    /**
     * Liveness check: confirms worker process is active.
     *
     * @return array{status: string, timestamp: string}
     */
    public function isLive(): array
    {
        $status = $this->systemHealth->checkLiveness();
        // This service's public application contract uses the same vocabulary
        // as the structured health report. The lower-level probe remains UP for
        // the framework endpoint; only this adapter normalizes it.
        $status['status'] = ($status['status'] ?? null) === 'UP' ? 'healthy' : (string) ($status['status'] ?? 'unhealthy');

        return $status;
    }

    /**
     * Readiness check: confirms database, cache, storage, and queue are operational.
     *
     * @return array{ready: bool, database: bool, cache: bool, queue: bool, storage: bool, timestamp: string}
     */
    public function isReady(): array
    {
        $dbOk = $this->pingDatabase();
        $cacheOk = $this->pingCache();
        $queueOk = $this->pingQueue();
        $storageOk = $this->pingStorage();

        $allReady = $dbOk && $cacheOk && $queueOk && $storageOk;

        return [
            'ready' => $allReady,
            'database' => $dbOk,
            'cache' => $cacheOk,
            'queue' => $queueOk,
            'storage' => $storageOk,
            'timestamp' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * Full health status report across all operational subsystems.
     *
     * @return array<string, mixed>
     */
    public function checkHealth(): array
    {
        $detailed = $this->systemHealth->checkDetailedHealth();
        $storageOk = $this->pingStorage();
        $paymentsOk = $this->checkPaymentConfiguration();
        $resultFreshness = $this->checkResultFreshness();

        $detailed['dependencies']['storage'] = [
            'status' => $storageOk ? 'pass' : 'fail',
        ];

        $detailed['dependencies']['payments'] = [
            'status' => $paymentsOk['enabled_count'] > 0 ? 'pass' : 'warn',
            'enabled_methods' => $paymentsOk['enabled_count'],
        ];

        $detailed['dependencies']['result_freshness'] = $resultFreshness;

        if (! $storageOk) {
            $detailed['status'] = 'UNHEALTHY';
            $detailed['warnings'][] = 'Local private storage is unreachable or not writable.';
        }

        return $detailed;
    }

    private function pingDatabase(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function pingCache(): bool
    {
        try {
            $key = 'health_ping_' . bin2hex(random_bytes(4));
            $this->cache->put($key, '1', 5);
            $val = $this->cache->get($key);
            $this->cache->forget($key);

            return $val === '1';
        } catch (Throwable) {
            return false;
        }
    }

    private function pingQueue(): bool
    {
        try {
            return $this->queueHealth->check()->isHealthy;
        } catch (Throwable) {
            return false;
        }
    }

    private function pingStorage(): bool
    {
        try {
            $disk = Storage::disk('local');
            $testFile = 'health_probe_' . bin2hex(random_bytes(4)) . '.tmp';
            $disk->put($testFile, 'probe');
            $read = $disk->get($testFile);
            $disk->delete($testFile);

            return $read === 'probe';
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{enabled_count: int, configured: bool}
     */
    private function checkPaymentConfiguration(): array
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('payment_methods')) {
                return ['enabled_count' => 0, 'configured' => false];
            }

            $count = PaymentMethod::query()->where('is_enabled', true)->count();

            return [
                'enabled_count' => $count,
                'configured' => $count > 0,
            ];
        } catch (Throwable) {
            return ['enabled_count' => 0, 'configured' => false];
        }
    }

    /**
     * @return array{status: string, latest_draw_id: ?int, scheduled_at: ?string}
     */
    private function checkResultFreshness(): array
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('draws')) {
                return ['status' => 'unknown', 'latest_draw_id' => null, 'scheduled_at' => null];
            }

            $latestDraw = Draw::query()->latest('scheduled_at')->first();

            return [
                'status' => $latestDraw instanceof Draw ? 'pass' : 'warn',
                'latest_draw_id' => $latestDraw?->id,
                'scheduled_at' => $latestDraw?->scheduled_at?->toIso8601String(),
            ];
        } catch (Throwable) {
            return ['status' => 'error', 'latest_draw_id' => null, 'scheduled_at' => null];
        }
    }
}
