<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AccountGradeSnapshot;
use App\Models\GradeDiscountSnapshot;
use App\Models\User;

/**
 * Grade data access policy (PROMPT 2, section M).
 *
 * GRADE FIGURES ARE THE PLAYER'S OWN. A grade is a summary of one
 * person's spend, so its snapshots answer to exactly two parties: the
 * owner and the staff administering the platform. Everybody else —
 * including other players and guests — is denied by default.
 *
 * The policy is registered for BOTH snapshot models in
 * AuthServiceProvider; super-admins short-circuit via the Gate::before
 * hook that already exists there, so this class only decides the
 * owner-or-admin cases.
 */
final class AccountGradePolicy
{
    /**
     * May the viewer list the subject's grade snapshots?
     */
    public function viewSnapshots(?User $viewer, User $subject): bool
    {
        return $this->isOwnerOrAdmin($viewer, $subject);
    }

    /**
     * May the viewer read one grade snapshot?
     */
    public function viewSnapshot(?User $viewer, AccountGradeSnapshot $snapshot): bool
    {
        return $this->isOwnerOrAdmin($viewer, $snapshot->user);
    }

    /**
     * May the viewer read one per-game discount snapshot?
     */
    public function viewGradeDiscountSnapshot(?User $viewer, GradeDiscountSnapshot $snapshot): bool
    {
        return $this->isOwnerOrAdmin($viewer, $snapshot->user);
    }

    /**
     * May the viewer trigger a recalculation of the subject's grade?
     * Recalculation re-reads the authoritative spend and may append a
     * new snapshot row, so it is owner-or-admin too.
     */
    public function recalculate(?User $viewer, User $subject): bool
    {
        return $this->isOwnerOrAdmin($viewer, $subject);
    }

    /**
     * May the viewer administer the whole grade programme (rebuild
     * snapshots for everybody, retire tiers...)? Admins only — an
     * owner may see and refresh their OWN grade, never touch anyone
     * else's and never rewrite the programme.
     */
    public function administer(?User $viewer): bool
    {
        return $viewer !== null && $viewer->isAdmin();
    }

    /**
     * Owner-or-admin. Guests are denied outright (a null viewer can
     * own nothing).
     */
    private function isOwnerOrAdmin(?User $viewer, ?User $subject): bool
    {
        if ($viewer === null || $subject === null) {
            return false;
        }

        return $viewer->id === $subject->id || $viewer->isAdmin();
    }
}
