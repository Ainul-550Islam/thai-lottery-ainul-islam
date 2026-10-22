<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class WithdrawalReconciliationAccess
{
    public const VIEW = 'view withdrawal reconciliations';

    public const REQUEST = 'request withdrawal reconciliations';

    public const REVIEW = 'review withdrawal reconciliations';

    /** Read current persisted authority; no Gate::before super-admin bypass or stale principal relations. */
    public function authorize(User $actor, string $ability): User
    {
        $current = User::query()->find($actor->getKey());
        $roles = $ability === self::VIEW ? ['admin', 'super-admin', 'auditor'] : ['admin', 'super-admin'];
        if (! in_array($ability, [self::VIEW, self::REQUEST, self::REVIEW], true) || ! $current
            || $current->status !== UserStatus::Active || ! $current->hasAnyRole($roles)
            || ! $current->checkPermissionTo(self::VIEW, 'web') || ! $current->checkPermissionTo($ability, 'web')) {
            throw new AuthorizationException('Active operator authority and explicit reconciliation permission are required.');
        }

        return $current;
    }
}
