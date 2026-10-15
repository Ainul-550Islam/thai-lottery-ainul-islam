<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * MfaChallengeStatus — MFA challenge lifecycle.
 *
 * Pending and Failed can be verified, fail again, expire or become Locked.
 * Failed self-transitions represent another unsuccessful attempt.
 * Verified, Locked and Expired are terminal lifecycle states; reading a
 * Verified receipt is not a new verification or fresh authentication proof.
 */
enum MfaChallengeStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Failed = 'failed';
    case Expired = 'expired';
    case Locked = 'locked';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Verified, self::Failed, self::Expired, self::Locked],
            self::Failed => [self::Verified, self::Failed, self::Expired, self::Locked],
            self::Verified, self::Expired, self::Locked => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Whether this challenge can still accept a verification attempt.
     */
    public function acceptsVerification(): bool
    {
        return $this === self::Pending || $this === self::Failed;
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }
}
