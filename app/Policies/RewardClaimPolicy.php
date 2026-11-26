<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RewardClaim;
use App\Models\User;
use App\Support\Admin\AdminAccess;

final class RewardClaimPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, RewardClaim $claim): bool
    {
        return (int) $claim->member_id === (int) $user->getKey()
            || AdminAccess::canAccessPanel($user);
    }

    public function create(User $user): bool
    {
        return true;
    }
}
