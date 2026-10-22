<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Monitoring\MetricsCollectorService;
use Illuminate\Http\Response;

class MetricsController extends Controller
{
    public function __construct(
        private readonly MetricsCollectorService $metricsService
    ) {}

    /**
     * Expose Prometheus metrics endpoint for Grafana telemetry scraping.
     */
    public function index(): Response
    {
        $payload = $this->metricsService->generatePrometheusMetrics();

        return new Response($payload, 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }
}
