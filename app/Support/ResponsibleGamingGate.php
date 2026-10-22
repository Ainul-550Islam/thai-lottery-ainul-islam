<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\SelfExclusionStatus;
use App\Models\SelfExclusion;
use App\Models\User;

/**
 * Single authoritative responsible-gaming check for conversion surfaces (§D6).
 *
 * Mirrors the exact check proven in LifecycleCampaignService (§D4):
 * queries active SelfExclusion rows for the player and evaluates
 * SelfExclusion::currentlyGates() so expired temporary cooling-off rows do not
 * falsely block an eligible player while active exclusions block immediately.
 *
 * This gate is intentionally NOT controlled by any configuration flag.
 */
final class ResponsibleGamingGate
{
    public static function isSelfExcluded(User|int|null $user): bool
    {
        if ($user === null) {
            return false;
        }

        $userId = $user instanceof User ? (int) $user->getKey() : (int) $user;
        if ($userId <= 0) {
            return false;
        }

        return SelfExclusion::query()
            ->where('user_id', $userId)
            ->where('status', SelfExclusionStatus::Active->value)
            ->get()
            ->contains(static fn (SelfExclusion $row): bool => $row->currentlyGates());
    }
}
