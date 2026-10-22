<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies that the public health endpoints do not disclose internal detail.
 */
final class HealthDisclosureTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'health-detail-token-0123456789abcdef';

    public function test_public_health_returns_only_status_and_timestamp(): void
    {
        config(['app.health_detail_token' => self::TOKEN]);

        $response = $this->getJson('/api/health');

        $this->assertContains($response->status(), [200, 503]);
        $payload = $response->json();
        $this->assertSame(['status', 'timestamp'], array_keys($payload));
        $this->assertContains($payload['status'], ['HEALTHY', 'DEGRADED', 'UNHEALTHY']);
        $this->assertArrayNotHasKey('dependencies', $payload);
        $this->assertArrayNotHasKey('warnings', $payload);
    }

    public function test_detail_is_hidden_when_no_token_is_configured(): void
    {
        config(['app.health_detail_token' => null]);

        $response = $this->withHeader('X-Health-Token', self::TOKEN)->getJson('/api/health');

        $this->assertArrayNotHasKey('dependencies', $response->json());
        $this->assertArrayNotHasKey('warnings', $response->json());
    }

    public function test_detail_is_hidden_for_short_configured_token(): void
    {
        config(['app.health_detail_token' => 'short']);

        $response = $this->withHeader('X-Health-Token', 'short')->getJson('/api/health');

        $this->assertArrayNotHasKey('dependencies', $response->json());
    }

    public function test_detail_is_hidden_for_wrong_token(): void
    {
        config(['app.health_detail_token' => self::TOKEN]);

        $response = $this->withHeader('X-Health-Token', 'wrong-token-value-000000000000000')->getJson('/api/health');

        $this->assertArrayNotHasKey('dependencies', $response->json());
    }

    public function test_detail_is_returned_with_correct_token(): void
    {
        config(['app.health_detail_token' => self::TOKEN]);

        $response = $this->withHeader('X-Health-Token', self::TOKEN)->getJson('/api/health');

        $this->assertContains($response->status(), [200, 503]);
        $this->assertArrayHasKey('dependencies', $response->json());
        $this->assertArrayHasKey('warnings', $response->json());
    }

    public function test_liveness_and_readiness_routes_remain_available(): void
    {
        $this->getJson('/up/live')->assertStatus(200);
        $this->assertContains($this->getJson('/up/ready')->status(), [200, 503]);
    }
}
