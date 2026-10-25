<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DataSubjectRequest;
use App\Models\User;
use App\Support\Admin\AdminAccess;

/**
 * Who may see and act on a data-subject request.
 *
 * =========================================================================
 * THE REQUESTER IS NOT AN OPERATOR
 * =========================================================================
 * The person who filed a request reaches their own copy through
 * /account/privacy-requests, which is scoped by session user and never calls
 * this policy. No ability here grants a requester anything: viewAny/update and
 * friends are staff abilities, so a player who happens to hold the model in a
 * Blade variable still cannot authorise a staff action with it.
 *
 * =========================================================================
 * TWO HATS, DELIBERATELY SEPARATE
 * =========================================================================
 * - LOOKING: an operator who may audit the platform's handling (audit logs,
 *   risk alerts) may read a request and its timeline. Reading a DSAR is a
 *   review activity.
 * - ACTING: moving the lifecycle, marking erasure reviewed, releasing the
 *   archive and re-running retention is a write on the platform's compliance
 *   record, so it requires the permission that already governs consequential
 *   staff actions (manage users). No new permission is invented: this lane
 *   must work on a deployment whose role seeder nobody has re-run, and adding
 *   a permission that does not exist yet would mean every ability answers
 *   false and the panel looks broken for everybody including the super admin.
 *
 * Nothing here can delete: erasure is a reviewed task, never a delete.
 */
final class DataSubjectRequestPolicy extends BasePolicy
{
    protected string $permissionPrefix = 'privacy-request';

    public function viewAny(User $user): bool
    {
        return $this->mayReview($user);
    }

    public function view(User $user, DataSubjectRequest $request): bool
    {
        return $this->mayReview($user);
    }

    public function create(User $user): bool
    {
        // Requests are opened by the requester, never typed in by an operator:
        // a staff-created request would have no proven owner and no verified
        // address, which is the one property the whole workflow rests on.
        return false;
    }

    public function update(User $user, DataSubjectRequest $request): bool
    {
        return $this->mayAct($user);
    }

    /** Build, rebuild or release the personal-data archive. */
    public function export(User $user, DataSubjectRequest $request): bool
    {
        return $this->mayAct($user);
    }

    /** Ask the retention guard what must be kept for an erasure request. */
    public function evaluateRetention(User $user, DataSubjectRequest $request): bool
    {
        return $this->mayAct($user);
    }

    public function delete(User $user, DataSubjectRequest $request): bool
    {
        // Not an ability this lane has, anywhere, for anybody. Purging an
        // EXPIRED ARCHIVE is a retention job (privacy:purge-expired); deleting
        // the request record itself would erase the evidence that the platform
        // answered it.
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, DataSubjectRequest $request): bool
    {
        return false;
    }

    public function forceDelete(User $user, DataSubjectRequest $request): bool
    {
        return false;
    }

    private function mayReview(User $user): bool
    {
        return $user->isActive()
            && AdminAccess::allowsAny($user, [AdminAccess::VIEW_AUDIT_LOGS, AdminAccess::MANAGE_USERS]);
    }

    private function mayAct(User $user): bool
    {
        return $user->isActive()
            && AdminAccess::allowsAny($user, [AdminAccess::MANAGE_USERS]);
    }
}
