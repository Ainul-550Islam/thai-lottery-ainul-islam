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
        return $this->isComplianceOfficer($user);
    }

    private function isComplianceOfficer(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        return (bool) ($user->is_admin ?? false) || in_array($user->role ?? '', ['admin', 'compliance_officer', 'risk_auditor'], true);
    }
}
