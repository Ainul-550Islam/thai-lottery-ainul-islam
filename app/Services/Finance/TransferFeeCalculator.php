<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\DTOs\Transfer\TransferFee;
use App\Enums\Currency;
use App\Enums\TransferDirection;
use App\Exceptions\BalanceTransferException;

/**
 * Exact decimal fee calculator for player pocket-to-pocket balance transfers.
 *
 * Reads the canonical fee schedule from config/fees.php so the public Fees page
 * and the transfer engine share a single source of truth.
 */
final class TransferFeeCalculator
{
    private const RATE_PATTERN = '/^\d+(?:\.\d{1,6})?$/';

    private const AMOUNT_PATTERN = '/^\d+(?:\.\d{1,2})?$/';

    /**
     * Calculate the fee and total debit for a transfer direction and amount.
     *
     * @throws BalanceTransferException
     */
    public function calculate(
        TransferDirection $direction,
        string $amount,
        Currency $currency = Currency::THB,
    ): TransferFee {
        $normalisedAmount = $this->normaliseAmount($amount);
        $category = $direction->feeCategory();
        $row = $this->resolveCategoryRow($direction, $category);

        $calculation = strtolower(trim((string) ($row['calculation'] ?? 'percentage')));

        if ($calculation === 'none') {
            $rate = '0.000000';
            $fee = '0.00';
        } elseif ($calculation === 'fixed') {
            $rawAmount = $row['amount'] ?? null;
            if (! is_string($rawAmount) || ! preg_match(self::AMOUNT_PATTERN, trim($rawAmount))) {
                throw BalanceTransferException::feeNotConfigured($direction->value, $category);
            }
            $rate = '0.000000';
            $fee = bcadd(trim($rawAmount), '0', 2);
        } elseif ($calculation === 'percentage') {
            $rate = $this->resolvePercentageRate($direction, $category, $row);
            $rawProduct = bcmul($normalisedAmount, $rate, 6);
            $fee = $this->roundHalfUpScale2($rawProduct);
            $fee = $this->applyBounds($fee, $row);
            if (bccomp($fee, $normalisedAmount, 2) === 1) {
                $fee = $normalisedAmount;
            }
        } else {
            throw BalanceTransferException::feeNotConfigured($direction->value, $category);
        }

        if (bccomp($fee, '0.00', 2) === -1) {
            $fee = '0.00';
        }

        return TransferFee::make(
            direction: $direction,
            amount: $normalisedAmount,
            fee: $fee,
            rate: $rate,
            category: $category,
        );
    }

    /**
     * Resolve the configured fractional rate ('0.010000') for a direction, or null when unconfigured.
     */
    public function configuredRateOrNull(TransferDirection $direction): ?string
    {
        try {
            $category = $direction->feeCategory();
            $row = $this->resolveCategoryRow($direction, $category);
            $calculation = strtolower(trim((string) ($row['calculation'] ?? 'percentage')));

            if ($calculation === 'none') {
                return '0.000000';
            }

            if ($calculation === 'percentage') {
                return $this->resolvePercentageRate($direction, $category, $row);
            }

            return null;
        } catch (BalanceTransferException) {
            return null;
        }
    }

    /**
     * Resolve the human-readable percentage string ('1' or '2.5') for a direction, or null when unconfigured.
     */
    public function configuredRatePercentOrNull(TransferDirection $direction): ?string
    {
        $rate = $this->configuredRateOrNull($direction);
        if ($rate === null) {
            return null;
        }

        $percent = bcmul($rate, '100', 2);

        return rtrim(rtrim($percent, '0'), '.') ?: '0';
    }

    /**
     * @return array<string, mixed>
     *
     * @throws BalanceTransferException
     */
    private function resolveCategoryRow(TransferDirection $direction, string $category): array
    {
        $row = config('fees.categories.'.$category);

        if (! is_array($row) || ! (bool) ($row['enabled'] ?? false)) {
            throw BalanceTransferException::feeNotConfigured($direction->value, $category);
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     *
     * @throws BalanceTransferException
     */
    private function resolvePercentageRate(TransferDirection $direction, string $category, array $row): string
    {
        $rawRate = $row['rate'] ?? null;
        if (! is_string($rawRate)) {
            throw BalanceTransferException::feeNotConfigured($direction->value, $category);
        }

        $trimmed = trim($rawRate);
        if ($trimmed === '' || ! preg_match(self::RATE_PATTERN, $trimmed)) {
            throw BalanceTransferException::feeNotConfigured($direction->value, $category);
        }

        $rate = bcadd($trimmed, '0', 6);

        if (bccomp($rate, '0.000000', 6) === -1 || bccomp($rate, '1.000000', 6) === 1) {
            throw BalanceTransferException::feeNotConfigured($direction->value, $category);
        }

        return $rate;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function applyBounds(string $fee, array $row): string
    {
        $min = $row['min'] ?? null;
        if (is_string($min) && preg_match(self::AMOUNT_PATTERN, trim($min))) {
            $minScaled = bcadd(trim($min), '0', 2);
            if (bccomp($fee, $minScaled, 2) === -1) {
                $fee = $minScaled;
            }
        }

        $max = $row['max'] ?? null;
        if (is_string($max) && preg_match(self::AMOUNT_PATTERN, trim($max))) {
            $maxScaled = bcadd(trim($max), '0', 2);
            if (bccomp($fee, $maxScaled, 2) === 1) {
                $fee = $maxScaled;
            }
        }

        return $fee;
    }

    /**
     * @throws BalanceTransferException
     */
    private function normaliseAmount(string $amount): string
    {
        if ($amount === '' || ! preg_match(self::AMOUNT_PATTERN, $amount)) {
            throw BalanceTransferException::malformedAmount($amount);
        }

        $scaled = bcadd($amount, '0', 2);

        if (bccomp($scaled, '0.00', 2) <= 0) {
            throw BalanceTransferException::malformedAmount($amount);
        }

        return $scaled;
    }

    private function roundHalfUpScale2(string $value): string
    {
        $scaled4 = bcadd($value, '0', 4);
        if (str_starts_with($scaled4, '-')) {
            return bcsub($scaled4, '0.005', 2);
        }

        return bcadd($scaled4, '0.005', 2);
    }
}
