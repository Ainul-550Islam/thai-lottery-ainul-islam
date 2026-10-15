<?php

declare(strict_types=1);

namespace App\DTOs\Security;

use JsonSerializable;
use LogicException;
use SensitiveParameter;

/** Ephemeral provisioning material for trusted callers, never authentication proof. */
final readonly class PendingTotpEnrollmentData implements JsonSerializable
{
    public function __construct(
        public string $reference,
        public int $expiresAtEpoch,
        #[SensitiveParameter] private string $secret,
    ) {}

    /** Explicit disclosure for a future authorized, no-store enrollment response. */
    public function revealSecret(): string
    {
        return $this->secret;
    }

    public function jsonSerialize(): array
    {
        return ['reference' => $this->reference, 'expires_at_epoch' => $this->expiresAtEpoch];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize() + ['secret' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new LogicException('Do not serialize TOTP provisioning material.');
    }
}
