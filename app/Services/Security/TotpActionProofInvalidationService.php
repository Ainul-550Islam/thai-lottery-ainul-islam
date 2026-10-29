<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\TotpActionProof;
use App\Models\TotpCredential;
use App\Models\TotpPendingEnrollment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Durable, transaction-coupled revocation of TOTP action proofs.
 *
 * The authoritative proofs are SQL rows, not cache entries. Writers and
 * invalidators acquire the user row before the TOTP credential row; password
 * writers invoke this inside the same SQL transaction as their credential and
 * session revocation. A rollback therefore restores both the password and its
 * proofs, and losing cache cannot resurrect either a proof or its generation.
 */
final class TotpActionProofInvalidationService
{
    public const PASSWORD_CHANGED = 'password_changed';

    public const TOTP_CHANGED = 'totp_changed';

    public const OPERATOR_REVOCATION = 'operator_revocation';

    public function invalidate(User $user, string $reason = self::PASSWORD_CHANGED): void
    {
        if (! in_array($reason, [self::PASSWORD_CHANGED, self::TOTP_CHANGED, self::OPERATOR_REVOCATION], true)) {
            throw new \InvalidArgumentException('Unknown TOTP proof invalidation reason.');
        }

        DB::transaction(function () use ($user, $reason): void {
            $locked = User::query()->useWritePdo()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $credential = TotpCredential::query()->where('user_id', $locked->getKey())->lockForUpdate()->first();
            if ($credential instanceof TotpCredential) {
                TotpActionProof::query()->where('totp_credential_id', $credential->getKey())->delete();
            }
            // An unconfirmed enrollment initiated under an old password must
            // not be completable after an account recovery or password change.
            if ($reason === self::PASSWORD_CHANGED) {
                TotpPendingEnrollment::query()->where('user_id', $locked->getKey())->delete();
            }
            $locked->forceFill([
                'totp_proof_epoch' => (int) $locked->totp_proof_epoch + 1,
                'totp_proof_invalidated_at' => now()->getTimestamp(),
                'totp_proof_invalidation_reason' => $reason,
            ]);
            if (! $locked->save()) {
                throw new \RuntimeException('TOTP proof revocation could not be persisted.');
            }
        });
    }

    /** The current durable revocation generation; never fall back to cache. */
    public function generation(User $user): int
    {
        return (int) User::query()->useWritePdo()->whereKey($user->getKey())->firstOrFail()->totp_proof_epoch;
    }

    /** A caller must prove the exact current generation, not a future value. */
    public function accepts(User $user, int $mintedGeneration): bool
    {
        return $mintedGeneration >= 0 && $mintedGeneration === $this->generation($user);
    }

    /** @return array{reason: string, invalidated_at: int}|null */
    public function lastInvalidation(User $user): ?array
    {
        $locked = User::query()->useWritePdo()->whereKey($user->getKey())->firstOrFail();
        if ($locked->totp_proof_invalidation_reason === null || $locked->totp_proof_invalidated_at === null) {
            return null;
        }

        return [
            'reason' => (string) $locked->totp_proof_invalidation_reason,
            'invalidated_at' => (int) $locked->totp_proof_invalidated_at,
        ];
    }
}
