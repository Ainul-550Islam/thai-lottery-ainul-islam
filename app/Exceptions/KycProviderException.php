<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Domain failure for the KYC provider lane.
 *
 * The important distinction this class makes is between "the provider is not
 * wired" (a configuration fact that must be shown to the member honestly) and
 * "the provider was asked and could not answer" (an operational failure that
 * must not be presented as a decision). Both are failures; conflating them is
 * how a member ends up told their identity was rejected when in fact nobody
 * looked at it.
 */
final class KycProviderException extends RuntimeException
{
    public const NOT_CONFIGURED = 'kyc_provider_not_configured';

    public const UNKNOWN_PROVIDER = 'kyc_provider_unknown';

    public const REFUSED = 'kyc_provider_refused';

    public const UNAVAILABLE = 'kyc_provider_unavailable';

    public const WEBHOOK_REJECTED = 'kyc_webhook_rejected';

    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        string $message,
        private readonly string $errorCode = self::UNAVAILABLE,
        private readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function unknownProvider(string $provider, array $context = []): self
    {
        return new self(
            sprintf('The KYC provider "%s" is not a recognised driver.', $provider),
            self::UNKNOWN_PROVIDER,
            ['provider' => $provider] + $context,
        );
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function notConfigured(array $context = []): self
    {
        return new self(
            'No KYC provider is configured, so no provider submission can be made.',
            self::NOT_CONFIGURED,
            $context,
        );
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function submissionRefused(string $provider, string $reasonCode, array $context = []): self
    {
        return new self(
            sprintf('The KYC provider "%s" refused the submission (%s).', $provider, $reasonCode),
            self::REFUSED,
            ['provider' => $provider, 'reason_code' => $reasonCode] + $context,
        );
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function unavailable(string $provider, string $reason, array $context = []): self
    {
        return new self(
            sprintf('The KYC provider "%s" could not answer.', $provider),
            self::UNAVAILABLE,
            ['provider' => $provider, 'reason' => $reason] + $context,
        );
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function webhookRejected(string $provider, string $reason, array $context = []): self
    {
        return new self(
            sprintf('A %s KYC webhook was rejected: %s.', $provider, $reason),
            self::WEBHOOK_REJECTED,
            ['provider' => $provider, 'reason' => $reason] + $context,
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function context(): array
    {
        return $this->context;
    }
}
