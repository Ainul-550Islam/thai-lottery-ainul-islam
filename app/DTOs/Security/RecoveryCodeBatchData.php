<?php

declare(strict_types=1);

namespace App\DTOs\Security;

use JsonSerializable;
use LogicException;
use SensitiveParameter;

/** One-time plaintext delivery to a trusted caller; never persist/queue this object. */
final readonly class RecoveryCodeBatchData implements JsonSerializable
{
    public function __construct(public string $batchReference, #[SensitiveParameter] private array $codes) {}

    /** Explicit disclosure for a future authorized no-store response. */
    public function revealCodes(): array
    {
        return $this->codes;
    }

    public function jsonSerialize(): array
    {
        return ['batch_reference' => $this->batchReference, 'code_count' => count($this->codes)];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize() + ['codes' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new LogicException('Do not serialize recovery-code material.');
    }
}
