<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Services\Ops\HealthCheckService;
use Illuminate\Http\JsonResponse;

/**
 * GET /health/live and GET /health/ready — for a load balancer.
 *
 * WHY A NEW CONTROLLER AND NOT MORE METHODS ON THE EXISTING HealthController
 * The existing controller (/up/*, /api/health) serves operators and already has a
 * token-gated detail mode. These two probes are unauthenticated by design (a load
 * balancer has no token), so their body must be minimal and their code path
 * separate. Keeping them apart means a future change to the operator surface
 * cannot accidentally widen what an anonymous caller can see.
 *
 * WHAT IS IN THE BODY
 * A status word, a timestamp, and per-check `ok` booleans with a closed-vocabulary
 * reason. No configuration values, no DSNs, no credentials, no counts of members or
 * money. HealthEndpointTest asserts that.
 */
final class PlayerHealthController
{
    public function __construct(
        private readonly HealthCheckService $health,
    ) {
    }

    /**
     * Liveness: the process answers. Never touches a dependency, so a database
     * incident cannot turn into a cluster-wide restart loop.
     */
    public function live(): JsonResponse
    {
        return new JsonResponse($this->health->live(), 200);
    }

    /**
     * Readiness: db + cache + queue + fresh scheduler heartbeat + provider config.
     * 503 when any check fails, which is what takes the node out of rotation
     * without killing it.
     */
    public function ready(): JsonResponse
    {
        $report = $this->health->ready();

        return new JsonResponse($report, $report['ready'] ? 200 : 503);
    }
}
