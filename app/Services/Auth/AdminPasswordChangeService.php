<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Security\TotpActionProofInvalidationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/**
 * An operator setting a member's password from the admin panel.
 *
 * One of the few services allowed to write a password hash (the allowlist in
 * PasswordWriteCoverageService). An admin-set password is a credential
 * takeover-grade action, so the write carries the full password-write
 * ceremony, in one transaction and then its post-commit effects:
 *
 *   1. the target row is locked and re-read — the panel rendered its form
 *      from a possibly stale model, and the write must land on the account
 *      as it is now;
 *   2. the profile fields the operator submitted are applied together with
 *      the new hash, so the record never shows new details under an old
 *      credential or the reverse;
 *   3. the credential generation advances, ending every session and API
 *      credential the account holds (SessionGenerationRevocationService) —
 *      an operator who sets a password because an account is compromised
 *      must not leave the attacker's session alive;
 *   4. every outstanding TOTP action proof is invalidated.
 *
 * Authorization (who may do this to whom) is the caller's decision and is
 * checked before this service is invoked; this service owns the write.
 */
final class AdminPasswordChangeService
{
    public function __construct(
        private readonly SessionGenerationRevocationService $revocations,
        private readonly TotpActionProofInvalidationService $proofs,
    ) {}

    /**
     * @param  array<string, mixed>  $data  profile fields submitted alongside the password
     */
    public function change(
        User $operator,
        User $target,
        #[SensitiveParameter] string $password,
        array $data = [],
    ): User {
        unset($data['password']);

        $updated = DB::transaction(function () use ($target, $password, $data): User {
            $locked = User::query()->lockForUpdate()->find((int) $target->getKey());

            if (! $locked instanceof User) {
                throw new \RuntimeException('The account no longer exists.');
            }

            foreach ($data as $key => $value) {
                $locked->setAttribute((string) $key, $value);
            }

            $locked->password = Hash::make($password);
            $locked->remember_token = null;
            $locked->save();

            return $locked;
        });

        $this->revocations->revoke($operator, $updated, SessionGenerationRevocationService::REASON_PASSWORD_CHANGE);
        $this->proofs->invalidate($updated, TotpActionProofInvalidationService::PASSWORD_CHANGED);

        return $updated->refresh();
    }
}
