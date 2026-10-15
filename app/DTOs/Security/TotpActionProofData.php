<?php

declare(strict_types=1);

namespace App\DTOs\Security;

use JsonSerializable;
use LogicException;
use SensitiveParameter;

/** Trusted delivery only, after outer commit. Never persist/queue this object. */
final readonly class TotpActionProofData implements JsonSerializable
{
    public function __construct(
        public string $action,
        public int $expiresAtEpoch,
        #[SensitiveParameter] private string $token,
    ) {}

    public function revealToken(): string
    {
        return $this->token;
    }

    public function jsonSerialize(): array
    {
        return ['action' => $this->action, 'expires_at_epoch' => $this->expiresAtEpoch];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize() + ['token' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new LogicException('Do not serialize action-proof material.');
    }
}
