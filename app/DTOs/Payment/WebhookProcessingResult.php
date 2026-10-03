<?php

declare(strict_types=1);

namespace App\DTOs\Payment;

use App\Models\FinancialTransaction;

/**
 * Outcome of processing one inbound payment webhook.
 *
 * Deliberately separate from PaymentProcessingResult. That DTO describes the
 * richer gateway-envelope pipeline (PaymentWebhookService::processEnvelope);
 * this one describes the narrow credit-a-wallet-once contract that
 * ProcessPaymentWebhookService exposes, and it names the replay explicitly so
 * a caller cannot mistake "already applied" for "applied again".
 */
final class WebhookProcessingResult
{
    /**
     * @param  bool  $successful  the webhook was understood and its effect is now in place
     * @param  bool  $replayed  the effect was ALREADY in place; this call wrote nothing
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly bool $successful,
        public readonly bool $replayed,
        public readonly string $provider,
        public readonly string $externalId,
        public readonly string $actionTaken,
        public readonly ?FinancialTransaction $transaction = null,
        public readonly ?string $message = null,
        public readonly array $metadata = [],
    ) {}

    /**
     * The webhook was applied for the first time.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function applied(
        string $provider,
        string $externalId,
        ?FinancialTransaction $transaction,
        string $actionTaken = 'credited',
        array $metadata = [],
    ): self {
        return new self(
            successful: true,
            replayed: false,
            provider: $provider,
            externalId: $externalId,
            actionTaken: $actionTaken,
            transaction: $transaction,
            metadata: $metadata,
        );
    }

    /**
     * The webhook had already been applied. Nothing was written.
     *
     * This is a SUCCESS, not a failure: a provider retrying a delivery it was
     * never acknowledged for is behaving correctly, and answering it with an
     * error would make it retry forever.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function replay(
        string $provider,
        string $externalId,
        ?FinancialTransaction $transaction,
        string $actionTaken = 'replayed',
        array $metadata = [],
    ): self {
        return new self(
            successful: true,
            replayed: true,
            provider: $provider,
            externalId: $externalId,
            actionTaken: $actionTaken,
            transaction: $transaction,
            message: 'Webhook already processed; no money moved.',
            metadata: $metadata,
        );
    }

    /**
     * The webhook was understood but intentionally produced no credit --
     * a pending, failed or cancelled payment notification.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function ignored(
        string $provider,
        string $externalId,
        string $reason,
        array $metadata = [],
    ): self {
        return new self(
            successful: true,
            replayed: false,
            provider: $provider,
            externalId: $externalId,
            actionTaken: 'ignored',
            transaction: null,
            message: $reason,
            metadata: $metadata,
        );
    }

    /**
     * The webhook could not be processed.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function failed(
        string $provider,
        string $externalId,
        string $reason,
        array $metadata = [],
    ): self {
        return new self(
            successful: false,
            replayed: false,
            provider: $provider,
            externalId: $externalId,
            actionTaken: 'failed',
            transaction: null,
            message: $reason,
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
            'replayed' => $this->replayed,
            'provider' => $this->provider,
            'external_id' => $this->externalId,
            'action_taken' => $this->actionTaken,
            'transaction_id' => $this->transaction?->getKey(),
            'message' => $this->message,
            'metadata' => $this->metadata,
        ];
    }
}
