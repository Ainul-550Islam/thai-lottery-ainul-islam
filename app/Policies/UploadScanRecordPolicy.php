<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\UploadScanRecord;
use App\Models\User;

class UploadScanRecordPolicy
{
    public function viewAny(?User $user): bool
    {
        return $this->isSecurityAdmin($user);
    }

    public function rescan(?User $user, UploadScanRecord $record): bool
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

        return $user->hasAnyRole(['security_officer', 'compliance_officer'])
            || $user->checkPermissionTo('manage system settings');
    }
}
