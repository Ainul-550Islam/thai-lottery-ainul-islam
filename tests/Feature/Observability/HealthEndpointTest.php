<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use App\Services\Monitoring\HealthCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Health, Liveness, and Readiness Endpoint Feature Tests.
 */
final class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_check_service_returns_live_status(): void
    {
        /** @var HealthCheckService $service */
        $service = $this->app->make(HealthCheckService::class);

        $liveness = $service->isLive();

        $this->assertIsArray($liveness);
        $this->assertSame('healthy', $liveness['status']);
        $this->assertArrayHasKey('timestamp', $liveness);
    }

    public function test_readiness_check_validates_critical_dependencies(): void
    {
        /** @var HealthCheckService $service */
        $service = $this->app->make(HealthCheckService::class);

        $readiness = $service->isReady();

        $this->assertIsArray($readiness);
        $this->assertTrue($readiness['ready']);
        $this->assertTrue($readiness['database']);
        $this->assertTrue($readiness['cache']);
        $this->assertTrue($readiness['queue']);
        $this->assertTrue($readiness['storage']);
    }

    public function test_full_health_report_structure(): void
    {
        /** @var HealthCheckService $service */
        $service = $this->app->make(HealthCheckService::class);

        $report = $service->checkHealth();

        $this->assertIsArray($report);
        $this->assertArrayHasKey('status', $report);
        $this->assertArrayHasKey('dependencies', $report);
        $this->assertArrayHasKey('storage', $report['dependencies']);
        $this->assertArrayHasKey('payments', $report['dependencies']);
    }
}
