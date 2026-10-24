<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\GloL6TicketReservation;
use App\Models\GloTicket;
use App\Models\User;

class GloL6TicketPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive();
    }

    public function view(User $user, GloTicket|GloL6TicketReservation $record): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        if ($this->isAdminOrAuditor($user)) {
            return true;
        }

        $ownerId = $record instanceof GloTicket
            ? (int) $record->owner_user_id
            : (int) $record->user_id;

        return (int) $user->getKey() === $ownerId;
    }

    public function create(User $user): bool
    {
        return $user->isActive();
    }

    public function confirm(User $user, GloL6TicketReservation $reservation): bool
    {
        return $user->isActive() && (int) $user->getKey() === (int) $reservation->user_id;
    }

    public function release(User $user, GloL6TicketReservation $reservation): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        if ($this->isAdmin($user)) {
            return true;
        }

        return (int) $user->getKey() === (int) $reservation->user_id;
    }

    public function refundCancelledDraw(User $user): bool
    {
        return $user->isActive() && $this->isAdmin($user);
    }

    private function isAdmin(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isAdmin();
    }

    private function isAdminOrAuditor(User $user): bool
    {
        return $user->isSuperAdmin()
            || $user->isAdmin()
            || $user->hasAnyRole([UserRole::Auditor->value, 'auditor', 'support', 'staff']);
    }
}
