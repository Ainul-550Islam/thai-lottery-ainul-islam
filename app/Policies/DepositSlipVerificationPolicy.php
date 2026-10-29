<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DepositSlipVerification;
use App\Models\User;
use App\Support\Admin\AdminAccess;

final class DepositSlipVerificationPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return AdminAccess::canAccessPanel($user) && AdminAccess::allowsAny($user, [
            AdminAccess::VIEW_TRANSACTION_HISTORY,
            AdminAccess::MANAGE_PAYOUTS,
        ]);
    }

    public function view(User $user, DepositSlipVerification $verification): bool
    {
        return ((int) $verification->user_id === (int) $user->getKey())
            || (AdminAccess::canAccessPanel($user) && AdminAccess::allowsAny($user, [
                AdminAccess::VIEW_TRANSACTION_HISTORY,
                AdminAccess::MANAGE_PAYOUTS,
            ]));
    }

    public function approve(User $user, DepositSlipVerification $verification): bool
    {
        return AdminAccess::canAccessPanel($user) && $verification->status->value === 'needs_review'
            && AdminAccess::allows($user, AdminAccess::MANAGE_PAYOUTS);
    }

    public function reject(User $user, DepositSlipVerification $verification): bool
    {
        return AdminAccess::canAccessPanel($user) && $verification->status->value === 'needs_review'
            && AdminAccess::allows($user, AdminAccess::MANAGE_PAYOUTS);
    }
}
