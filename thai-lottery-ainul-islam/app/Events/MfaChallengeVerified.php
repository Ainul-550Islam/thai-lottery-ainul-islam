<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\MfaChallenge;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Synchronous in-transaction notification for the legacy challenge ledger.
 * A listener exception or outer rollback can undo database writes; a retry
 * may dispatch again. This is neither an exactly-once delivery contract nor
 * fresh enrolled-factor proof. Listeners must not perform irreversible I/O;
 * future external delivery needs a separately designed durable outbox.
 */
final class MfaChallengeVerified
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly MfaChallenge $challenge,
        public readonly string $verifiedAt,
    ) {}

    /**
     * THE ANCHOR: this verification pronouncement may audit once.
     */
    public function verificationFingerprint(): string
    {
        return hash('sha256', 'glo-mfa-ver|'.(string) $this->challenge->challenge_key.'|'.$this->verifiedAt);
    }
}
