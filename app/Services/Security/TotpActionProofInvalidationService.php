<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Revocation of outstanding TOTP action proofs.
 *
 * An action proof (TotpActionProofData) says "this member recently proved
 * possession of their authenticator for THIS sensitive action". A proof is
 * delivered ephemerally and is never persisted by the DTO itself, so the
 * revocation side needs its own durable marker: a per-member generation
 * counter in the cache. `invalidate()` bumps the generation and stamps the
 * reason and time; any proof minted under an older generation is dead on
 * arrival wherever it is presented.
 *
 * WHY PASSWORD CHANGE INVALIDATES EVERYTHING
 * A password change — above all a password RESET — is the moment an account
 * may have changed hands. Every outstanding proof was minted under the old
 * credential; letting one survive would let a pre-reset proof authorise a
 * post-reset action, which is precisely the session the reset exists to kill.
 * PasswordResetService therefore calls invalidate() inside the same
 * transaction boundary as the credential write and the session revocation.
 *
 * The cache is the right store for this: proofs are short-lived by design,
 * so their revocation marker only has to outlive the longest proof TTL,
 * not the account.
 */
final class TotpActionProofInvalidationService
{
    /** Reason recorded when a password change/reset kills every proof. */
    public const PASSWORD_CHANGED = 'password_changed';

    /** Reason recorded when the member's authenticator itself changes. */
    public const TOTP_CHANGED = 'totp_changed';

    /** Reason recorded for an operator-forced revocation. */
    public const OPERATOR_REVOCATION = 'operator_revocation';

    /** How long a revocation marker is retained (seconds): 24 hours. */
    private const MARKER_TTL_SECONDS = 86400;

    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * Kill every outstanding action proof for the member and record why.
     */
    public function invalidate(User $user, string $reason = self::PASSWORD_CHANGED): void
    {
        $id = (int) $user->getKey();

        $this->cache->put($this->generationKey($id), $this->generation($user) + 1, self::MARKER_TTL_SECONDS);
        $this->cache->put($this->markerKey($id), [
            'reason' => $reason,
            'invalidated_at' => now()->getTimestamp(),
        ], self::MARKER_TTL_SECONDS);
    }

    /**
     * The member's current proof generation. A proof carries the generation
     * it was minted under; anything below this value has been revoked.
     */
    public function generation(User $user): int
    {
        $value = $this->cache->get($this->generationKey((int) $user->getKey()), 0);

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }

    /**
     * Whether a proof minted at $mintedGeneration is still acceptable.
     */
    public function accepts(User $user, int $mintedGeneration): bool
    {
        return $mintedGeneration >= $this->generation($user);
    }

    /**
     * The last invalidation marker, if any: {reason, invalidated_at}.
     *
     * @return array{reason: string, invalidated_at: int}|null
     */
    public function lastInvalidation(User $user): ?array
    {
        $marker = $this->cache->get($this->markerKey((int) $user->getKey()));

        return is_array($marker) && isset($marker['reason'], $marker['invalidated_at']) ? $marker : null;
    }

    private function generationKey(int $userId): string
    {
        return 'security.totp_action_proofs.generation.'.$userId;
    }

    private function markerKey(int $userId): string
    {
        return 'security.totp_action_proofs.invalidated.'.$userId;
    }
}
