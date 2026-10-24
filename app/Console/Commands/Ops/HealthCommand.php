<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Services\Observability\SystemHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * `ops:health` — the probe an external monitor runs, and the one an operator
 * runs during an incident.
 *
 * WHY IT EXISTS WHEN /up/ready ALREADY DOES
 * -----------------------------------------
 * /up/ready answers "should traffic be sent here?" with three booleans. That is
 * the right answer for a load balancer and the wrong answer for a human, and it
 * cannot see the two operational facts that actually cause lottery incidents:
 *
 *   * the scheduler stopped firing (nothing is scheduled, so no job fails, so
 *     every probe stays green while results never settle);
 *   * the last verified backup is old (the database is healthy right up to the
 *     moment it is not).
 *
 * So this command adds a scheduler heartbeat, backup age, disk headroom and
 * failed-job pressure to the same dependency checks, and it is deliberately
 * usable over SSH without an HTTP request.
 *
 * Exit codes: 0 OK, 1 DEGRADED, 2 CRITICAL.
 */
class HealthCommand extends Command
{
    protected $signature = 'ops:health
        {--json : Machine-readable output}
        {--max-backup-age-hours=26 : Age after which the newest verified backup is stale}
        {--max-heartbeat-minutes=10 : Age after which the scheduler heartbeat is stale}';

    protected $description = 'Report dependency, queue, scheduler-heartbeat, backup-age and disk health with monitoring-friendly exit codes.';

    public function handle(SystemHealthService $health): int
    {
        $json = (bool) $this->option('json');
        $checks = [];
        $critical = false;
        $degraded = false;

        // ── Dependencies (shared with /up/ready, so the two cannot disagree) ──
        $readiness = $health->checkReadiness();

        $checks[] = $this->check('database', (bool) $readiness['database'], (bool) $readiness['database'] ? 'reachable' : 'unreachable');
        $checks[] = $this->check('cache', (bool) $readiness['cache'], (bool) $readiness['cache'] ? 'reachable' : 'unreachable');
        $checks[] = $this->check('queue_connection', (bool) $readiness['queue'], (bool) $readiness['queue'] ? 'reachable' : 'unreachable');

        foreach ($checks as $entry) {
            $critical = $critical || $entry['status'] === 'CRITICAL';
        }

        // ── Redis, when it is load-bearing ────────────────────────────────────
        try {
            Redis::connection()->ping();
            $checks[] = $this->check('redis', true, 'PONG');
        } catch (Throwable $error) {
            // Note the session driver: an app that stores sessions in Redis is
            // just as broken without it as one that queues there, and the
            // preflight already classifies it that way.
            $usesRedis = (string) config('cache.default') === 'redis'
                || (string) config('queue.default') === 'redis'
                || (string) config('session.driver') === 'redis';

            if ($usesRedis) {
                $checks[] = ['name' => 'redis', 'status' => 'CRITICAL', 'detail' => 'not reachable'];
                $critical = true;
            } else {
                // Reporting a dependency the application does not use as
                // DEGRADED makes every local run and every CI run "unhealthy",
                // which is how a real outage gets ignored. NOT_CONFIGURED is
                // visible, accurate, and does not fail the aggregate.
                $checks[] = [
                    'name' => 'redis',
                    'status' => 'NOT_CONFIGURED',
                    'detail' => 'not reachable, but queue, cache and sessions do not use Redis',
                ];
            }
        }

        // ── Failed jobs ───────────────────────────────────────────────────────
        try {
            $failed = (int) DB::table('failed_jobs')->count();
            $threshold = (int) config('ops.thresholds.failed_jobs_degraded', 10);

            $checks[] = $failed > $threshold
                ? ['name' => 'failed_jobs', 'status' => 'DEGRADED', 'detail' => $failed.' failed jobs']
                : $this->check('failed_jobs', true, $failed.' failed jobs');

            $degraded = $degraded || $failed > $threshold;
        } catch (Throwable) {
            $checks[] = $this->check('failed_jobs', true, 'not applicable (no failed_jobs table)');
        }

        // ── Scheduler heartbeat ───────────────────────────────────────────────
        $heartbeat = $this->schedulerHeartbeatAgeMinutes();
        $maxHeartbeat = (int) $this->option('max-heartbeat-minutes');

        if ($heartbeat === null) {
            // A missing heartbeat on a fresh deployment is expected; a missing
            // heartbeat on a live one means the scheduler never started. Only
            // the second is a health signal, so the severity follows APP_ENV.
            $production = in_array((string) config('app.env'), ['production', 'prod'], true);

            $checks[] = [
                'name' => 'scheduler_heartbeat',
                'status' => $production ? 'DEGRADED' : 'NOT_CONFIGURED',
                'detail' => $production
                    ? 'never recorded — the scheduler is not running'
                    : 'never recorded (expected outside production; run `php artisan schedule:work`)',
            ];

            $degraded = $degraded || $production;
        } elseif ($heartbeat > $maxHeartbeat) {
            $checks[] = [
                'name' => 'scheduler_heartbeat',
                'status' => 'CRITICAL',
                'detail' => sprintf('%d minutes stale (limit %d)', $heartbeat, $maxHeartbeat),
            ];
            $critical = true;
        } else {
            $checks[] = $this->check('scheduler_heartbeat', true, $heartbeat.' minutes ago');
        }

        // ── Backup age ────────────────────────────────────────────────────────
        $backup = $this->newestBackup();
        $maxAge = (int) $this->option('max-backup-age-hours');

        if ($backup === null) {
            $checks[] = ['name' => 'backup_age', 'status' => 'DEGRADED', 'detail' => 'no verified archive found'];
            $degraded = true;
        } elseif ($backup['age_hours'] > $maxAge) {
            $checks[] = [
                'name' => 'backup_age',
                'status' => 'DEGRADED',
                'detail' => sprintf('newest archive is %d hours old (limit %d)', $backup['age_hours'], $maxAge),
            ];
            $degraded = true;
        } else {
            $checks[] = $this->check('backup_age', true, sprintf('%d hours old, SHA-256 recorded', $backup['age_hours']));
        }

        // ── Disk headroom ─────────────────────────────────────────────────────
        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());

        if (is_float($free) && is_float($total) && $total > 0) {
            $percentFree = ($free / $total) * 100;

            if ($percentFree < 5.0) {
                $checks[] = ['name' => 'disk_space', 'status' => 'CRITICAL', 'detail' => sprintf('%.1f%% free', $percentFree)];
                $critical = true;
            } elseif ($percentFree < 15.0) {
                $checks[] = ['name' => 'disk_space', 'status' => 'DEGRADED', 'detail' => sprintf('%.1f%% free', $percentFree)];
                $degraded = true;
            } else {
                $checks[] = $this->check('disk_space', true, sprintf('%.1f%% free', $percentFree));
            }
        } else {
            $checks[] = ['name' => 'disk_space', 'status' => 'DEGRADED', 'detail' => 'could not be read'];
            $degraded = true;
        }

        $status = $critical ? 'CRITICAL' : ($degraded ? 'DEGRADED' : 'OK');

        if ($json) {
            $this->line((string) json_encode([
                'status' => $status,
                'checks' => $checks,
                'backup' => $backup,
                'timestamp' => gmdate('c'),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->newLine();

            foreach ($checks as $entry) {
                $badge = match ($entry['status']) {
                    'OK' => '<fg=green>OK  </>',
                    'DEGRADED' => '<fg=yellow>WARN</>',
                    'NOT_CONFIGURED' => '<fg=gray>n/a </>',
                    default => '<fg=red>CRIT</>',
                };

                $this->line(sprintf('  %s  %-22s %s', $badge, $entry['name'], $entry['detail']));
            }

            $this->newLine();
            $status === 'OK'
                ? $this->components->info('Overall: OK')
                : $this->components->warn('Overall: '.$status);
        }

        return $critical ? 2 : ($degraded ? 1 : 0);
    }

    /**
     * @return array{name: string, status: string, detail: string}
     */
    private function check(string $name, bool $ok, string $detail): array
    {
        return ['name' => $name, 'status' => $ok ? 'OK' : 'CRITICAL', 'detail' => $detail];
    }

    private function schedulerHeartbeatAgeMinutes(): ?int
    {
        $path = storage_path('framework/schedule-heartbeat');

        if (! is_file($path)) {
            return null;
        }

        $recorded = (int) trim((string) file_get_contents($path));

        if ($recorded <= 0) {
            return null;
        }

        return (int) floor((time() - $recorded) / 60);
    }

    /**
     * @return array{archive: string, created_at: string, age_hours: int, sha256: string}|null
     */
    private function newestBackup(): ?array
    {
        $directory = (string) config('backup.path', storage_path('app/backups'));
        $pointer = rtrim($directory, '/').'/latest.json';

        if (! is_file($pointer)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($pointer), true);

        if (! is_array($decoded) || ($decoded['verified'] ?? false) !== true) {
            return null;
        }

        $createdAt = strtotime((string) ($decoded['created_at'] ?? ''));

        if ($createdAt === false) {
            return null;
        }

        return [
            'archive' => (string) ($decoded['archive'] ?? 'unknown'),
            'created_at' => (string) $decoded['created_at'],
            'age_hours' => (int) floor((time() - $createdAt) / 3600),
            'sha256' => (string) ($decoded['sha256'] ?? ''),
        ];
    }
}
