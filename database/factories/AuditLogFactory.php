<?php

namespace Database\Factories;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AuditLog>
 *
 * The table carries created_at only — App\Models\AuditLog disables UPDATED_AT.
 */
class AuditLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'action' => AuditAction::Create,
            'risk_level' => RiskLevel::Low,
            'auditable_type' => null,
            'auditable_id' => null,
            'description' => 'Audit line written by a factory',
            'old_values' => null,
            'new_values' => null,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'url' => 'http://localhost/admin',
            'method' => 'GET',
            'request_id' => (string) Str::uuid(),
            'metadata' => [],
        ];
    }

    public function system(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => null,
        ]);
    }

    public function critical(): static
    {
        return $this->state(fn (array $attributes) => [
            'risk_level' => RiskLevel::Critical,
            'action' => AuditAction::SecurityAlert,
        ]);
    }
}
