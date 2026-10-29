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
        return $user !== null && $user->isActive()
            && $user->hasAnyRole(['admin', 'super-admin', 'security_officer']);
    }
}
