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

    public function review(?User $user, FraudAlert|string|null $alert = null): bool
    {
        if (! $this->isComplianceOfficer($user)) {
            return false;
        }

        if ($alert instanceof FraudAlert && $alert->user_id !== null && trim((string) $alert->user_id) !== '') {
            if ((string) $alert->user_id === (string) $user->getKey()) {
                return false;
            }
        }

        return true;
    }

    private function isComplianceOfficer(?User $user): bool
    {
        if (! $user instanceof User || ! $user->isActive()) {
            return false;
        }

        if ($user->isSuperAdmin() || $user->isAdmin()) {
            return true;
        }

        return $user->hasAnyRole(['compliance_officer', 'risk_auditor', 'security_officer'])
            || $user->checkPermissionTo('view risk alerts')
            || $user->checkPermissionTo('manage risk limits');
    }
}
