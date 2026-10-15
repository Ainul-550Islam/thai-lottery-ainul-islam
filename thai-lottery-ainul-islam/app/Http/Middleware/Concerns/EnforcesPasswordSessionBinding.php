<?php

declare(strict_types=1);

namespace App\Http\Middleware\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Fail-closed password-session binding for session-authenticated routes.
 *
 * WHY THIS EXISTS
 * The stock Illuminate AuthenticateSession middleware COMPARES the
 * session's `password_hash_{guard}` binding when one exists — but when
 * the key is absent it silently ADOPTS the current password hash into
 * the session and lets the request through. That adoption exists for
 * framework backward compatibility. Its security cost: any session
 * created before password binding was recorded (legacy sessions), or
 * any serialized session row restored without the key, is trusted as
 * fully password-current without ever having proved it.
 *
 * WHAT THIS TRAIT CHANGES (and only this)
 * If the request presents a persisted session (its session cookie), the
 * session carries an authenticated user with a password, the request
 * was NOT authenticated via a remember-me recaller cookie, and the
 * session holds no password binding for the default guard, the request
 * is logged out through the stock middleware's own logout path
 * (current-device logout, session flush, AuthenticationException)
 * instead of adopting a binding. Everything else — the recaller check,
 * the hash comparison, the post-response refresh — is the stock
 * implementation, reached via `parent::handle()`.
 *
 * WHAT PART 57 ADDS
 * A second, independent check on the session's credential GENERATION. The
 * account carries a monotonic `credential_epoch` counter that every
 * credential writer advances inside its own transaction, and a session
 * records the value that was current when it was issued
 * (`credential_epoch_{guard}`). A session from an earlier generation is
 * retired even when its stored hash still matches the account's current
 * hash, which is what makes revocation possible without changing the
 * password at all. Accounts whose model has no such column keep the
 * hash-only behaviour, and remember-me requests stay exempt for the reason
 * given below.
 *
 * WHAT PART 60 ADDS
 * A third check, kept in its own trait: the account's STATUS is re-read on every
 * covered request, so a suspension, ban or deactivation takes effect on the next
 * request even if the revocation that should have ended the session failed or
 * was skipped. `handle()` above is the single entry point for both checks,
 * because PHP will not accept two traits that define the same method.
 *
 * WHY THE SESSION COOKIE GATES THE CHECK
 * A legacy session can only reach this middleware as a persisted store
 * row, and a persisted row is only loaded when the browser presents
 * the session cookie. Authentication established in-process for the
 * current request alone (framework test `actingAs`, programmatic guard
 * use) presents no cookie and has no persisted row to retire; treating
 * it as legacy would break that legitimate context without retiring
 * anything real. An attacker gains nothing from the distinction: a
 * session id they did not receive from the server authenticates no one.
 *
 * WHY THE RECALLER IS EXEMPT
 * A remember-me recaller cookie carries its own password-hash segment,
 * which the stock middleware validates against the current password
 * BEFORE the binding check. A recaller-authenticated request therefore
 * proves password currency through the cookie; forcing a logout there
 * would break every legitimate "remember me" return visit. The stock
 * flow then stores the binding, so later requests on that session are
 * bound normally.
 *
 * ROLLOUT NOTE (deliberate, stated)
 * Enabling this retires every pre-binding session once: affected users
 * simply log in again, and Part 51's lifecycle guard records a current
 * binding at that login. No session is silently trusted, and no new
 * login flow changes shape. Sessions written by any current login
 * (member, Filament, or direct `SessionGuard::login()`, which records
 * the binding itself) are unaffected.
 *
 * Used by App\Http\Middleware\AuthenticateBoundSession (web routes,
 * alias `auth.session`) and App\Http\Middleware\
 * FilamentAuthenticateBoundSession (admin panel).
 */
trait EnforcesPasswordSessionBinding
{
    /**
     * @param  Closure(Request): mixed  $next
     */
    public function handle($request, Closure $next)
    {
        // Two independent reasons to retire a session, one entry point. PHP
        // rejects two traits that both define handle(), so this stays the only
        // handle() and delegates: the generation check lives below, the account
        // status check in EnforcesAccountStatusForSession. The method_exists
        // guard keeps this trait usable on a class that does not also use the
        // Part 60 status trait.
        if ($this->sessionGenerationIsStale($request)
            || (method_exists($this, 'accountHasLostAccess') && $this->accountHasLostAccess($request))) {
            $this->logout($request);
        }

        return parent::handle($request, $next);
    }

    /**
     * Whether this request presents a persisted session that no longer
     * belongs to the account's current credential generation.
     */
    private function sessionGenerationIsStale($request): bool
    {
        // No persisted session row, no authenticated user, no password, or a
        // remember-me cookie that already proved password currency: nothing
        // to retire here. See the trait docblock for each condition.
        if (! $request->hasSession()
            || ! $request->cookies->has((string) config('session.cookie'))
            || ! $request->user()
            || ! $request->user()->getAuthPassword()
            || $this->guard()->viaRemember()) {
            return false;
        }

        $driver = $this->auth->getDefaultDriver();

        // Part 52: a session with no binding at all is retired rather than
        // silently adopted.
        if (! $request->session()->has('password_hash_'.$driver)) {
            return true;
        }

        $user = $request->user();
        $attributes = $user instanceof Model ? $user->getAttributes() : [];

        // Models without the column (non-Eloquent authenticatables, fixtures
        // that predate the migration) keep the hash-only comparison.
        if (! array_key_exists('credential_epoch', $attributes)) {
            return false;
        }

        // Part 57: a session issued before the current generation is dead,
        // including the pre-epoch sessions that carry no key at all.
        if (! $request->session()->has('credential_epoch_'.$driver)) {
            return true;
        }

        return (int) $request->session()->get('credential_epoch_'.$driver) !== (int) $attributes['credential_epoch'];
    }
}
