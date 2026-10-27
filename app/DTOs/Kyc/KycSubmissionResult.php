<?php

declare(strict_types=1);

namespace App\DTOs\Kyc;

use App\Exceptions\KycProviderException;

/**
 * Typed answer to "I have received a member's identity submission".
 *
 * A KYC provider can answer one of exactly three ways, and the type refuses to
 * represent a fourth:
 *
 *   ACCEPTED       the submission is now queued for a decision. `reference` is
 *                  the platform's own reference — never a provider's opaque id
 *                  in a field a page might print.
 *   REFUSED        the submission was rejected at intake (duplicate, malformed).
 *                  The caller must not retry blindly.
 *   NOT_CONFIGURED no provider is wired, so nothing was submitted.
 *
 * There is no `approved` state here on purpose: an instant approval is a
 * decision, and decisions in this application belong to a reviewer writing a
 * KycVerification row, not to an intake call.
 */
final class KycSubmissionResult
{
    public const ACCEPTED = 'ACCEPTED';

    public const REFUSED = 'REFUSED';

    public const NOT_CONFIGURED = 'NOT_CONFIGURED';

    /**
     * @param  array<string, scalar|null>  $context  safe diagnostic scalars only
     */
    private function __construct(
        public readonly string $state,
        public readonly string $provider,
        public readonly ?string $reference,
        public readonly ?string $reasonCode,
        public readonly array $context = [],
    ) {}

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function accepted(string $provider, string $reference, array $context = []): self
    {
        return new self(self::ACCEPTED, $provider, $reference, null, $context);
    }

    public static function refused(string $provider, string $reasonCode): self
    {
        return new self(self::REFUSED, $provider, null, $reasonCode);
    }

    public static function notConfigured(string $provider): self
    {
        return new self(self::NOT_CONFIGURED, $provider, null, 'provider_not_configured');
    }

    public function isAccepted(): bool
    {
        return $this->state === self::ACCEPTED;
    }

    public function isNotConfigured(): bool
    {
        return $this->state === self::NOT_CONFIGURED;
    }

    /**
     * Serialise for a controller. The reason code is included; the provider's
     * raw response never is.
     *
     * @return array{state: string, provider: string, reference: string|null, reason_code: string|null}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'provider' => $this->provider,
            'reference' => $this->reference,
            'reason_code' => $this->reasonCode,
        ];
    }

    /**
     * A submission that must succeed, or the caller aborts.
     *
     * @throws KycProviderException
     */
    public function orFail(): self
    {
        if ($this->state === self::ACCEPTED) {
            return $this;
        }

        throw KycProviderException::submissionRefused(
            $this->provider,
            (string) ($this->reasonCode ?? $this->state),
        );
    }
}
