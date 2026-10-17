<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\SecurityAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityAuditLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_persists_immutable_security_audit_logs(): void
    {
        $log = SecurityAuditLog::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'event_type' => 'IP_BLOCKED',
            'actor_user_id' => 'admin-001',
            'client_ip' => '198.51.100.5',
            'user_agent' => 'AdminPortal/1.0',
            'severity' => 'HIGH',
            'payload' => [
                'target_ip' => '198.51.100.0/24',
                'reason' => 'BRUTE_FORCE',
            ],
            'occurred_at' => now(),
        ]);

        $this->assertDatabaseHas('security_audit_logs', [
            'id' => $log->id,
            'event_type' => 'IP_BLOCKED',
            'severity' => 'HIGH',
            'actor_user_id' => 'admin-001',
        ]);
    }
}
