<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LotteryGame;
use App\Models\User;
use App\Support\Admin\AdminAccess;

/**
 * Who may see a digit game, and who may change its operator state.
 *
 * WHY THIS POLICY EXISTS EVEN THOUGH THE FILAMENT RESOURCE ALSO CHECKS
 * The resource's static can*() methods answer "should this button or screen exist". This
 * policy answers "may THIS user perform THIS action on THIS record", and it is the answer
 * Laravel's authorisation layer uses everywhere outside the panel — a controller, a
 * `can:` middleware, a Blade `@can`, a queued action re-checked later. Two layers, one
 * source: both ask AdminAccess, so they cannot disagree about which permission governs
 * a game.
 *
 * THE PERMISSIONS ARE THE EXISTING DRAW PERMISSIONS, NOT NEW ONES
 * A digit game is draw-shaped: it has draws, results and a settlement boundary. Batch 1
 * already models exactly that trust with `view draws` and `manage draws`, and a parallel
 * `manage games` permission would be a second switch an operator has to remember to grant
 * — and the first one to be forgotten, leaving a game verified by somebody who could not
 * be trusted with draws. So:
 *
 *   viewAny / view          view draws
 *   verify / suspend / resume / update   manage draws, and NOT a read-only account
 *
 * WHY VERIFY IS A MANAGE ACTION AND NOT A VIEW ACTION
 * Verification stamps a checksum of the exact rule set an operator accepted. It is the
 * moment the platform starts promising to pay according to those numbers, so it is a
 * write, and it is the write that matters most in this lane.
 *
 * WHY THERE IS NO DELETE PERMISSION
 * A game's row is the audit trail of who verified what and when. `delete` is denied
 * outright rather than left to a permission: withdrawing a game is what `suspend` is for,
 * and it keeps the history that a delete would erase.
 *
 * READ-ONLY ACCOUNTS ARE REFUSED EXPLICITLY
 * AdminAccess::isReadOnly() is a second, deliberate refusal on top of the permission
 * check. A role can hold `manage draws` for reporting purposes on this platform, and a
 * read-only seat must never be able to verify a payout table.
 */
final class LotteryGamePolicy
{
    /**
     * May the user open the game list at all?
     */
    public function viewAny(User $user): bool
    {
        return $this->mayReachConsole($user, AdminAccess::VIEW_DRAWS);
    }

    /**
     * May the user open this game?
     */
    public function view(User $user, LotteryGame $game): bool
    {
        return $this->mayReachConsole($user, AdminAccess::VIEW_DRAWS);
    }

    /**
     * May the user stamp a verification (and therefore enable selling)?
     */
    public function verify(User $user, LotteryGame $game): bool
    {
        return $this->mayManage($user);
    }

    /**
     * May the user withdraw a verification?
     */
    public function suspend(User $user, LotteryGame $game): bool
    {
        return $this->mayManage($user);
    }

    /**
     * May the user put a suspended game back into service?
     *
     * Deliberately the same permission as suspend: an account that can take a game out of
     * service and cannot put it back leaves the lane stuck until somebody else logs in.
     */
    public function resume(User $user, LotteryGame $game): bool
    {
        return $this->mayManage($user);
    }

    /**
     * May the user switch selling on?
     *
     * The same permission as verify: switching selling on is the moment the platform
     * starts accepting stakes on the verified numbers.
     */
    public function enable(User $user, LotteryGame $game): bool
    {
        return $this->mayManage($user);
    }

    /**
     * May the user switch selling off?
     *
     * Deliberately not weaker than enable: an account that can switch a game on and
     * cannot switch it off would be a trap during an incident.
     */
    public function disable(User $user, LotteryGame $game): bool
    {
        return $this->mayManage($user);
    }

    /**
     * Editing is limited to what the verify/suspend/resume actions already write.
     */
    public function update(User $user, LotteryGame $game): bool
    {
        return $this->mayManage($user);
    }

    /**
     * Games are never deleted: suspension is the supported withdrawal, and the row is the
     * audit record. Denied for every account, including a super-admin.
     */
    public function delete(User $user, LotteryGame $game): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, LotteryGame $game): bool
    {
        return false;
    }

    public function forceDelete(User $user, LotteryGame $game): bool
    {
        return false;
    }

    /**
     * The one place "may manage this game" is decided.
     */
    private function mayManage(User $user): bool
    {
        return $this->mayReachConsole($user, AdminAccess::MANAGE_DRAWS)
            && ! AdminAccess::isReadOnly($user);
    }

    /**
     * A permission AND a seat at the operator console.
     *
     * WHY THE PERMISSION ALONE IS NOT ENOUGH
     * `view draws` is not an operator permission. Players hold it, because the player-facing
     * draw list is the same permission, and a policy that asked only "does this user hold
     * view draws" would answer yes to every logged-in player. Inside the panel that mistake
     * is invisible — the panel door refuses players before the policy is reached — but a
     * policy is consulted outside the panel as well: a `can:` route middleware, a Blade
     * @can, a queued action re-checked minutes later. In those places the panel door does
     * not exist, and a permissive answer here would be the only answer. So the policy asks
     * both questions, and it is deliberately stricter than the resource's static can*()
     * methods, which run with the panel already having admitted the user.
     */
    private function mayReachConsole(User $user, string $permission): bool
    {
        return AdminAccess::allows($user, $permission)
            && $user->hasAnyRole(AdminAccess::PANEL_ROLES);
    }
}
