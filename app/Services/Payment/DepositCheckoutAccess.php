<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/** Explicit current staff duty; neither a player permission nor a provider settlement grant. */
final class DepositCheckoutAccess
{
    public const RECONCILE = 'reconcile deposit checkouts';

    public function authorize(?User $actor): User
    {
        $current = $actor ? User::query()->useWritePdo()->find($actor->getKey()) : null;
        if (! $current || $current->status !== UserStatus::Active
            || ! $current->hasAnyRole(['admin', 'super-admin'])
            || ! $current->checkPermissionTo(self::RECONCILE, 'web')) {
            throw new AuthorizationException('Current active staff and explicit checkout reconciliation duty are required.');
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
