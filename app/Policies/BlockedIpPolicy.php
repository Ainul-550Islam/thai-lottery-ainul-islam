<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BlockedIp;
use App\Models\User;

class BlockedIpPolicy
{
    public function viewAny(?User $user): bool
    {
        return $this->isSecurityAdmin($user);
    }

    public function create(?User $user): bool
    {
        return $this->isSecurityAdmin($user);
    }

    public function update(?User $user, BlockedIp $blockedIp): bool
    {
        return $this->isSecurityAdmin($user);
    }

    public function delete(?User $user, BlockedIp $blockedIp): bool
    {
        return $this->isSecurityAdmin($user);
    }

    private function isSecurityAdmin(?User $user): bool
    {
        if (! $user instanceof User || ! $user->isActive()) {
            return false;
        }

        if ($user->isSuperAdmin() || $user->isAdmin()) {
            return true;
        }

        return $user->hasAnyRole(['security_officer', 'security-admin'])
            || $user->checkPermissionTo('manage system settings');
    }
}
