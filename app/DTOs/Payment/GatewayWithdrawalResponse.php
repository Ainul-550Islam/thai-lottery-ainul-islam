<?php

declare(strict_types=1);

namespace App\DTOs\Payment;

/**
 * Immutable DTO representing the outcome of an initiated gateway payout/withdrawal.
 */
final class GatewayWithdrawalResponse
{
    /**
     * @param  array<string, mixed>  $rawResponse
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly bool $successful,
        public readonly bool $isPending = false,
        public readonly ?string $providerReference = null,
        public readonly ?string $errorMessage = null,
        public readonly array $rawResponse = [],
        public readonly array $metadata = [],
        /**
         * True when the platform does not know what the provider did. The hold
         * and the reservation must then stay in place until provider evidence is
         * obtained; it is never a licence to resend or release.
         */
        public readonly bool $outcomeUnknown = false,
    ) {}

    public static function completed(
        string $providerReference,
        array $rawResponse = [],
        array $metadata = [],
    ): self {
        return new self(
            successful: true,
            isPending: false,
            providerReference: $providerReference,
            rawResponse: $rawResponse,
            metadata: $metadata,
        );
    }

    public static function pending(
        string $providerReference,
        array $rawResponse = [],
        array $metadata = [],
    ): self {
        return new self(
            successful: true,
            isPending: true,
            providerReference: $providerReference,
            rawResponse: $rawResponse,
            metadata: $metadata,
        );
    }

    /**
     * The provider gave no final answer: a transport timeout, a dropped
     * connection, or a success reply whose provider reference cannot be used.
     *
     * This is deliberately NOT `completed()` and NOT `failed()`:
     *  - `failed()` would let the caller release the member's hold and free the
     *    reservation, but the money may already have left the platform.
     *  - `completed()` would debit the wallet, but the payout is not proved.
     * It is pending-with-unknown-state: the hold and reservation stay in place,
     * the obligation stays Processing, and only provider evidence obtained
     * through the reconciliation workflow may settle it. A known provider
     * reference is still carried so the identity can be reserved against
     * another withdrawal claiming the same reference.
     */
    public static function uncertain(
        ?string $providerReference = null,
        array $rawResponse = [],
        array $metadata = [],
    ): self {
        return new self(
            successful: false,
            isPending: true,
            providerReference: $providerReference,
            errorMessage: 'The payout provider did not return a final outcome; reconciliation is required.',
            outcomeUnknown: true,
            rawResponse: $rawResponse,
            metadata: array_merge([
                'state' => 'unknown',
                'reconciliation_required' => true,
            ], $metadata),
        );
    }

    public static function failed(
        string $errorMessage,
        array $rawResponse = [],
        array $metadata = [],
    ): self {
        return new self(
            successful: false,
            errorMessage: $errorMessage,
            rawResponse: $rawResponse,
            metadata: $metadata,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'successful' => $this->successful,
            'is_pending' => $this->isPending,
            'provider_reference' => $this->providerReference,
            'error_message' => $this->errorMessage,
            'outcome_unknown' => $this->outcomeUnknown,
            'metadata' => $this->metadata,
        ];
    }
}
