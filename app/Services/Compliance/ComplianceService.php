<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Enums\SelfExclusionStatus;
use App\Models\AuditLog;
use App\Models\SelfExclusion;
use App\Models\User;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Universal Compliance & AML Risk Management Service.
 *
 * Enforces player protection, self-exclusion gates, KYC thresholds,
 * and suspicious activity monitoring across betting and payments.
 */
class ComplianceService
{
    /**
     * Check if a player is barred from wagering or depositing due to active self-exclusion or AML holds.
     */
    public function assertPlayerPermitted(User $user, string $action = 'bet'): void
    {
        $exclusion = SelfExclusion::query()
            ->where('user_id', $user->id)
            ->where('status', SelfExclusionStatus::Active->value)
            ->where('ends_at', '>', now())
            ->first();

        if ($exclusion !== null) {
            throw new InvalidArgumentException(sprintf(
                'Player account is currently self-excluded until %s. Action [%s] is prohibited.',
                $exclusion->ends_at->toIso8601String(),
                $action
            ));
        }

        if ($user->status !== \App\Enums\UserStatus::Active) {
            throw new InvalidArgumentException(sprintf(
                'Player account status [%s] does not permit [%s] actions.',
                $user->status->value,
                $action
            ));
        }
    }

    /**
     * Self-exclude a user for a given duration.
     */
    public function applySelfExclusion(User $user, int $durationDays, string $reason): SelfExclusion
    {
        if ($durationDays < 1) {
            throw new InvalidArgumentException('Self-exclusion must be at least 1 day.');
        }

        $exclusion = SelfExclusion::create([
            'user_id' => $user->id,
            'status' => SelfExclusionStatus::Active->value,
            'starts_at' => now(),
            'ends_at' => now()->addDays($durationDays),
            'reason' => $reason,
        ]);

        AuditLog::create([
            'user_id' => $user->id,
            'action' => AuditAction::Update,
            'risk_level' => RiskLevel::High,
            'auditable_type' => SelfExclusion::class,
            'auditable_id' => $exclusion->id,
            'description' => 'player_self_exclusion_applied',
            'metadata' => [
                'duration_days' => $durationDays,
                'ends_at' => $exclusion->ends_at->toIso8601String(),
                'reason' => $reason,
            ],
        ]);

        return $exclusion;
    }
}
