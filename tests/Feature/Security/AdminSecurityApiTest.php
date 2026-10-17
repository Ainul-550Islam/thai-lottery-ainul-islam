<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\BlockedIp;
use App\Models\FraudAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSecurityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_and_create_ip_blocks(): void
    {
        $payload = [
            'ip_or_cidr' => '198.51.100.0/24',
            'reason_code' => 'BRUTE_FORCE',
            'source' => 'MANUAL',
            'duration_minutes' => 60,
            'notes' => 'Test IP Block',
        ];

        $response = $this->postJson('/api/v1/admin/security/ip-blocks', $payload);

        $response->assertStatus(201);
        $this->assertDatabaseHas('blocked_ips', [
            'ip_or_cidr' => '198.51.100.0/24',
            'is_active' => true,
        ]);
    }

    public function test_admin_can_review_fraud_alert(): void
    {
        $alert = FraudAlert::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'idempotency_event_key' => 'evt-test-123',
            'event_type' => 'WITHDRAWAL',
            'user_id' => 'user-999',
            'amount' => '50000.0000',
            'currency' => 'THB',
            'risk_score' => 85,
            'decision' => 'HOLD',
            'status' => 'OPEN',
            'reason_codes' => ['HIGH_VELOCITY'],
            'raw_payload' => [],
        ]);

        $response = $this->postJson("/api/v1/admin/security/fraud-alerts/{$alert->id}/review", [
            'action' => 'RELEASE_ALLOW',
            'decision_reason' => 'Customer KYC identity verified via live phone check.',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('fraud_alerts', [
            'id' => $alert->id,
            'status' => 'RESOLVED_ALLOW',
        ]);
        $this->assertDatabaseHas('fraud_cases', [
            'fraud_alert_id' => $alert->id,
            'action_taken' => 'RELEASE_ALLOW',
        ]);
    }
}
