<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\GloPrizeClaim;
use App\Models\User;
use App\Support\Admin\AdminAccess;

/**
 * GLO-12/14 prize claim authorization (default deny).
 *
 * Claimants see only their own claims; operators need the granular GLO claim
 * or payment permission. Payment ability is intentionally separate from claim
 * review so a claim reviewer cannot execute money alone.
 */
class GloPrizeClaimPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return AdminAccess::allowsAny($user, [
            AdminAccess::MANAGE_GLO_PRIZE_CLAIMS,
            AdminAccess::EXECUTE_GLO_PRIZE_PAYMENTS,
            AdminAccess::VIEW_AUDIT_LOGS,
        ]);
    }

    public function view(User $user, GloPrizeClaim $claim): bool
    {
        if ((int) $claim->claimant_user_id === (int) $user->getKey()) {
            return true;
        }

        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        // Any authenticated, active claimant may start their own claim;
        // eligibility is enforced in GloPrizeClaimService.
        return $user->isActive();
    }

    public function review(User $user, GloPrizeClaim $claim): bool
    {
        return AdminAccess::allows($user, AdminAccess::MANAGE_GLO_PRIZE_CLAIMS);
    }

    public function approve(User $user, GloPrizeClaim $claim): bool
    {
        return AdminAccess::allows($user, AdminAccess::MANAGE_GLO_PRIZE_CLAIMS);
    }

    public function pay(User $user, GloPrizeClaim $claim): bool
    {
        return AdminAccess::allows($user, AdminAccess::EXECUTE_GLO_PRIZE_PAYMENTS);
    }

    public function cancel(User $user, GloPrizeClaim $claim): bool
    {
        if ((int) $claim->claimant_user_id === (int) $user->getKey()) {
            return true;
        }

        return AdminAccess::allows($user, AdminAccess::MANAGE_GLO_PRIZE_CLAIMS);
    }
}
