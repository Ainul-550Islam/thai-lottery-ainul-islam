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
        if (! $user) {
            return false;
        }

        return (bool) ($user->is_admin ?? false) || in_array($user->role ?? '', ['admin', 'super_admin', 'security_officer'], true);
    }
}
