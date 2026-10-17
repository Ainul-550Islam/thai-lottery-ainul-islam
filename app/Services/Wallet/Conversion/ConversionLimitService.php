<?php

declare(strict_types=1);

namespace App\Services\Wallet\Conversion;

use App\Enums\Currency;
use App\Repositories\Wallet\WalletConversionRepository;
use App\ValueObjects\ConversionLimits;
use DateTimeImmutable;

/**
 * The amount envelope for one member: per-transaction bounds and the rolling
 * daily/monthly ceilings, read at the moment of judgment.
 *
 * The bounds themselves live in the ConversionLimits value object, which owns
 * the boundary arithmetic (a minimum of 10.00 permits exactly 10.00 and
 * refuses 9.99). This service answers the two questions the value object
 * cannot: what THIS member has already moved inside each window. Windows are
 * calendar windows in UTC: the day starts at midnight, the month at the first.
 */
final class ConversionLimitService
{
    public function __construct(private readonly WalletConversionRepository $conversions)
    {
    }

    /**
     * The configured envelope for a currency.
     */
    public function limits(Currency $currency): ConversionLimits
    {
        $config = config('conversions.limits', []);

        return ConversionLimits::fromConfig(is_array($config) ? $config : [], $currency);
    }

    /**
     * What the member has moved (amount in, completed) since the start of
     * today, as an exact decimal string.
     */
    public function dailyTotal(int $userId): string
    {
        return $this->conversions->completedTotalForUser($userId, $this->startOfDay());
    }

    /**
     * What the member has moved since the start of the month.
     */
    public function monthlyTotal(int $userId): string
    {
        return $this->conversions->completedTotalForUser($userId, $this->startOfMonth());
    }

    /**
     * The head-room left in the daily ceiling, or null when the ceiling is not
     * enforced. Clamped at zero: a member who already passed the ceiling has
     * no remaining room, not negative room.
     */
    public function remainingDaily(int $userId, Currency $currency): ?string
    {
        return $this->limits($currency)->remainingDaily($this->dailyTotal($userId));
    }

    private function startOfDay(): DateTimeImmutable
    {
        return new DateTimeImmutable('today');
    }

    private function startOfMonth(): DateTimeImmutable
    {
        return new DateTimeImmutable('first day of this month');
    }
}
