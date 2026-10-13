<?php

declare(strict_types=1);

namespace App\Exceptions\Wallet;

use App\Enums\WalletConversionRejectionReason;
use InvalidArgumentException;
use Throwable;

/**
 * A configured conversion envelope was breached.
 *
 * WHY ONE EXCEPTION CARRIES FOUR DIFFERENT REFUSALS
 * The minimum, the maximum, the daily window and the monthly window are the
 * same kind of thing: a number in config/conversions.php that the request did
 * not satisfy. Splitting them into four exception classes would produce four
 * near-identical files and, worse, four chances to map one of them to the wrong
 * reason code. One class with a validated reason, plus a `limitKey()` that
 * names the exact configuration path, gives support a single thing to read and
 * gives alerting a single thing to group — while `rejectionReason()` still
 * distinguishes "below minimum" from "daily window exhausted", which is the
 * distinction that decides whether the member should top up or come back
 * tomorrow.
 *
 * The constructor refuses a reason that is not a limit reason, so a caller
 * cannot file an unrelated refusal under this type.
 */
final class ConversionLimitExceededException extends ConversionException
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        private readonly WalletConversionRejectionReason $reason,
        private readonly string $limit,
        private readonly string $attempted,
        private readonly string $currency,
        private readonly string $limitKey,
        array $context = [],
        ?Throwable $previous = null,
    ) {
        if (! self::isLimitReason($reason)) {
            throw new InvalidArgumentException(
                sprintf('[%s] is not a conversion limit reason.', $reason->value),
            );
        }

        parent::__construct(
            self::describe($reason, $limit, $attempted, $currency),
            $reason->value,
            array_merge([
                'reason' => $reason->value,
                'limit' => $limit,
                'attempted' => $attempted,
                'currency' => $currency,
                'limit_key' => $limitKey,
            ], $context),
            $previous,
        );
    }

    public static function forMinimum(
        string $minimum,
        string $attempted,
        string $currency,
        ?Throwable $previous = null,
    ): self {
        return new self(
            WalletConversionRejectionReason::BelowMinimum,
            $minimum,
            $attempted,
            $currency,
            'conversions.limits.min_amount',
            previous: $previous,
        );
    }

    public static function forMaximum(
        string $maximum,
        string $attempted,
        string $currency,
        ?Throwable $previous = null,
    ): self {
        return new self(
            WalletConversionRejectionReason::AboveMaximum,
            $maximum,
            $attempted,
            $currency,
            'conversions.limits.max_amount',
            previous: $previous,
        );
    }

    public static function forDailyWindow(
        string $dailyLimit,
        string $attemptedTotal,
        string $currency,
        ?Throwable $previous = null,
    ): self {
        return new self(
            WalletConversionRejectionReason::DailyLimitExceeded,
            $dailyLimit,
            $attemptedTotal,
            $currency,
            'conversions.limits.daily_limit',
            previous: $previous,
        );
    }

    public static function forMonthlyWindow(
        string $monthlyLimit,
        string $attemptedTotal,
        string $currency,
        ?Throwable $previous = null,
    ): self {
        return new self(
            WalletConversionRejectionReason::MonthlyLimitExceeded,
            $monthlyLimit,
            $attemptedTotal,
            $currency,
            'conversions.limits.monthly_limit',
            previous: $previous,
        );
    }

    /**
     * Build from a reason plus its numbers, for callers that resolved the
     * reason themselves before knowing the values.
     */
    public static function forReason(
        WalletConversionRejectionReason $reason,
        string $limit,
        string $attempted,
        string $currency,
        ?Throwable $previous = null,
    ): self {
        return new self(
            $reason,
            $limit,
            $attempted,
            $currency,
            match ($reason) {
                WalletConversionRejectionReason::BelowMinimum => 'conversions.limits.min_amount',
                WalletConversionRejectionReason::AboveMaximum => 'conversions.limits.max_amount',
                WalletConversionRejectionReason::DailyLimitExceeded => 'conversions.limits.daily_limit',
                WalletConversionRejectionReason::MonthlyLimitExceeded => 'conversions.limits.monthly_limit',
                default => 'conversions.limits',
            },
            previous: $previous,
        );
    }

    public function limit(): string
    {
        return $this->limit;
    }

    public function attempted(): string
    {
        return $this->attempted;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    /**
     * The exact configuration path an operator edits to change this envelope.
     */
    public function limitKey(): string
    {
        return $this->limitKey;
    }

    /**
     * True for the rolling windows, where the remedy is to wait or to split the
     * request, rather than to re-enter a different amount.
     */
    public function isRollingWindow(): bool
    {
        return in_array($this->reason, [
            WalletConversionRejectionReason::DailyLimitExceeded,
            WalletConversionRejectionReason::MonthlyLimitExceeded,
        ], true);
    }

    public function rejectionReason(): ?WalletConversionRejectionReason
    {
        return $this->reason;
    }

    private static function isLimitReason(WalletConversionRejectionReason $reason): bool
    {
        return in_array($reason, [
            WalletConversionRejectionReason::BelowMinimum,
            WalletConversionRejectionReason::AboveMaximum,
            WalletConversionRejectionReason::DailyLimitExceeded,
            WalletConversionRejectionReason::MonthlyLimitExceeded,
        ], true);
    }

    private static function describe(
        WalletConversionRejectionReason $reason,
        string $limit,
        string $attempted,
        string $currency,
    ): string {
        return match ($reason) {
            WalletConversionRejectionReason::BelowMinimum => sprintf(
                'The conversion amount %s %s is below the configured minimum of %s %s.',
                $attempted,
                $currency,
                $limit,
                $currency,
            ),
            WalletConversionRejectionReason::AboveMaximum => sprintf(
                'The conversion amount %s %s exceeds the configured maximum of %s %s.',
                $attempted,
                $currency,
                $limit,
                $currency,
            ),
            WalletConversionRejectionReason::DailyLimitExceeded => sprintf(
                'This conversion would bring the day total to %s %s, above the daily conversion limit of %s %s.',
                $attempted,
                $currency,
                $limit,
                $currency,
            ),
            WalletConversionRejectionReason::MonthlyLimitExceeded => sprintf(
                'This conversion would bring the month total to %s %s, above the monthly conversion limit of %s %s.',
                $attempted,
                $currency,
                $limit,
                $currency,
            ),
            default => 'A configured conversion limit was exceeded.',
        };
    }
}
