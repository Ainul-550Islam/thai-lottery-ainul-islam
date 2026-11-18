<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BetSlip;
use App\Models\User;

/**
 * A member may only touch their own draft slip.
 *
 * WHY A POLICY WHEN THE SERVICE ALREADY SCOPES BY MEMBER
 * Defence in depth, and a single readable statement of the rule. The service's
 * scoped queries are what actually prevent a cross-member read; the policy is what
 * makes the intent explicit to a reviewer, to Filament, and to any future caller
 * that has a BetSlip in hand and asks "may I?".
 *
 * There is no `viewAny` for staff: an operator inspects drafts through the support
 * and audit surfaces, not by browsing a member's basket anonymously.
 */
final class BetSlipPolicy
{
    public function view(User $user, BetSlip $slip): bool
    {
        return (int) $slip->member_id === (int) $user->getKey();
    }

    public function update(User $user, BetSlip $slip): bool
    {
        return $this->view($user, $slip) && $slip->status->isEditable();
    }

    /**
     * Confirming is an update that moves money, so it additionally requires the
     * slip to still be inside its clock.
     */
    public function confirm(User $user, BetSlip $slip): bool
    {
        return $this->update($user, $slip) && $slip->isEditable();
    }

    public function delete(User $user, BetSlip $slip): bool
    {
        return $this->view($user, $slip) && ! $slip->status->isFinal();
    }
}
