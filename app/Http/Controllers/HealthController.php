<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Observability\SystemHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Health, Liveness & Readiness Probe Endpoints.
 *
 * Provides non-mutating operational liveness, readiness, and detailed health checks.
 */
class HealthController
{
    public function __construct(
        private readonly SystemHealthService $healthService,
    ) {
    }

    /**
     * Liveness Probe: confirms PHP-FPM / HTTP worker is alive.
     */
    public function live(): JsonResponse
    {
        return response()->json($this->healthService->checkLiveness(), 200);
    }

    /**
     * Readiness Probe: confirms DB, cache, and queue connections are operational.
     */
    public function ready(): JsonResponse
    {
        $result = $this->healthService->checkReadiness();
        $status = $result['ready'] ? 200 : 503;

        return response()->json($result, $status);
    }

    /**
     * Health & Degraded State Check.
     *
     * Public callers receive only the overall status and timestamp. The full
     * dependency, queue, and reconciliation report is returned only when the
     * request carries the configured X-Health-Token header. If no token is
     * configured, the detailed report is never returned (fail closed).
     */
    public function health(Request $request): JsonResponse
    {
        $report = $this->healthService->checkDetailedHealth();
        $httpStatus = $report['status'] === 'UNHEALTHY' ? 503 : 200;

        if (! $this->isDetailAuthorized($request)) {
            return response()->json([
                'status' => $report['status'],
                'timestamp' => $report['timestamp'],
            ], $httpStatus);
        }

        return response()->json($report, $httpStatus);
    }

    private function isDetailAuthorized(Request $request): bool
    {
        $expected = config('app.health_detail_token');

        if (! is_string($expected) || strlen($expected) < 32) {
            return false;
        }

        $provided = $request->header('X-Health-Token');

        return is_string($provided) && hash_equals($expected, $provided);
    }
}
