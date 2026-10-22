<?php

declare(strict_types=1);

namespace App\Services\Observability;

use App\Console\Scheduling\ReconciliationCadence;
use App\Models\AuditLog;
use App\Services\Queue\QueueHealthService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Multi-Tier System Health, Liveness, and Readiness Assessment Service.
 *
 * Evaluates operational readiness, connectivity to core dependencies,
 * queue backlogs, failed jobs, and financial reconciliation freshness.
 */
class SystemHealthService
{
    public function __construct(
        private readonly QueueHealthService $queueHealth,
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * Liveness check: confirms application process is running.
     *
     * @return array{status: string, timestamp: string}
     */
    public function checkLiveness(): array
    {
        return [
            'status' => 'UP',
            'timestamp' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * Readiness check: validates connectivity to critical dependencies.
     *
     * @return array{ready: bool, database: bool, cache: bool, queue: bool, timestamp: string}
     */
    public function checkReadiness(): array
    {
        $dbOk = $this->pingDatabase();
        $cacheOk = $this->pingCache();
        $queueOk = $this->pingQueue();

        $allReady = $dbOk && $cacheOk && $queueOk;

        return [
            'ready' => $allReady,
            'database' => $dbOk,
            'cache' => $cacheOk,
            'queue' => $queueOk,
            'timestamp' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * Comprehensive health assessment with degraded state detection.
     *
     * @return array<string, mixed>
     */
    public function checkDetailedHealth(): array
    {
        $startTime = microtime(true);
        $warnings = [];
        $isDegraded = false;
        $isUnhealthy = false;

        // 1. Database Check
        $dbStart = microtime(true);
        $dbOk = $this->pingDatabase();
        $dbLatency = round((microtime(true) - $dbStart) * 1000, 2);

        if (! $dbOk) {
            $isUnhealthy = true;
            $warnings[] = 'Database is unreachable.';
        } elseif ($dbLatency > 500) {
            $isDegraded = true;
            $warnings[] = sprintf('High database query latency: %0.2f ms.', $dbLatency);
        }

        // 2. Cache Check
        $cacheStart = microtime(true);
        $cacheOk = $this->pingCache();
        $cacheLatency = round((microtime(true) - $cacheStart) * 1000, 2);

        if (! $cacheOk) {
            $isDegraded = true;
            $warnings[] = 'Cache storage is unreachable.';
        }

        // 3. Queue Check
        $queueReport = $this->queueHealth->check();
        if (! $queueReport->isHealthy) {
            $isDegraded = true;
            foreach ($queueReport->warnings as $w) {
                $warnings[] = $w;
            }
        }

        if ($queueReport->failedCount > 10) {
            $isDegraded = true;
            $warnings[] = sprintf('Excessive failed jobs backlog: %d failed jobs in queue.', $queueReport->failedCount);
        }

        // 4. Financial Reconciliation Freshness
        //
        // Evaluated only while something is scheduled to run the control. When
        // finance.ledger.auto_reconcile is off, the status says "disabled" and no
        // warning is added: a freshness alarm about a control an operator switched off
        // is not a finding about the platform, and it used to be loud -
        // ops:evaluate-alerts turns a "warn" here into a HIGH-severity audit row, so the
        // scheduled lane would have written one false incident per hour on any deployment
        // whose .env copies the shipped template, which sets FINANCE_LEDGER_AUTO_RECONCILE
        // to false. Silence in the warnings list is deliberate too: the list is what an
        // operator reads to decide what to act on, and "you turned this off" is not an
        // action, while a real staleness breach still is one.
        $reconciliationInfo = $this->checkReconciliationFreshness();
        if ($reconciliationInfo['enabled'] && $reconciliationInfo['is_stale']) {
            $isDegraded = true;
            $warnings[] = sprintf(
                'Financial reconciliation is stale (last run: %s, %s hours ago; bound %s hours = %dx the scheduled interval).',
                $reconciliationInfo['last_run_at'] ?? 'NEVER',
                $reconciliationInfo['staleness_hours'] ?? 'N/A',
                $reconciliationInfo['threshold_hours'],
                ReconciliationCadence::STALENESS_GRACE_FACTOR
            );
        }
        if ($reconciliationInfo['last_status'] === 'critical') {
            $isDegraded = true;
            $warnings[] = 'Last financial reconciliation reported CRITICAL anomalies.';
        }

        $overallStatus = $isUnhealthy ? 'UNHEALTHY' : ($isDegraded ? 'DEGRADED' : 'HEALTHY');

        return [
            'status' => $overallStatus,
            'timestamp' => Carbon::now()->toIso8601String(),
            'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'dependencies' => [
                'database' => [
                    'status' => $dbOk ? 'pass' : 'fail',
                    'latency_ms' => $dbLatency,
                ],
                'cache' => [
                    'status' => $cacheOk ? 'pass' : 'fail',
                    'latency_ms' => $cacheLatency,
                ],
                'queue' => [
                    'status' => $queueReport->isHealthy ? 'pass' : 'warn',
                    'driver' => $queueReport->driver,
                    'total_pending' => $queueReport->totalPending,
                    'failed_count' => $queueReport->failedCount,
                    'stuck_count' => $queueReport->stuckCount,
                ],
                'reconciliation' => [
                    'status' => ! $reconciliationInfo['enabled']
                        ? 'disabled'
                        : ($reconciliationInfo['is_stale'] ? 'warn' : 'pass'),
                    'enabled' => $reconciliationInfo['enabled'],
                    'threshold_hours' => $reconciliationInfo['threshold_hours'],
                    'last_run_at' => $reconciliationInfo['last_run_at'],
                    'staleness_hours' => $reconciliationInfo['staleness_hours'],
                    'last_status' => $reconciliationInfo['last_status'],
                ],
            ],
            'warnings' => $warnings,
        ];
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
            $testKey = 'health_ping_'.bin2hex(random_bytes(4));
            $this->cache->put($testKey, 'ok', 10);
            $val = $this->cache->get($testKey);
            $this->cache->forget($testKey);

            return $val === 'ok';
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

    /**
     * Is the ledger reconciliation control both switched on and actually running?
     *
     * Two numbers used to decide this and only one of them was real. The bound was a
     * literal 24.0 hours written here, while the cadence the same control schedules
     * itself on lives in finance.ledger.reconciliation_interval (hourly by default, and
     * the schedule honours it through ReconciliationCadence). A bound that is not derived
     * from the cadence it is watching cannot mean anything: at a 15-minute interval it
     * would call a 23-hour outage healthy, and at a monthly interval it would raise a
     * HIGH alert every hour about a control nobody expected to run today.
     *
     * So the bound comes from ReconciliationCadence::stalenessThresholdSeconds(), the
     * same class that turns the interval into the cron field, and the switch comes from
     * the same strict reading the schedule uses. Both halves of the freshness question
     * now have exactly one source.
     *
     * @return array{enabled: bool, is_stale: bool, last_run_at: ?string, staleness_hours: ?float, last_status: string, threshold_hours: float}
     */
    private function checkReconciliationFreshness(): array
    {
        $enabled = ReconciliationCadence::isAutoReconcileEnabled(
            $this->config->get('finance.ledger.auto_reconcile', false)
        );

        $thresholdHours = round(
            ReconciliationCadence::stalenessThresholdSeconds(
                $this->config->get('finance.ledger.reconciliation_interval', ReconciliationCadence::DEFAULT_INTERVAL_SECONDS)
            ) / 3600.0,
            2
        );

        if (! $enabled) {
            // Not stale, and not fresh either: unevaluated. Reporting pass here would be
            // the lie - it would tell an operator reading /api/health that the ledger had
            // been checked recently when nothing has checked it at all.
            return [
                'enabled' => false,
                'is_stale' => false,
                'last_run_at' => null,
                'staleness_hours' => null,
                'last_status' => 'disabled',
                'threshold_hours' => $thresholdHours,
            ];
        }

        try {
            if (! DB::getSchemaBuilder()->hasTable('audit_logs')) {
                return [
                    'enabled' => true,
                    'is_stale' => true,
                    'last_run_at' => null,
                    'staleness_hours' => null,
                    'last_status' => 'unknown',
                    'threshold_hours' => $thresholdHours,
                ];
            }

            $lastAudit = AuditLog::query()
                ->where('action', 'reconcile')
                ->latest('id')
                ->first();

            if (! $lastAudit instanceof AuditLog || $lastAudit->created_at === null) {
                return [
                    'enabled' => true,
                    'is_stale' => true,
                    'last_run_at' => null,
                    'staleness_hours' => null,
                    'last_status' => 'never_run',
                    'threshold_hours' => $thresholdHours,
                ];
            }

            $hours = round(abs(Carbon::now()->diffInMinutes($lastAudit->created_at)) / 60.0, 2);
            $metadata = is_array($lastAudit->metadata) ? $lastAudit->metadata : [];
            $status = (string) ($metadata['status'] ?? 'pass');

            return [
                'is_stale' => $hours > $thresholdHours,
                'enabled' => true,
                'last_run_at' => $lastAudit->created_at->toIso8601String(),
                'staleness_hours' => $hours,
                'last_status' => $status,
                'threshold_hours' => $thresholdHours,
            ];
        } catch (Throwable) {
            return [
                'enabled' => true,
                'is_stale' => true,
                'last_run_at' => null,
                'staleness_hours' => null,
                'last_status' => 'error',
                'threshold_hours' => $thresholdHours,
            ];
        }
    }
}
