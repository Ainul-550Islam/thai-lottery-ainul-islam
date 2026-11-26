<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MemberBankAccount;
use App\Models\User;
use App\Support\Admin\AdminAccess;

final class MemberBankAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, MemberBankAccount $bankAccount): bool
    {
        return (int) $bankAccount->member_id === (int) $user->getKey()
            || AdminAccess::canAccessPanel($user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, MemberBankAccount $bankAccount): bool
    {
        return (int) $bankAccount->member_id === (int) $user->getKey();
    }

    public function delete(User $user, MemberBankAccount $bankAccount): bool
    {
        return (int) $bankAccount->member_id === (int) $user->getKey();
    }

    public function verify(User $user): bool
    {
        return AdminAccess::canAccessPanel($user) && ! AdminAccess::isReadOnly($user);
    }
}
