<?php

declare(strict_types=1);

namespace App\Services\Wallet\Conversion;

use App\Contracts\Wallet\ConversionRepositoryContract;
use App\Enums\Currency;
use App\ValueObjects\ConversionLimits;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\DB;

final class ConversionLimitService
{
    public function __construct(private readonly Config $config) {}

    public function limits(Currency $currency): ConversionLimits
    {
        return ConversionLimits::fromConfig((array) $this->config->get('conversions.limits', []), $currency);
    }

    public function dailyTotal(int $userId, ?Currency $currency = null): string
    {
        return $this->total($userId, CarbonImmutable::now()->startOfDay(), CarbonImmutable::now());
    }

    public function monthlyTotal(int $userId, ?Currency $currency = null): string
    {
        return $this->total($userId, CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now());
    }

    public function remainingDaily(int $userId, ?Currency $currency = null): ?string
    {
        $limits = $this->limits($currency ?? Currency::primary());
        return $limits->remainingDaily($this->dailyTotal($userId, $currency));
    }

    private function total(int $userId, \DateTimeInterface $from, \DateTimeInterface $to): string
    {
        $sum = DB::table('wallet_conversions')->where('user_id', $userId)
            ->where('status', 'completed')->whereBetween('completed_at', [$from, $to])
            ->sum('amount_in');
        return bcadd((string) $sum, '0', 2);
    }
}
