<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\UserStatus;
use App\Models\FraudAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminSecurityApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSecurityAdmin(): User
    {
        $admin = User::factory()->create([
            'status' => UserStatus::Active,
        ]);

        $role = Role::findOrCreate('super-admin', 'web');
        $perms = [
            'view audit logs',
            'manage system settings',
            'view risk alerts',
            'manage risk limits',
        ];
        foreach ($perms as $perm) {
            Permission::findOrCreate($perm, 'web');
        }
        $role->syncPermissions($perms);
        $admin->assignRole($role);

        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_unauthenticated_requests_are_rejected_on_admin_security_endpoints(): void
    {
        $this->postJson('/api/v1/admin/security/ip-blocks', [
            'ip_or_cidr' => '198.51.100.0/24',
            'reason_code' => 'BRUTE_FORCE',
        ])->assertStatus(401);
    }

    public function test_admin_can_list_and_create_ip_blocks(): void
    {
        $this->actingAsSecurityAdmin();

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
        $this->actingAsSecurityAdmin();
        // The reviewer and the account under review must be different people.
        $subject = User::factory()->create();

        $alert = FraudAlert::create([
            'id' => (string) Str::uuid(),
            'idempotency_event_key' => 'evt-test-123',
            'event_type' => 'WITHDRAWAL',
            'user_id' => (string) $subject->id,
            'amount' => '50000.0000',
            'currency' => 'THB',
            'risk_score' => 85,
            'decision' => 'HOLD',
            'status' => 'OPEN',
            'reason_codes' => ['HIGH_VELOCITY'],
        ]);

        $response = $this->postJson("/api/v1/admin/security/fraud-alerts/{$alert->id}/review", [
            'action' => 'RELEASE_ALLOW',
            'decision_reason' => 'Customer KYC identity verified via live phone check.',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('fraud_alerts', [
            'id' => $alert->id,
            'status' => 'RESOLVED_ALLOW',
        ]);
    }
}
