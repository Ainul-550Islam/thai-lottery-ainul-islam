<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/** Explicit current staff duty; not a player permission or a blanket manual credit grant. */
final class DepositSettlementAccess
{
    public const RECONCILE = 'reconcile stripe deposit settlements';

    public function authorize(?User $actor): User
    {
        $current = $actor ? User::query()->useWritePdo()->find($actor->getKey()) : null;
        if (! $current || $current->status !== UserStatus::Active
            || ! $current->hasAnyRole(['admin', 'super-admin'])
            || ! $current->checkPermissionTo(self::RECONCILE, 'web')) {
            throw new AuthorizationException('Current active staff and explicit Stripe settlement duty are required.');
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
