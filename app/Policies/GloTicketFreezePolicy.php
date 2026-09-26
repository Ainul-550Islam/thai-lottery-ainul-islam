<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\GloTicketFreeze;
use App\Models\User;
use App\Support\Admin\AdminAccess;

/**
 * GLO-11 freeze case authorization (default deny).
 *
 * Viewers need review permission (or panel audit permission); mutations are
 * decided in GloTicketFreezeService under locks — this policy only answers
 * "may this operator touch freeze cases at all?".
 */
class GloTicketFreezePolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return AdminAccess::allowsAny($user, [
            AdminAccess::REVIEW_GLO_FREEZES,
            AdminAccess::REQUEST_GLO_FREEZES,
            AdminAccess::VIEW_AUDIT_LOGS,
        ]);
    }

    public function view(User $user, GloTicketFreeze $freeze): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return AdminAccess::allows($user, AdminAccess::REQUEST_GLO_FREEZES);
    }

    public function review(User $user, GloTicketFreeze $freeze): bool
    {
        return AdminAccess::allows($user, AdminAccess::REVIEW_GLO_FREEZES);
    }

    public function release(User $user, GloTicketFreeze $freeze): bool
    {
        return AdminAccess::allows($user, AdminAccess::REVIEW_GLO_FREEZES);
    }
}
