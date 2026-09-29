<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AccountVerification;
use App\Models\User;
use App\Support\Admin\AdminAccess;

/*
 * PROMPT 3 — the verification authorization policy.
 *
 * SELF-SCOPE ONLY for members: a member may view and submit only
 * their own verification. Any client-supplied user_id / account_id /
 * verification_id is dead on arrival — the controller resolves the
 * subject from the session, and these methods are the second wall.
 *
 * REVIEW is a privileged action: the same panel-role convention the
 * platform uses everywhere else (AdminAccess::PANEL_ROLES + the
 * explicit admin/super-admin checks), with super-admin short-
 * circuiting via the existing Gate::before.
 */
final class AccountVerificationPolicy
{
    /**
     * View one's own submission aggregate (and its history).
     */
    public function view(User $user, AccountVerification $verification): bool
    {
        return (int) $verification->user_id === (int) $user->id;
    }

    /**
     * Submit a verification for oneself. The subject is ALWAYS the
     * authenticated member; a model is only passed when re-submitting
     * against a prior aggregate row.
     */
    public function submit(User $user, ?AccountVerification $verification = null): bool
    {
        return $verification === null
            || (int) $verification->user_id === (int) $user->id;
    }

    /**
     * Move an open submission into review.
     */
    public function review(User $user, AccountVerification $verification): bool
    {
        return $this->isReviewer($user);
    }

    /**
     * Close a submission with a decision.
     */
    public function decide(User $user, AccountVerification $verification): bool
    {
        return $this->isReviewer($user);
    }

    private function isReviewer(User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->hasAnyRole(AdminAccess::PANEL_ROLES);
    }
}
