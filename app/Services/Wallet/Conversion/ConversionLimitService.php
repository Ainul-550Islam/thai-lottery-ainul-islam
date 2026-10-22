<?php

declare(strict_types=1);

namespace App\Services\Wallet\Conversion;

use App\Contracts\Wallet\ConversionRepositoryContract;
use App\Enums\Currency;
use App\ValueObjects\ConversionLimits;
use DateTimeImmutable;

final class ConversionLimitService
{
    public function __construct(
        private readonly ConversionRepositoryContract $conversions,
    ) {}

    public function limits(?Currency $currency = null): ConversionLimits
    {
        $resolvedCurrency = $currency
            ?? Currency::tryFrom((string) config('conversions.currency', ''))
            ?? Currency::primary();

        $rawLimits = config('conversions.limits', []);

        return ConversionLimits::fromConfig(is_array($rawLimits) ? $rawLimits : [], $resolvedCurrency);
    }

    public function dailyTotal(int $userId, ?DateTimeImmutable $now = null): string
    {
        $at = $now ?? new DateTimeImmutable;
        $from = $at->modify('-24 hours');

        return $this->conversions->completedTotalForUser($userId, $from, $at);
    }

    public function monthlyTotal(int $userId, ?DateTimeImmutable $now = null): string
    {
        $at = $now ?? new DateTimeImmutable;
        $from = $at->modify('-30 days');

        return $this->conversions->completedTotalForUser($userId, $from, $at);
    }

    public function remainingDaily(int $userId, ?Currency $currency = null): ?string
    {
        $limits = $this->limits($currency);
        $ceiling = $limits->dailyLimit();
        if ($ceiling === null) {
            return null;
        }

        $used = $this->dailyTotal($userId);
        $diff = bcsub($ceiling, $used, 2);

        return bccomp($diff, '0.00', 2) >= 0 ? $diff : '0.00';
    }

    public function remainingMonthly(int $userId, ?Currency $currency = null): ?string
    {
        $limits = $this->limits($currency);
        $ceiling = $limits->monthlyLimit();
        if ($ceiling === null) {
            return null;
        }

        $used = $this->monthlyTotal($userId);
        $diff = bcsub($ceiling, $used, 2);

        return bccomp($diff, '0.00', 2) >= 0 ? $diff : '0.00';
    }
}
