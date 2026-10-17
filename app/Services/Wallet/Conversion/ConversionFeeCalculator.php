<?php

declare(strict_types=1);

namespace App\Services\Wallet\Conversion;

use App\Enums\ConversionFeeBearer;
use App\Enums\WalletConversionDirection;
use App\DTOs\Wallet\ConversionFeeBreakdownData;
use Illuminate\Config\Repository;

/**
 * Turns one published fee row into the exact numbers a conversion charges.
 *
 * WHY THE CALCULATOR READS CONFIGURATION AND NOTHING ELSE
 * The fee row is the tariff: the same row the public fees page renders. This
 * class resolves it the same way FeesPageService::resolveRate does — the row's
 * `execution` path is read as a whole percent and divided by 100 with bcdiv —
 * so what the page shows and what the engine charges cannot drift. The unit
 * suite hands it a plain config Repository to prove it has no other dependency.
 *
 * FALLBACK ORDER, AND WHY IT IS NOT "ZERO"
 * 1. The live value at the row's `execution` path, when readable and
 *    non-negative, is what is charged.
 * 2. An unreadable or negative live value falls back to the row's published
 *    `rate` — the documented number stands rather than the fee vanishing.
 * 3. A row with neither is UNCONFIGURED: the engine refuses the conversion.
 *    Silently charging zero would move money at a price nobody published.
 */
final class ConversionFeeCalculator
{
    public function __construct(private readonly Repository $config)
    {
    }

    /**
     * Price one amount under the direction's published row.
     */
    public function breakdownFor(WalletConversionDirection $direction, string $amount, string $currency): ConversionFeeBreakdownData
    {
        $row = $this->rowFor($direction);
        $scale = $this->scale();
        $rateScale = $this->rateScale();

        $enabled = (bool) ($row['enabled'] ?? false);
        $publicVisible = (bool) ($row['public_visible'] ?? true);
        $calculation = is_string($row['calculation'] ?? null) ? $row['calculation'] : 'percentage';

        $rate = $this->effectiveRate($row);
        $configured = $enabled && $publicVisible && $rate !== null;

        $normalisedAmount = $this->money($amount, $scale);

        if (! $configured) {
            // No price means no fee — but the CALLER must refuse. The net
            // figure is echoed only so a preview at least shows the amount
            // untouched rather than a fabricated zero fee.
            return new ConversionFeeBreakdownData(
                ruleKey: $direction->value,
                ruleVersion: $this->ruleVersion($row),
                direction: $direction,
                rate: $this->rateString($rate ?? '0', $rateScale),
                percentage: $this->percentageString($rate ?? '0', $scale),
                basisPoints: $this->basisPoints($rate ?? '0'),
                feeAmount: $this->money('0', $scale),
                netAmount: $normalisedAmount,
                currency: $currency,
                calculation: $calculation,
                bearer: $this->bearer(),
                isConfigured: false,
                effectiveFrom: isset($row['effective_from']) && is_string($row['effective_from']) ? $row['effective_from'] : null,
                effectiveTo: isset($row['effective_to']) && is_string($row['effective_to']) ? $row['effective_to'] : null,
                labelKey: isset($row['label_key']) && is_string($row['label_key']) ? $row['label_key'] : null,
                descriptionKey: isset($row['description_key']) && is_string($row['description_key']) ? $row['description_key'] : null,
            );
        }

        $feeAmount = $this->applyRate($normalisedAmount, $rate, $scale, $calculation, $row);
        $netAmount = $this->money(bcsub($normalisedAmount, $feeAmount, $scale), $scale);

        return new ConversionFeeBreakdownData(
            ruleKey: $direction->value,
            ruleVersion: $this->ruleVersion($row),
            direction: $direction,
            rate: $this->rateString($rate, $rateScale),
            percentage: $this->percentageString($rate, $scale),
            basisPoints: $this->basisPoints($rate),
            feeAmount: $feeAmount,
            netAmount: $netAmount,
            currency: $currency,
            calculation: $calculation,
            bearer: $this->bearer(),
            isConfigured: true,
            effectiveFrom: isset($row['effective_from']) && is_string($row['effective_from']) ? $row['effective_from'] : null,
            effectiveTo: isset($row['effective_to']) && is_string($row['effective_to']) ? $row['effective_to'] : null,
            labelKey: isset($row['label_key']) && is_string($row['label_key']) ? $row['label_key'] : null,
            descriptionKey: isset($row['description_key']) && is_string($row['description_key']) ? $row['description_key'] : null,
        );
    }

    /**
     * The rate this row currently prices with, or null when no rate exists at
     * all. Public so the parity tests and support tooling can ask the engine
     * what it would use without pricing an amount.
     *
     * @param  array<string, mixed>  $row
     */
    public function effectiveRate(array $row): ?string
    {
        $rateScale = $this->rateScale();

        $execution = $row['execution'] ?? null;

        if (is_string($execution) && $execution !== '') {
            $live = $this->config->get($execution);

            // A readable, non-negative live value wins; anything else falls
            // through to the published rate rather than becoming zero.
            if (is_string($live) && $live !== '' && is_numeric($live) && bccomp($live, '0', $rateScale) >= 0) {
                return bcdiv($live, '100', $rateScale);
            }
        }

        $rate = $row['rate'] ?? null;

        if (! is_string($rate) || $rate === '' || ! is_numeric($rate)) {
            return null;
        }

        if (bccomp($rate, '0', $rateScale) < 0) {
            return null;
        }

        return $rate;
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFor(WalletConversionDirection $direction): array
    {
        $row = $this->config->get($direction->feeConfigPath());

        return is_array($row) ? $row : [];
    }

    private function bearer(): ConversionFeeBearer
    {
        return ConversionFeeBearer::fromConfig($this->config->get('conversions.fee.bearer'));
    }

    private function scale(): int
    {
        $scale = (int) $this->config->get('conversions.fee.scale', 2);

        return max(0, min(8, $scale));
    }

    private function rateScale(): int
    {
        $scale = (int) $this->config->get('conversions.fee.rate_scale', 4);

        return max(0, min(8, $scale));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function ruleVersion(array $row): string
    {
        $version = $row['rule_version'] ?? null;

        if (is_string($version) && $version !== '') {
            return $version;
        }

        $fallback = $this->config->get('conversions.fee.rule_version_fallback');

        return is_string($fallback) && $fallback !== '' ? $fallback : '0';
    }

    /**
     * fee = amount × rate, rounded ONCE to the fee scale (half up). bcmul
     * truncates, so the half is added explicitly before the final truncation:
     * a fee rounds the way a member expects a price to round, not toward zero.
     */
    private function applyRate(string $amount, string $rate, int $scale, string $calculation, array $row): string
    {
        if ($calculation === 'fixed') {
            $fixed = $row['amount'] ?? null;

            return is_string($fixed) && is_numeric($fixed) && bccomp($fixed, '0', $scale) >= 0
                ? $this->money($fixed, $scale)
                : $this->money('0', $scale);
        }

        if (bccomp($amount, '0', $scale) === 0) {
            return $this->money('0', $scale);
        }

        $raw = bcmul($amount, $rate, $scale + 4);
        $half = '0.'.str_repeat('0', $scale).'5';

        return $this->money(bcadd($raw, $half, $scale + 4), $scale);
    }

    private function money(string $value, int $scale): string
    {
        return bcadd($value, '0', $scale);
    }

    private function rateString(string $rate, int $rateScale): string
    {
        return bcadd($rate, '0', $rateScale);
    }

    /**
     * Whole percent for display: '0.0100' is shown as '1.00'.
     */
    private function percentageString(string $rate, int $scale): string
    {
        return bcmul($rate, '100', $scale);
    }

    private function basisPoints(string $rate): int
    {
        return (int) bcmul($rate, '10000', 0);
    }
}
