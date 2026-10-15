<?php

declare(strict_types=1);

namespace App\Http\Middleware\Concerns;

use App\Enums\UserStatus;
use App\Models\User;

/**
 * Per-request account-status gate for session-authenticated routes — Part 60.
 *
 * WHY THIS EXISTS
 * `EnsureUserIsActive` exists and is registered as the `active` alias, but it is
 * applied only to the Sanctum API routes: no web route re-checked account
 * status per request. Parts 58 and 59 made a status decision end the account's
 * sessions, which is the right primary control — but revocation is an ACTION,
 * and an action can fail or be skipped. The panel reports a failed revocation as
 * a separate visible fact rather than rolling the status change back, and any
 * writer that sets `users.status` without going through the shared service
 * leaves the session standing. This trait is the check that does not depend on
 * that revocation having happened: it reads the account, on every covered
 * request, and refuses it if the account can no longer log in.
 *
 * WHAT IT DOES
 * If the request carries an authenticated account whose status cannot log in —
 * `UserStatus::canLogin()` is false, i.e. Inactive, Suspended or Banned — and
 * the request arrived either with a persisted session row (its session cookie)
 * or through a remember-me recaller, it is logged out through the stock
 * middleware's own logout path. That path removes the session key, unqueues AND
 * forgets the remember-me cookie, and cycles the remember token, so a suspended
 * account cannot simply come back with the recaller it arrived with.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 * - PendingVerification keeps working. `canLogin()` admits it, and signing that
 *   account out would break registration and email verification. This is the
 *   single most important non-behaviour in the file.
 * - It does NOT use `UserStatus::canTransact()`. That is the stricter panel and
 *   transaction rule; applying it here would sign out every account that is
 *   merely awaiting verification.
 * - Guests and in-process authentication (framework test `actingAs`,
 *   programmatic guard use) are untouched: they present neither a session cookie
 *   nor a recaller, so there is nothing persisted to retire. This is the same
 *   distinction EnforcesPasswordSessionBinding documents, applied to a slightly
 *   wider gate — see the note inside accountHasLostAccess().
 * - Authenticatables that are not the Eloquent `User` model, or that carry no
 *   `UserStatus`, keep stock behaviour.
 *
 * WHY THIS IS A TRAIT AND NOT ITS OWN MIDDLEWARE
 * It is used by the same two classes that already run on every covered surface
 * — `AuthenticateBoundSession` (web `auth.session`) and
 * `FilamentAuthenticateBoundSession` (admin panel) — so it inherits Part 50's
 * explicit routes, Part 53's broadcasting and betting redirects and Part 54/55's
 * Livewire endpoints without a single route change, and its coverage cannot
 * drift from the binding check's. Both traits would otherwise define `handle()`,
 * which PHP rejects, so the entry point stays in
 * EnforcesPasswordSessionBinding and calls this one.
 *
 * MEASURED LIMIT ON THE ADMIN PANEL
 * On a panel request Filament's own `Filament\Http\Middleware\Authenticate`
 * enforces `canAccessPanel()` — `AdminAccess` requires an Active account — and
 * aborts 403 BEFORE this middleware is reached, so a suspended operator is
 * refused by the panel and this gate does not fire there. What was measured, not
 * assumed: the request answers 403, the session row is left standing, and it is
 * retired on the operator's next member route, where this gate does run. Both
 * halves are asserted in SessionAccountStatusEnforcementTest.
 */
trait EnforcesAccountStatusForSession
{
    /**
     * Whether this request presents a persisted session belonging to an account
     * that can no longer log in.
     */
    private function accountHasLostAccess($request): bool
    {
        if (! $request->hasSession() || ! $request->user()) {
            return false;
        }

        // The gate is deliberately NOT the same as the binding check's. That one
        // asks "is there a persisted session row to retire?", so a session cookie
        // is the whole question. This one asks "may this ACCOUNT still be signed
        // in?", which is also true of a request authenticated by a remember-me
        // recaller and carrying no session cookie at all. Requiring the cookie
        // here would let a suspended account walk straight back in with its
        // recaller. In-process authentication (framework test `actingAs`,
        // programmatic guard use) has neither, and stays untouched.
        if (! $request->cookies->has((string) config('session.cookie'))
            && ! $this->guard()->viaRemember()) {
            return false;
        }

        $user = $request->user();

        if (! $user instanceof User) {
            return false;
        }

        $status = $user->status;

        return $status instanceof UserStatus && ! $status->canLogin();
    }
}
