<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FraudAlert;
use App\Models\User;

class FraudAlertPolicy
{
    public function viewAny(?User $user): bool
    {
        return $this->isComplianceOfficer($user);
    }

    public function view(?User $user, FraudAlert $alert): bool
    {
        return $this->isComplianceOfficer($user);
    }

    public function review(?User $user, ?FraudAlert $alert = null): bool
    {
        return $this->isComplianceOfficer($user)
            && ($alert === null || (string) $alert->user_id !== (string) $user->getKey());
    }

    private function isComplianceOfficer(?User $user): bool
    {
        return $user !== null && $user->isActive()
            && $user->hasAnyRole(['admin', 'super-admin', 'compliance_officer']);
    }
}
