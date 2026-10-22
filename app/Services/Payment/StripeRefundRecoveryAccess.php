<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/** Financial duty must be assigned directly to the current staff user, not inherited from a broad role. */
final class StripeRefundRecoveryAccess
{
    public const RECOVER = 'recover confirmed stripe refunds';

    public function authorize(?User $actor): User
    {
        $current = app(DepositReviewAccess::class)->authorize($actor);
        if (! $current->permissions()->where('permissions.name', self::RECOVER)->where('guard_name', 'web')->exists()) {
            throw new AuthorizationException('An explicit direct-user confirmed-refund financial recovery duty is required.');
        }

        return $current;
    }

    public function can(?User $actor): bool
    {
        try {
            $this->authorize($actor);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }
}
