<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Domain refusal in the balance-transfer lane.
 *
 * Carries a stable machine-readable code, HTTP status, diagnostic context,
 * optional Retry-After seconds, and public-safe context filtering so
 * controllers never leak internal IDs or compliance reasons.
 */
final class BalanceTransferException extends RuntimeException
{
    private const PUBLIC_CONTEXT_KEYS = [
        'direction',
        'currency',
        'minimum',
        'maximum',
        'limit',
        'remaining',
        'retry_after',
        'allowed',
        'reference',
    ];

    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        private readonly string $machineCode,
        string $message,
        private readonly int $httpStatus = 422,
        private readonly array $context = [],
        private readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function machineCode(): string
    {
        return $this->machineCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function publicContext(): array
    {
        $filtered = [];

        foreach ($this->context as $key => $value) {
            if (in_array((string) $key, self::PUBLIC_CONTEXT_KEYS, true)) {
                $filtered[(string) $key] = $value;
            }
        }

        if ($this->retryAfter !== null && ! array_key_exists('retry_after', $filtered)) {
            $filtered['retry_after'] = $this->retryAfter;
        }

        return $filtered;
    }

    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }

    public function translatedMessage(): string
    {
        $key = 'transfer.errors.'.$this->machineCode;
        $replacements = [];

        foreach ($this->publicContext() as $k => $v) {
            if ($v !== null) {
                $replacements[$k] = (string) $v;
            }
        }

        $translated = __($key, $replacements);

        if (! is_string($translated) || $translated === $key || trim($translated) === '') {
            return $this->getMessage();
        }

        return $translated;
    }

    public static function disabled(): self
    {
        return new self(
            machineCode: 'transfer_disabled',
            message: 'Balance transfers are currently disabled.',
            httpStatus: 503,
        );
    }

    public static function directionDisabled(string $direction): self
    {
        return new self(
            machineCode: 'transfer_direction_disabled',
            message: 'This transfer direction is currently disabled.',
            httpStatus: 503,
            context: ['direction' => $direction],
        );
    }

    public static function feeNotConfigured(string $direction, string $category = ''): self
    {
        return new self(
            machineCode: 'transfer_fee_not_configured',
            message: 'The fee schedule for this transfer direction is not configured.',
            httpStatus: 503,
            context: array_filter([
                'direction' => $direction,
                'fee_category' => $category !== '' ? $category : null,
            ], static fn ($v): bool => $v !== null),
        );
    }

    public static function invalidLane(string $from, string $to): self
    {
        return new self(
            machineCode: 'transfer_invalid_lane',
            message: 'Transfers are only supported between Cash and Win pockets.',
            httpStatus: 422,
            context: [
                'from_wallet_type' => $from,
                'to_wallet_type' => $to,
            ],
        );
    }

    public static function malformedAmount(string $raw = ''): self
    {
        return new self(
            machineCode: 'transfer_amount_malformed',
            message: 'The transfer amount must be a positive decimal with at most two decimal places.',
            httpStatus: 422,
            context: $raw !== '' ? ['raw_amount' => $raw] : [],
        );
    }

    public static function belowMinimum(string $minimum, string $currency = 'THB'): self
    {
        return new self(
            machineCode: 'transfer_below_minimum',
            message: sprintf('The transfer amount is below the minimum of %s %s.', $minimum, $currency),
            httpStatus: 422,
            context: [
                'minimum' => $minimum,
                'currency' => $currency,
            ],
        );
    }

    public static function aboveMaximum(string $maximum, string $currency = 'THB'): self
    {
        return new self(
            machineCode: 'transfer_above_maximum',
            message: sprintf('The transfer amount exceeds the maximum of %s %s.', $maximum, $currency),
            httpStatus: 422,
            context: [
                'maximum' => $maximum,
                'currency' => $currency,
            ],
        );
    }

    public static function insufficientFunds(string $required, string $available, string $currency = 'THB'): self
    {
        return new self(
            machineCode: 'transfer_insufficient_funds',
            message: 'Insufficient available balance to cover the transfer amount and fee.',
            httpStatus: 422,
            context: [
                'currency' => $currency,
                'required' => $required,
                'available' => $available,
            ],
        );
    }

    public static function walletNotTransactable(int $walletId, string $side): self
    {
        return new self(
            machineCode: 'transfer_wallet_not_transactable',
            message: 'One of the wallets for this transfer cannot currently transact.',
            httpStatus: 409,
            context: [
                'wallet_id' => $walletId,
                'side' => $side,
            ],
        );
    }

    public static function accountNotEligible(int $userId, string $reasonCode = 'account_restricted'): self
    {
        return new self(
            machineCode: 'transfer_account_not_eligible',
            message: 'Your account is not currently eligible to perform balance transfers.',
            httpStatus: 403,
            context: [
                'user_id' => $userId,
                'reason_code' => $reasonCode,
            ],
        );
    }

    public static function idempotencyConflict(string $idempotencyKey): self
    {
        return new self(
            machineCode: 'transfer_idempotency_conflict',
            message: 'This idempotency key was already used with a different transfer request.',
            httpStatus: 409,
            context: ['idempotency_key' => $idempotencyKey],
        );
    }

    public static function coolingDown(int $retryAfterSeconds, string $direction): self
    {
        $retryAfter = max(1, $retryAfterSeconds);

        return new self(
            machineCode: 'transfer_cooling_down',
            message: sprintf('Please wait %d seconds before submitting another transfer in this direction.', $retryAfter),
            httpStatus: 429,
            context: [
                'direction' => $direction,
                'retry_after' => $retryAfter,
            ],
            retryAfter: $retryAfter,
        );
    }

    public static function dailyCountReached(int $limit, string $direction): self
    {
        return new self(
            machineCode: 'transfer_daily_count_reached',
            message: 'You have reached the maximum number of transfers allowed in 24 hours for this direction.',
            httpStatus: 429,
            context: [
                'direction' => $direction,
                'limit' => $limit,
            ],
        );
    }

    public static function dailyAmountReached(string $limit, string $remaining, string $currency, string $direction): self
    {
        return new self(
            machineCode: 'transfer_daily_amount_reached',
            message: 'This transfer would exceed your 24-hour transfer volume limit for this direction.',
            httpStatus: 429,
            context: [
                'direction' => $direction,
                'limit' => $limit,
                'remaining' => $remaining,
                'currency' => $currency,
            ],
        );
    }

    public static function notReversible(string $code, string $message, int $httpStatus = 409): self
    {
        return new self(
            machineCode: $code,
            message: $message,
            httpStatus: $httpStatus,
        );
    }
}
