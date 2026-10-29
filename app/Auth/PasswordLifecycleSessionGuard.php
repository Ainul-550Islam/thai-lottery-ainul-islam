<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use SensitiveParameter;

/**
 * Session guard that cannot resurrect a superseded password at login.
 *
 * WHY THIS EXISTS
 * The stock Laravel 12 sequence is: retrieve a user model, validate the
 * submitted password against that model's hash, fire `Validated`, then —
 * when the stored hash no longer matches the configured algorithm/cost —
 * `forceFill()` a fresh hash of the submitted password onto THE SAME
 * possibly-stale model and `save()` it. If the password was changed after
 * the model was retrieved (by PasswordChangeService, an admin edit, a
 * reset, or a `Validated` listener in another flow), that save writes the
 * OLD password's new hash over the NEW password. The account silently
 * reverts to the superseded credential and the legitimate new password
 * stops working. Part 50 reproduced this with a diagnostic.
 *
 * WHAT THIS GUARD CHANGES (and only this)
 * Credential-bearing entry points (`attempt`, `attemptWhen`, `once`,
 * `validate`) re-read the persisted account AFTER validation/callbacks
 * and BEFORE any login state is issued, and require the submitted
 * password to still verify against the persisted hash at that moment.
 * The automatic rehash is a compare-and-swap: it updates the row only
 * where the stored hash still equals the hash that was just verified, so
 * a concurrent password change can never be overwritten by it. When the
 * persisted credential no longer matches, the attempt FAILS (the same
 * outward outcome as a wrong password) instead of issuing a session
 * bound to a superseded hash.
 *
 * WHAT THIS DOES NOT CHANGE
 * Event order and names (`Attempting`, `Validated`, `Login`, `Failed`),
 * the timebox, remember-me handling, session regeneration and the
 * `password_hash_{guard}` session binding all remain the framework's.
 * Direct `login($user)` calls perform no credential check in the stock
 * guard and still perform none here; Part 50's `auth.session` middleware
 * remains the control that rejects sessions bound to stale hashes.
 * Non-Eloquent authenticatables keep the exact stock behaviour, because
 * there is no persisted row to compare-and-swap.
 *
 * CONCURRENCY BOUNDARY (stated, not implied)
 * The compare-and-swap makes the REHASH write atomic on any engine that
 * honours single-statement conditional updates. It does not serialize a
 * login against a password change that commits in the small window
 * between the final verification read and the session write; such a
 * login may still complete, but its session is bound to the hash that
 * was current at verification, so `auth.session` rejects it on the next
 * covered request, and the stored password is never reverted. Real
 * MySQL/PostgreSQL multi-worker behaviour remains an operations
 * verification item in the remaining-work register.
 */
class PasswordLifecycleSessionGuard extends SessionGuard
{
    /**
     * Issue the session, then stamp the credential generation onto it.
     *
     * Part 57. The stock login already records `password_hash_{guard}`; this
     * adds `credential_epoch_{guard}`, the value of the account's monotonic
     * counter at the moment the session was issued. The two keys answer
     * different questions: the hash says "this session carries the current
     * credential", the epoch says "this session belongs to generation N".
     * Only the epoch can be advanced without changing the password, which is
     * what makes deterministic revocation possible.
     *
     * Authenticatables without a declared credential generation (non-
     * Eloquent providers, fixture models that predate the column) are left
     * exactly as the framework handles them.
     */
    public function login(AuthenticatableContract $user, $remember = false)
    {
        parent::login($user, $remember);

        $this->recordCredentialEpoch($user);
    }

    /**
     * Attempt to authenticate a user using the given credentials.
     *
     * Mirrors the stock implementation, with the password-lifecycle
     * check inserted between credential validation and login.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function attempt(array $credentials = [], $remember = false)
    {
        return $this->timebox->call(function ($timebox) use ($credentials, $remember) {
            $this->fireAttemptEvent($credentials, $remember);

            $this->lastAttempted = $user = $this->provider->retrieveByCredentials($credentials);

            if ($this->hasValidCredentials($user, $credentials)
                && $user instanceof AuthenticatableContract
                && $this->synchronizePasswordState($user, $credentials)) {
                $this->login($user, $remember);

                $timebox->returnEarly();

                return true;
            }

            $this->fireFailedEvent($user, $credentials);

            return false;
        }, $this->timeboxDuration);
    }

    /**
     * Attempt to authenticate a user with credentials and callbacks.
     *
     * The lifecycle check runs AFTER the caller's callbacks as well:
     * a callback that changes the password must not be followed by a
     * login for the superseded credential.
     *
     * @param  array<string, mixed>  $credentials
     * @param  array<callable>|callable|null  $callbacks
     */
    public function attemptWhen(array $credentials = [], $callbacks = null, $remember = false)
    {
        return $this->timebox->call(function ($timebox) use ($credentials, $callbacks, $remember) {
            $this->fireAttemptEvent($credentials, $remember);

            $this->lastAttempted = $user = $this->provider->retrieveByCredentials($credentials);

            if ($this->hasValidCredentials($user, $credentials)
                && $user instanceof AuthenticatableContract
                && $this->shouldLogin($callbacks, $user)
                && $this->synchronizePasswordState($user, $credentials)) {
                $this->login($user, $remember);

                $timebox->returnEarly();

                return true;
            }

            $this->fireFailedEvent($user, $credentials);

            return false;
        }, $this->timeboxDuration);
    }

    /**
     * Validate a user's credentials without logging them in.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function validate(array $credentials = [])
    {
        $validated = parent::validate($credentials);

        if (! $validated || ! $this->lastAttempted instanceof AuthenticatableContract) {
            return $validated;
        }

        // Verification only here: `once()` applies the rehash through
        // the guarded override below, and a bare `validate()` caller
        // must not mutate the stored credential as a side effect.
        return $this->passwordStillCurrent($this->lastAttempted, $credentials);
    }

    /**
     * Log a user into the application without sessions or cookies.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function once(array $credentials = [])
    {
        $this->fireAttemptEvent($credentials);

        if ($this->validate($credentials)
            && $this->lastAttempted instanceof AuthenticatableContract
            && $this->synchronizePasswordState($this->lastAttempted, $credentials)) {
            $this->setUser($this->lastAttempted);

            return true;
        }

        $this->fireFailedEvent($this->lastAttempted, $credentials);

        return false;
    }

    /**
     * Rehash entry point kept for framework callers (e.g. other-device
     * logout re-verification). It can only ever write through the
     * compare-and-swap; it never force-fills a stale model.
     *
     * @param  array<string, mixed>  $credentials
     */
    protected function rehashPasswordIfRequired(AuthenticatableContract $user, #[SensitiveParameter] array $credentials)
    {
        if (! $this->rehashOnLogin) {
            return;
        }

        $this->synchronizePasswordState($user, $credentials);
    }

    /**
     * Whether the submitted password still verifies against the row as
     * currently persisted, syncing the in-memory model to that row.
     *
     * @param  array<string, mixed>  $credentials
     */
    private function passwordStillCurrent(AuthenticatableContract $user, #[SensitiveParameter] array $credentials): bool
    {
        if (! $user instanceof Model) {
            return true; // No persisted row to compare: stock behaviour.
        }

        $plain = Arr::get($credentials, 'password');

        if (! is_string($plain) || $plain === '') {
            return false;
        }

        $fresh = $this->freshModel($user);

        if ($fresh === null) {
            return false;
        }

        if (! $this->provider->validateCredentials($fresh, $credentials)) {
            return false;
        }

        $this->syncPasswordAttribute($user, $fresh);

        return true;
    }

    /**
     * Verify-then-rehash as one guarded step.
     *
     * Returns false when the persisted credential no longer matches the
     * submitted password, or when a needed rehash loses its
     * compare-and-swap race. On success the in-memory model's password
     * attribute equals the persisted hash, so the session binding that
     * `login()` records is the current one.
     *
     * @param  array<string, mixed>  $credentials
     */
    private function synchronizePasswordState(AuthenticatableContract $user, #[SensitiveParameter] array $credentials): bool
    {
        if (! $user instanceof Model || ! $this->provider instanceof EloquentUserProvider) {
            // Nothing safe to compare-and-swap: keep the stock rehash.
            if ($this->rehashOnLogin) {
                $this->provider->rehashPasswordIfRequired($user, $credentials);
            }

            return true;
        }

        if (! $this->passwordStillCurrent($user, $credentials)) {
            return false;
        }

        $hasher = $this->provider->getHasher();
        $passwordName = $user->getAuthPasswordName();
        $currentHash = (string) $user->getAuthPassword();

        if (! $this->rehashOnLogin || ! $hasher->needsRehash($currentHash)) {
            return true;
        }

        $plain = Arr::get($credentials, 'password');

        if (! is_string($plain) || $plain === '') {
            return false;
        }

        $updates = [$passwordName => $hasher->make($plain)];

        if ($user->timestamps) {
            $updates[$user->getUpdatedAtColumn()] = $user->freshTimestamp();
        }

        $updated = $user->getConnection()
            ->table($user->getTable())
            ->where($user->getKeyName(), $user->getKey())
            ->where($passwordName, $currentHash)
            ->update($updates);

        if ($updated !== 1) {
            // The row changed between verification and write. Never
            // overwrite it; refuse the attempt instead.
            return false;
        }

        $attributes = $user->getAttributes();
        $attributes[$passwordName] = $updates[$passwordName];

        if ($user->timestamps) {
            $attributes[$user->getUpdatedAtColumn()] = $updates[$user->getUpdatedAtColumn()];
        }

        $user->setRawAttributes($attributes, true);

        return true;
    }

    /**
     * A freshly-read copy of the same persisted account, or null.
     */
    private function freshModel(Model $user): ?Model
    {
        /** @var Model|null $fresh */
        $fresh = $user->newQuery()
            ->useWritePdo()
            ->where($user->getKeyName(), $user->getKey())
            ->first();

        return $fresh;
    }

    /**
     * Copy the persisted password hash (and only it) onto the model the
     * guard is about to log in, without triggering the `hashed` cast.
     */
    private function syncPasswordAttribute(Model $target, Model $fresh): void
    {
        $passwordName = $target->getAuthPasswordName();
        $attributes = $target->getAttributes();
        $attributes[$passwordName] = $fresh->getRawOriginal($passwordName);
        $target->setRawAttributes($attributes, true);
    }

    /**
     * Stamp the account's credential generation onto the issued session.
     *
     * Reads the raw attribute rather than the cast value so a model that
     * predates the column is simply skipped instead of being stamped with a
     * fabricated zero.
     */
    private function recordCredentialEpoch(AuthenticatableContract $user): void
    {
        // Authenticatables without a declared credential generation (non-
        // Eloquent providers, fixture models that predate the column) keep
        // the framework's hash-only binding. The cast list — not the loaded
        // attributes — decides this: a freshly created model carries the
        // column's default only in the database, so an attribute check would
        // silently skip stamping and retire every such session on its first
        // covered request.
        if (! $user instanceof Model || ! array_key_exists('credential_epoch', $user->getCasts())) {
            return;
        }

        $attributes = $user->getAttributes();

        // Every login path reaches this with the model read during credential
        // verification, so the value is normally already loaded. If a caller
        // logged in an in-memory model instead, read the persisted row rather
        // than stamping a generation the account does not have.
        $epoch = array_key_exists('credential_epoch', $attributes)
            ? (int) $attributes['credential_epoch']
            : (int) ($user->exists
                ? $user->newQuery()->whereKey($user->getKey())->value('credential_epoch')
                : 0);

        $this->session->put('credential_epoch_'.$this->name, $epoch);
    }
}
