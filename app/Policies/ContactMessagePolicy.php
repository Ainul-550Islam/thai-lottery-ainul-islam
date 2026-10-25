<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ContactMessage;
use App\Models\User;
use App\Support\Admin\AdminAccess;

/**
 * Who may work the support inbox.
 *
 * A contact message is a stranger's words about the platform. Reading one is a
 * support task; replying to one is an outbound email written in the platform's
 * name; classifying one as spam is a judgement that can hide a real complaint.
 * All three therefore sit behind the same consequential-staff permission the
 * rest of the operator panel uses (manage users), plus the active-account check
 * every other policy in this project applies.
 *
 * NOBODY MAY CREATE OR DELETE. A ticket exists because a visitor sent it, so an
 * operator cannot conjure one; and a support record is evidence of what was
 * said, so deleting it is not offered - the closed states are SPAM and
 * ARCHIVED, both reachable and both reversible.
 */
final class ContactMessagePolicy extends BasePolicy
{
    protected string $permissionPrefix = 'contact-message';

    public function viewAny(User $user): bool
    {
        return $this->mayWork($user);
    }

    public function view(User $user, ContactMessage $message): bool
    {
        return $this->mayWork($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ContactMessage $message): bool
    {
        return $this->mayWork($user);
    }

    /** Compose and queue a reply to the visitor. */
    public function reply(User $user, ContactMessage $message): bool
    {
        return $this->mayWork($user);
    }

    /** Add an internal note (stored encrypted, never sent). */
    public function note(User $user, ContactMessage $message): bool
    {
        return $this->mayWork($user);
    }

    public function delete(User $user, ContactMessage $message): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, ContactMessage $message): bool
    {
        return false;
    }

    public function forceDelete(User $user, ContactMessage $message): bool
    {
        return false;
    }

    private function mayWork(User $user): bool
    {
        return $user->isActive()
            && AdminAccess::allowsAny($user, [AdminAccess::MANAGE_USERS]);
    }
}
