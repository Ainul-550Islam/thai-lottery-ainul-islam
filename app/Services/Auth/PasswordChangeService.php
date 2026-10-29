<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Rules\StrongPasswordRule;
use App\Services\Security\TotpActionProofInvalidationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * A member changing their OWN password.
 *
 * This is one of the few services allowed to write a password hash (the
 * allowlist in PasswordWriteCoverageService), and it earns that place by
 * doing everything a password write must do, in one transaction:
 *
 *   1. lock the account row (lockForUpdate) so a concurrent login, reset or
 *      admin edit cannot interleave with the credential swap;
 *   2. verify the CURRENT password against the locked row — never against a
 *      model a caller loaded earlier;
 *   3. write the new hash and clear the remember token;
 *   4. advance the credential generation, ending every other session and
 *      API credential (SessionGenerationRevocationService);
 *   5. invalidate every outstanding TOTP action proof, which was minted
 *      under the old credential.
 *
 * The service also enforces the centralized password-strength policy, so a
 * future caller cannot bypass it by omitting request validation.
 */
final class PasswordChangeService
{
    public function __construct(
        private readonly SessionGenerationRevocationService $revocations,
        private readonly TotpActionProofInvalidationService $proofs,
    ) {}

    /**
     * @throws ValidationException when the current password does not verify
     */
    public function change(
        User $user,
        #[SensitiveParameter] string $currentPassword,
        #[SensitiveParameter] string $newPassword,
    ): User {
        Validator::make(['password' => $newPassword], ['password' => ['required', 'string', new StrongPasswordRule]])->validate();

        return DB::transaction(function () use ($user, $currentPassword, $newPassword): User {
            $locked = User::query()->lockForUpdate()->find((int) $user->getKey());

            if (! $locked instanceof User) {
                throw new \RuntimeException('The account no longer exists.');
            }

            if (! Hash::check($currentPassword, (string) $locked->password)) {
                throw ValidationException::withMessages([
                    'current_password' => __('The current password is incorrect.'),
                ]);
            }

            $locked->password = Hash::make($newPassword);
            $locked->remember_token = null;
            if (! $locked->save()) {
                throw new \RuntimeException('Password change persistence was refused.');
            }

            $this->revocations->revoke($locked, $locked, SessionGenerationRevocationService::REASON_PASSWORD_CHANGE);
            $this->proofs->invalidate($locked, TotpActionProofInvalidationService::PASSWORD_CHANGED);

            return $locked->refresh();
        });
    }
}
