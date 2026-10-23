<?php

declare(strict_types=1);

namespace App\Services\Wallet\Conversion;

use App\Enums\Currency;
use App\Enums\WalletConversionRejectionReason;
use App\Enums\WalletConversionStatus;
use App\Exceptions\Wallet\ConversionException;
use App\Exceptions\Wallet\ConversionLimitExceededException;
use App\Models\WalletConversion;
use App\Support\Wallet\ConversionCurrency;
use App\ValueObjects\ConversionLimits;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ConversionLimitService
{
    private const BATCH_SIZE = 250;

    private readonly Repository $config;

    public function __construct(?Repository $config = null)
    {
        $this->config = $config ?? app('config');
    }

    public function limits(?Currency $currency = null): ConversionLimits
    {
        $resolvedCurrency = $currency ?? ConversionCurrency::configured($this->config);
        $rawLimits = $this->config->get('conversions.limits', []);

        return ConversionLimits::fromConfig(is_array($rawLimits) ? $rawLimits : [], $resolvedCurrency);
    }

    public function assertAllowed(int $userId, string $amount, Currency $currency): void
    {
        if (DB::transactionLevel() > 0) {
            $this->assertAllowedForExecution($userId, $amount, $currency);

            return;
        }

        $this->check($userId, $amount, $currency, false);
    }

    public function assertAllowedForExecution(int $userId, string $amount, Currency $currency): void
    {
        if (DB::transactionLevel() < 1 || ! DB::connection()->getPdo()->inTransaction()) {
            throw ConversionException::failure(
                'transaction_required',
                'Conversion limit admission requires an active SQL transaction.',
            );
        }

        $this->check($userId, $amount, $currency, true);
    }

    public function dailyTotal(
        int $userId,
        DateTimeImmutable|Currency|string|null $now = null,
        ?Currency $currency = null,
    ): string {
        if ($now instanceof Currency) {
            $currency = $now;
            $now = null;
        } elseif (is_string($now)) {
            $currency = Currency::tryFrom($now) ?? $currency;
            $now = null;
        }
        $resolvedCurrency = $currency ?? ConversionCurrency::configured($this->config);
        $cutoff = $now !== null ? Carbon::instance($now) : null;

        return $this->totals($userId, $resolvedCurrency, false, $cutoff)['daily'];
    }

    public function monthlyTotal(
        int $userId,
        DateTimeImmutable|Currency|string|null $now = null,
        ?Currency $currency = null,
    ): string {
        if ($now instanceof Currency) {
            $currency = $now;
            $now = null;
        } elseif (is_string($now)) {
            $currency = Currency::tryFrom($now) ?? $currency;
            $now = null;
        }
        $resolvedCurrency = $currency ?? ConversionCurrency::configured($this->config);
        $cutoff = $now !== null ? Carbon::instance($now) : null;

        return $this->totals($userId, $resolvedCurrency, false, $cutoff)['monthly'];
    }

    public function remainingDaily(int $userId, ?Currency $currency = null): ?string
    {
        $limits = $this->limits($currency);
        $ceiling = $limits->dailyLimit();
        if ($ceiling === null) {
            return null;
        }

        $used = $this->dailyTotal($userId, currency: $currency);
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

        $used = $this->monthlyTotal($userId, currency: $currency);
        $diff = bcsub($ceiling, $used, 2);

        return bccomp($diff, '0.00', 2) >= 0 ? $diff : '0.00';
    }

    private function check(int $userId, string $amount, Currency $currency, bool $locking): void
    {
        $normalisedAmount = bcadd($amount, '0', 2);
        $limits = $this->limits($currency);

        $txViolations = $limits->transactionViolations($normalisedAmount);
        if (in_array(WalletConversionRejectionReason::BelowMinimum, $txViolations, true)) {
            throw ConversionLimitExceededException::forMinimum(
                $limits->minimum(),
                $normalisedAmount,
                $currency->value,
            );
        }
        if (in_array(WalletConversionRejectionReason::AboveMaximum, $txViolations, true) && $limits->maximum() !== null) {
            throw ConversionLimitExceededException::forMaximum(
                $limits->maximum(),
                $normalisedAmount,
                $currency->value,
            );
        }

        $totals = $this->totals($userId, $currency, $locking);

        if ($limits->enforcesDailyLimit() && $limits->dailyLimit() !== null) {
            $attemptedDaily = bcadd($totals['daily'], $normalisedAmount, 2);
            if (bccomp($attemptedDaily, $limits->dailyLimit(), 2) === 1) {
                throw ConversionLimitExceededException::forDailyWindow(
                    $limits->dailyLimit(),
                    $attemptedDaily,
                    $currency->value,
                );
            }
        }

        if ($limits->enforcesMonthlyLimit() && $limits->monthlyLimit() !== null) {
            $attemptedMonthly = bcadd($totals['monthly'], $normalisedAmount, 2);
            if (bccomp($attemptedMonthly, $limits->monthlyLimit(), 2) === 1) {
                throw ConversionLimitExceededException::forMonthlyWindow(
                    $limits->monthlyLimit(),
                    $attemptedMonthly,
                    $currency->value,
                );
            }
        }
    }

    /**
     * @return array{daily: string, monthly: string}
     */
    private function totals(int $userId, Currency $currency, bool $locking, ?Carbon $at = null): array
    {
        $cutoff = ($at ?? Carbon::now())->copy()->startOfSecond();
        $dailyFloor = $cutoff->copy()->subHours(24);
        $monthlyFloor = $cutoff->copy()->subDays(30);

        $settled = array_map(
            static fn (WalletConversionStatus $status): string => $status->value,
            WalletConversionStatus::settledStates(),
        );

        $daily = '0.00';
        $monthly = '0.00';
        $afterId = 0;

        do {
            $query = WalletConversion::query()
                ->where('user_id', $userId)
                ->where('currency', $currency->value)
                ->whereNull('reversal_of_id')
                ->whereIn('status', $settled)
                ->whereNotNull('completed_at')
                ->where('completed_at', '>=', $monthlyFloor)
                ->where('completed_at', '<=', $cutoff)
                ->where('id', '>', $afterId)
                ->orderBy('id')
                ->limit(self::BATCH_SIZE);

            if ($locking) {
                $query->lockForUpdate();
            } else {
                $query->useWritePdo();
            }

            $rows = $query->get(['id', 'amount_in', 'completed_at']);

            foreach ($rows as $row) {
                $afterId = (int) $row->id;
                $rawAmount = $row->getRawOriginal('amount_in');
                $amountStr = is_string($rawAmount) || is_int($rawAmount)
                    ? (string) $rawAmount
                    : (is_float($rawAmount) ? number_format($rawAmount, 2, '.', '') : '');

                if (preg_match('/^\d+(\.\d{1,2})?$/D', $amountStr) !== 1 || bccomp($amountStr, '0', 2) <= 0) {
                    throw ConversionException::failure(
                        'conversion_limits_unproven',
                        'Historical conversion allowance contains an invalid amount and requires review.',
                    );
                }

                $exactAmount = bcadd($amountStr, '0', 2);
                $monthly = bcadd($monthly, $exactAmount, 2);

                $completedAt = $row->completed_at instanceof Carbon
                    ? $row->completed_at
                    : Carbon::parse((string) $row->getRawOriginal('completed_at'));

                if ($completedAt->gte($dailyFloor)) {
                    $daily = bcadd($daily, $exactAmount, 2);
                }
            }
        } while ($rows->count() === self::BATCH_SIZE);

        return [
            'daily' => $daily,
            'monthly' => $monthly,
        ];
    }
}
