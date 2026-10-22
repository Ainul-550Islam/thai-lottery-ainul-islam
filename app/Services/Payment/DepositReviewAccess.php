<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/** A separate observation/acknowledgement duty; never grants a refund or wallet reversal. */
final class DepositReviewAccess
{
    public const REVIEW = 'review stripe deposit followups';

    public function authorize(?User $actor): User
    {
        $current = $actor ? User::query()->useWritePdo()->find($actor->getKey()) : null;
        if (! $current || $current->status !== UserStatus::Active
            || ! $current->hasAnyRole(['admin', 'super-admin'])
            || ! $current->checkPermissionTo(self::REVIEW, 'web')) {
            throw new AuthorizationException('Current active staff and explicit Stripe follow-up review duty are required.');
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
