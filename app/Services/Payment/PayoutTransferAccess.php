<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class PayoutTransferAccess
{
    public const CLAIM_REVIEW = 'review player prize claims';

    public const VIEW = 'view payout transfers';

    public const REQUEST = 'request payout approvals';

    public const APPROVE = 'review payout approvals';

    public const REVIEW = 'review payout transfers';

    public const ROUTE = 'route payout batches';

    public const UPLOAD_EVIDENCE = 'upload payout evidence';

    public const READ_EVIDENCE = 'read payout evidence';

    public function authorize(?User $actor, string $ability): User
    {
        $current = $actor ? User::query()->find($actor->getKey()) : null;
        if (! in_array($ability, [self::VIEW, self::REQUEST, self::APPROVE, self::REVIEW, self::ROUTE, self::UPLOAD_EVIDENCE, self::READ_EVIDENCE, self::CLAIM_REVIEW], true)
            || ! $current || $current->status !== UserStatus::Active
            || ! $current->hasAnyRole(in_array($ability, [self::VIEW, self::READ_EVIDENCE], true) ? ['admin', 'super-admin', 'auditor'] : ['admin', 'super-admin'])
            || ! $current->checkPermissionTo($ability, 'web')) {
            throw new AuthorizationException('Current active staff authority and explicit payout duty are required.');
        }

        return $current;
    }
}
