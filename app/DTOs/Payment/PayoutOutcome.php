<?php

declare(strict_types=1);

namespace App\DTOs\Payment;

use App\Enums\ThaiBankPayoutState;

final readonly class PayoutOutcome
{
    /**
     * @param array<string, mixed> $rawSanitised
     */
    public function __construct(
        public ThaiBankPayoutState $state,
        public ?string $providerReference = null,
        public ?string $message = null,
        public array $rawSanitised = [],
    ) {}

    /** @param array<string, mixed> $rawSanitised */
    public static function accepted(?string $providerReference, array $rawSanitised = []): self
    {
        return new self(ThaiBankPayoutState::Accepted, $providerReference, null, $rawSanitised);
    }

    /** @param array<string, mixed> $rawSanitised */
    public static function settled(?string $providerReference, array $rawSanitised = []): self
    {
        return new self(ThaiBankPayoutState::Settled, $providerReference, null, $rawSanitised);
    }

    /** @param array<string, mixed> $rawSanitised */
    public static function rejected(?string $providerReference = null, ?string $message = null, array $rawSanitised = []): self
    {
        return new self(ThaiBankPayoutState::Rejected, $providerReference, $message, $rawSanitised);
    }

    /** @param array<string, mixed> $rawSanitised */
    public static function uncertain(?string $providerReference = null, array $rawSanitised = []): self
    {
        return new self(ThaiBankPayoutState::Uncertain, $providerReference, 'The bank outcome is uncertain and requires reconciliation.', $rawSanitised);
    }
}
