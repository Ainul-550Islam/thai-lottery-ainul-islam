<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Enums\Currency;
use App\Enums\WalletConversionRejectionReason;
use InvalidArgumentException;
use JsonSerializable;

/**
 * The immutable limit envelope a single conversion amount is judged against.
 *
 * WHY THIS IS A VALUE OBJECT AND NOT FOUR SERVICE ARGUMENTS
 * Limits are read once, from configuration, and then asked the same three
 * questions at three different moments: the quote endpoint previews them, the
 * policy guard enforces them, and the UI renders them as helper text under the
 * amount field. Passing four loose strings through those three call sites is
 * how a UI ends up showing "minimum 10" while the engine enforces 100. This
 * object is constructed once per request from config and every consumer asks
 * the same instance.
 *
 * The per-transaction bounds (min/max) are evaluated here with exact decimal
 * comparison. The rolling windows (daily/monthly) are NOT evaluated here: they
 * require a database aggregate over the user's completed conversions, so this
 * object only carries the ceilings and the comparison helpers the service uses
 * once it has the totals.
 *
 * Every comparison uses bccomp on decimal strings — never float casting.
 */
final class ConversionLimits implements JsonSerializable
{
    private function __construct(
        private readonly string $minimum,
        private readonly ?string $maximum,
        private readonly ?string $dailyLimit,
        private readonly ?string $monthlyLimit,
        private readonly Currency $currency,
        private readonly bool $enforceDaily = true,
        private readonly bool $enforceMonthly = true,
    ) {
    }

    /**
     * @param  array<string, mixed>  $limits  the `limits` block of config/conversions.php
     */
    public static function fromConfig(array $limits, Currency $currency): self
    {
        $minimum = self::normaliseAmount($limits['min_amount'] ?? null) ?? '0.00';

        return new self(
            minimum: $minimum,
            maximum: self::normaliseAmount($limits['max_amount'] ?? null),
            dailyLimit: self::normaliseAmount($limits['daily_limit'] ?? null),
            monthlyLimit: self::normaliseAmount($limits['monthly_limit'] ?? null),
            currency: $currency,
            // The enforcement switches are read HERE, not left for a caller to
            // remember. They were previously documented in config and ignored by
            // every consumer, which made an operator's `enforce_daily = false`
            // a switch that changed nothing while members kept being refused.
            enforceDaily: (bool) ($limits['enforce_daily'] ?? true),
            enforceMonthly: (bool) ($limits['enforce_monthly'] ?? true),
        );
    }

    public function minimum(): string
    {
        return $this->minimum;
    }

    public function maximum(): ?string
    {
        return $this->maximum;
    }

    public function dailyLimit(): ?string
    {
        return $this->dailyLimit;
    }

    public function monthlyLimit(): ?string
    {
        return $this->monthlyLimit;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    public function hasDailyLimit(): bool
    {
        return $this->dailyLimit !== null && bccomp($this->dailyLimit, '0', 2) === 1;
    }

    public function hasMonthlyLimit(): bool
    {
        return $this->monthlyLimit !== null && bccomp($this->monthlyLimit, '0', 2) === 1;
    }

    /**
     * Published AND switched on. A ceiling can be shown to members while not
     * being enforced — an operator raising a limit during a promotion, or
     * investigating a false positive — and the two questions are therefore
     * separate: `hasDailyLimit()` is what the page prints, this is what the
     * engine obeys.
     */
    public function enforcesDailyLimit(): bool
    {
        return $this->enforceDaily && $this->hasDailyLimit();
    }

    public function enforcesMonthlyLimit(): bool
    {
        return $this->enforceMonthly && $this->hasMonthlyLimit();
    }

    /**
     * True when the amount satisfies every bound this object is responsible for.
     * Rolling-window totals are checked by the limit service, not here.
     */
    public function allows(string $amount): bool
    {
        return $this->transactionViolations($amount) === [];
    }

    /**
     * Every per-transaction bound the amount breaks, in a stable order.
     *
     * @return array<int, WalletConversionRejectionReason>
     */
    public function transactionViolations(string $amount): array
    {
        $violations = [];

        if (bccomp($amount, $this->minimum, 2) === -1) {
            $violations[] = WalletConversionRejectionReason::BelowMinimum;
        }

        if ($this->maximum !== null && bccomp($amount, $this->maximum, 2) === 1) {
            $violations[] = WalletConversionRejectionReason::AboveMaximum;
        }

        return $violations;
    }

    /**
     * Would adding $amount to an already-recorded $total breach a rolling
     * window? The service passes the aggregate it read under its own lock.
     */
    public function rollingViolations(string $amount, string $dailyTotal, ?string $monthlyTotal = null): array
    {
        $violations = [];

        if (
            $this->enforcesDailyLimit()
            && bccomp(bcadd($dailyTotal, $amount, 2), (string) $this->dailyLimit, 2) === 1
        ) {
            $violations[] = WalletConversionRejectionReason::DailyLimitExceeded;
        }

        if (
            $this->enforcesMonthlyLimit()
            && $monthlyTotal !== null
            && bccomp(bcadd($monthlyTotal, $amount, 2), (string) $this->monthlyLimit, 2) === 1
        ) {
            $violations[] = WalletConversionRejectionReason::MonthlyLimitExceeded;
        }

        return $violations;
    }

    /**
     * The remaining head-room inside the daily window for a given total.
     * Returns null when no daily limit is configured.
     */
    public function remainingDaily(string $dailyTotal): ?string
    {
        if (! $this->enforcesDailyLimit()) {
            return null;
        }

        $remaining = bcsub((string) $this->dailyLimit, $dailyTotal, 2);

        return bccomp($remaining, '0', 2) === -1 ? '0.00' : $remaining;
    }

    /**
     * A serialisable description for the quote response and the UI helper text.
     *
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency->value,
            'min_amount' => $this->minimum,
            'max_amount' => $this->maximum,
            'daily_limit' => $this->dailyLimit,
            'monthly_limit' => $this->monthlyLimit,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Accept a decimal string, int or float and normalise it to a 2dp decimal
     * string. Blank values normalise to null so "not configured" stays
     * distinguishable from a real zero ceiling.
     */
    private static function normaliseAmount(string|int|float|null $amount): ?string
    {
        if ($amount === null) {
            return null;
        }

        if (is_float($amount)) {
            $amount = number_format($amount, 2, '.', '');
        }

        $normalised = trim((string) $amount);

        if ($normalised === '') {
            return null;
        }

        if (preg_match('/^\d+(\.\d+)?$/', $normalised) !== 1) {
            throw new InvalidArgumentException(
                sprintf('A configured conversion limit must be a non-negative decimal value; [%s] was given.', $normalised),
            );
        }

        return bcadd($normalised, '0', 2);
    }
}
