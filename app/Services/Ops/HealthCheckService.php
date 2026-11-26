<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Services\Observability\SystemHealthService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * /health/live and /health/ready, for a load balancer.
 *
 * WHY THIS EXISTS NEXT TO SystemHealthService
 * The existing SystemHealthService answers a richer question for operators (queue
 * depth, reconciliation state, dependency detail). A load balancer needs a binary
 * answer and a status code, and it calls every ten seconds from every node. So this
 * service COMPOSES the existing one where it is useful (db/cache/queue) and adds
 * the two things a probe needs and the old surface does not model: the scheduler
 * heartbeat and a stable, minimal body.
 *
 * WHAT IS NEVER RETURNED
 * No configuration values, no DSNs, no credentials, no member data, no counts of
 * people or money. Each check reports `ok: true|false` and, on failure, a short
 * reason from a closed vocabulary. HealthEndpointTest asserts the body contains no
 * configured secret and no email-address-shaped string.
 *
 * READY vs LIVE
 *   live   the process answers at all. It touches nothing external, so it cannot
 *          fail because a dependency is down — restarting the process would not
 *          help with that, and a liveness probe that fails on a dependency turns a
 *          database incident into a cluster-wide restart loop.
 *   ready  everything needed to serve traffic is available: db, cache, queue and a
 *          fresh scheduler heartbeat. Ready returns 503 when it is not, which is
 *          what takes the node out of rotation without killing it.
 */
final class HealthCheckService
{
    public function __construct(
        private readonly SchedulerHeartbeat $heartbeat,
        private readonly SystemHealthService $existing,
    ) {
    }

    /**
     * @return array{status:string, timestamp:string}
     */
    public function live(?Carbon $at = null): array
    {
        return [
            'status' => 'UP',
            'timestamp' => ($at ?? Carbon::now())->toIso8601String(),
        ];
    }

    /**
     * @return array{ready:bool, status:string, checks:array<string, array{ok:bool, reason:string|null}>, timestamp:string}
     */
    public function ready(?Carbon $at = null): array
    {
        $now = $at ?? Carbon::now();

        $checks = [
            'database' => $this->probeDatabase(),
            'cache' => $this->probeCache(),
            'queue' => $this->probeQueue(),
            'scheduler' => $this->probeScheduler($now),
            'payment_provider_config' => $this->probeProviderConfig(
                (string) config('payment.default_gateway', ''),
                true,
            ),
            'result_provider_config' => $this->probeProviderConfig(
                // config('lottery.results') declares a confirmation policy, not a
                // provider name. The provider is named by the GLO result-provider
                // chain, so the probe reads the configured chain head and treats
                // an absent chain as "not configured" rather than passing.
                (string) config('glo.result_provider', config('glo.provider', '')),
                true,
            ),
        ];

        $ready = true;

        foreach ($checks as $check) {
            if (! $check['ok']) {
                $ready = false;
                break;
            }
        }

        return [
            'ready' => $ready,
            'status' => $ready ? 'READY' : 'NOT_READY',
            'checks' => $checks,
            'timestamp' => $now->toIso8601String(),
        ];
    }

    /**
     * @return array{ok:bool, reason:string|null}
     */
    private function probeDatabase(): array
    {
        try {
            DB::connection()->select('select 1');

            return ['ok' => true, 'reason' => null];
        } catch (\Throwable) {
            return ['ok' => false, 'reason' => 'database_unreachable'];
        }
    }

    /**
     * @return array{ok:bool, reason:string|null}
     */
    private function probeCache(): array
    {
        try {
            $key = 'ops:health:probe:'.($now = Carbon::now()->getTimestamp());
            \Illuminate\Support\Facades\Cache::put($key, 1, 10);

            return ['ok' => \Illuminate\Support\Facades\Cache::get($key) === 1, 'reason' => null];
        } catch (\Throwable) {
            return ['ok' => false, 'reason' => 'cache_unreachable'];
        }
    }

    /**
     * @return array{ok:bool, reason:string|null}
     */
    private function probeQueue(): array
    {
        $connection = (string) config('queue.default', 'sync');

        // The sync driver cannot back up, so it is ready by definition. Reporting
        // it as unhealthy would fail every local and CI environment.
        if ($connection === 'sync') {
            return ['ok' => true, 'reason' => null];
        }

        try {
            Queue::connection($connection)->size();

            return ['ok' => true, 'reason' => null];
        } catch (\Throwable) {
            return ['ok' => false, 'reason' => 'queue_unreachable'];
        }
    }

    /**
     * @return array{ok:bool, reason:string|null, age_seconds:int|null}
     */
    private function probeScheduler(Carbon $now): array
    {
        $age = $this->heartbeat->ageSeconds($now);

        if ($age === null) {
            return ['ok' => false, 'reason' => 'scheduler_heartbeat_missing', 'age_seconds' => null];
        }

        $stale = max(1, (int) config('player_experience.health.scheduler_stale_seconds', 180));

        return [
            'ok' => $age <= $stale,
            'reason' => $age <= $stale ? null : 'scheduler_heartbeat_stale',
            'age_seconds' => $age,
        ];
    }

    /**
     * Whether a critical provider is configured — the FACT of a value being
     * present, never the value itself.
     *
     * A readiness probe that echoed a key would put a credential in every load
     * balancer log and every screenshot of a status page.
     *
     * @return array{ok:bool, reason:string|null}
     */
    private function probeProviderConfig(string $value, bool $required): array
    {
        if (! $required) {
            return ['ok' => true, 'reason' => null];
        }

        if (trim($value) === '') {
            return ['ok' => false, 'reason' => 'provider_not_configured'];
        }

        return ['ok' => true, 'reason' => null];
    }
}
