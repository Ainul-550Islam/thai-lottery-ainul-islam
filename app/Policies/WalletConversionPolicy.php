<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WalletConversion;
use App\Support\Admin\AdminAccess;

/**
 * Who may see, and who may undo, a wallet conversion.
 *
 * THE TWO QUESTIONS THIS POLICY ANSWERS DIFFERENTLY
 * Viewing is ownership: a member may read their own conversions and only their
 * own, and an operator with the transaction-history permission may read any.
 * Reversing is the opposite: NO member may reverse anything, ever, including
 * their own conversion. That asymmetry is the point of the whole file. A
 * conversion is a completed movement of money; letting the party who benefited
 * from it undo it would turn the feature into a fee-free round trip and would
 * let a member defeat a rolling limit by converting, reversing, converting
 * again. Reversal exists for the platform to correct its own mistakes, and only
 * the platform may reach for it.
 *
 * WHY `reverse` IS NOT `update`
 * Laravel's default resource abilities include `update`. Mapping a reversal onto
 * it would be convenient and wrong: `update` implies editing a record in place,
 * and a reversal never edits anything — it writes a second conversion and marks
 * the first. The ability is named for what it does so that a future reader of
 * the controller cannot mistake one for the other.
 *
 * WHY THE WINDOW IS NOT CHECKED HERE
 * The reversal window is a business rule, not an authorisation rule: an operator
 * who is permitted to reverse is still permitted at hour 25, and the refusal
 * they receive should say "the window has closed", not "you may not". The policy
 * answers who; the service answers when. Mixing them produces a 403 for what is
 * really a 409 and sends support looking for a permissions problem that does not
 * exist.
 */
class WalletConversionPolicy
{
    /**
     * May this member list their own conversions? Always: the endpoint is scoped
     * by user id, so the answer cannot leak another member's rows.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Owners read their own conversions. Operators read any, gated on the
     * permission the panel already uses for financial history.
     */
    public function view(User $user, WalletConversion $conversion): bool
    {
        if ((int) $conversion->user_id === (int) $user->getKey()) {
            return true;
        }

        return AdminAccess::allows($user, AdminAccess::VIEW_TRANSACTION_HISTORY);
    }

    /**
     * Members cannot create a conversion through a policy-gated route: the
     * endpoint authenticates and derives everything from the session, so the
     * policy is never the gate that stops abuse. It returns true for an
     * authenticated user because refusing here would break the member lane while
     * providing no protection the route does not already provide.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Nobody edits a conversion. There is no update endpoint, and this method
     * exists so that a future controller which tries to add one gets a hard
     * "no" from the framework rather than an accidental yes by omission.
     */
    public function update(User $user, WalletConversion $conversion): bool
    {
        return false;
    }

    /**
     * Deleting a conversion would destroy the evidence that money moved while
     * leaving its ledger rows behind, which is the one state this platform must
     * never be able to reach. Refused for everyone, without exception — the
     * correction path is a reversal, which leaves both records in place.
     */
    public function delete(User $user, WalletConversion $conversion): bool
    {
        return false;
    }

    /**
     * Operator-only, and only with the wallet-management capability. The
     * reconciliation permission is accepted as well, because investigating a
     * mismatch is exactly the situation that produces a legitimate reversal and
     * the person investigating it is the person who holds that permission.
     *
     * An auditor deliberately does NOT pass: an auditor's role is to read the
     * trail, and a role that can both read and alter the trail is not an
     * auditor.
     */
    /**
     * Does this principal hold an operator role?
     *
     * READ FROM THE LOADED SNAPSHOT WHEN THERE IS ONE, ON PURPOSE. This policy is
     * the *presentation* answer: it decides whether a panel shows the reverse
     * button, and a panel that has already hydrated a user must not fire an extra
     * query per row to re-ask a question it just answered. The consequence is
     * that this answer can be stale — a role removed in another process is still
     * visible to a model loaded a moment earlier.
     *
     * That is safe only because the policy is NOT the financial boundary. Every
     * reversal passes through ConversionReversalAuthority, which re-reads roles,
     * permissions, guards and user status from the database inside the
     * transaction, under lock, and refuses on its own. So a stale "yes" here
     * costs a refused request, never a moved balance. A stale "no" costs nothing
     * but a reload. Making this method fresh-looking would not add safety — it
     * would only move the same decision to a second place where the two copies
     * can disagree.
     */
    private function holdsAnyRole(User $user): bool
    {
        if ($user->relationLoaded('roles')) {
            return $user->roles->isNotEmpty();
        }

        return $user->roles()->exists();
    }

    public function reverse(User $user, WalletConversion $conversion): bool
    {
        // A member holds no operator role, so they are refused here without the
        // permission lookup. Checking a permission a member cannot hold is how a
        // refusal turned into a server error.
        if (! $this->holdsAnyRole($user)) {
            return false;
        }

        if (AdminAccess::isReadOnly($user)) {
            return false;
        }

        return AdminAccess::allows($user, AdminAccess::MANAGE_WALLET)
            || AdminAccess::allows($user, AdminAccess::RECONCILE_LEDGER);
    }
}
