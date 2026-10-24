<?php

declare(strict_types=1);

namespace App\Services\Wallet\Conversion;

use App\Contracts\Wallet\ConversionFeeProviderContract;
use App\DTOs\Wallet\ConversionFeeBreakdownData;
use App\Enums\ConversionFeeBearer;
use App\Enums\WalletConversionDirection;
use App\Support\Wallet\ConversionCurrency;
use App\ValueObjects\ConversionRate;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

final class ConversionFeeCalculator implements ConversionFeeProviderContract
{
    private readonly Repository $config;

    public function __construct(?Repository $config = null)
    {
        $this->config = $config ?? app('config');
    }

    public function breakdownFor(
        WalletConversionDirection $direction,
        string $amount,
        string $currency = 'THB',
    ): ConversionFeeBreakdownData {
        $currency = ConversionCurrency::parse($currency)->value;
        $ruleKey = $direction->value;
        $row = $this->rowFor($direction);
        $fallbackVersion = (string) $this->config->get('conversions.fee.rule_version_fallback', '2');
        $ruleVersion = is_scalar($row['rule_version'] ?? null) && trim((string) $row['rule_version']) !== ''
            ? trim((string) $row['rule_version'])
            : $fallbackVersion;

        $bearerRaw = strtolower(trim((string) $this->config->get('conversions.fee.bearer', 'source')));
        $bearer = ConversionFeeBearer::tryFrom($bearerRaw) ?? ConversionFeeBearer::Source;
        $calculation = is_string($row['calculation'] ?? null) && $row['calculation'] !== ''
            ? $row['calculation']
            : 'percentage';

        $normalisedAmount = is_numeric($amount) && bccomp($amount, '0', 2) >= 0
            ? bcadd($amount, '0', 2)
            : '0.00';

        $rate = $this->effectiveRate($direction);
        $currencyMatches = (string) ($row['currency'] ?? $currency) === $currency;

        if ($rate === null || ! $currencyMatches) {
            return new ConversionFeeBreakdownData(
                ruleKey: $ruleKey,
                ruleVersion: $ruleVersion,
                direction: $direction,
                rate: '0.0000',
                percentage: '0.00',
                basisPoints: 0,
                feeAmount: '0.00',
                netAmount: $normalisedAmount,
                currency: $currency,
                calculation: $calculation,
                bearer: $bearer,
                isConfigured: false,
                effectiveFrom: isset($row['effective_from']) && is_string($row['effective_from']) ? $row['effective_from'] : null,
                effectiveTo: isset($row['effective_to']) && is_string($row['effective_to']) ? $row['effective_to'] : null,
                labelKey: isset($row['label_key']) && is_string($row['label_key']) ? $row['label_key'] : null,
                descriptionKey: isset($row['description_key']) && is_string($row['description_key']) ? $row['description_key'] : null,
            );
        }

        if (bccomp($normalisedAmount, '0.00', 2) === 0) {
            $feeAmount = '0.00';
            $netAmount = '0.00';
        } else {
            $feeAmount = $rate->feeOn($normalisedAmount);
            $netAmount = $rate->netOf($normalisedAmount);
        }

        return new ConversionFeeBreakdownData(
            ruleKey: $ruleKey,
            ruleVersion: $ruleVersion,
            direction: $direction,
            rate: $rate->toString(),
            percentage: $rate->percentage(),
            basisPoints: $rate->basisPoints(),
            feeAmount: $feeAmount,
            netAmount: $netAmount,
            currency: $currency,
            calculation: $calculation,
            bearer: $bearer,
            isConfigured: true,
            effectiveFrom: isset($row['effective_from']) && is_string($row['effective_from']) ? $row['effective_from'] : null,
            effectiveTo: isset($row['effective_to']) && is_string($row['effective_to']) ? $row['effective_to'] : null,
            labelKey: isset($row['label_key']) && is_string($row['label_key']) ? $row['label_key'] : null,
            descriptionKey: isset($row['description_key']) && is_string($row['description_key']) ? $row['description_key'] : null,
        );
    }

    public function isConfigured(WalletConversionDirection $direction): bool
    {
        return $this->effectiveRate($direction) !== null;
    }

    public function publishedPercentage(WalletConversionDirection $direction): ?string
    {
        $rate = $this->effectiveRate($direction);

        return $rate?->percentage();
    }

    public function effectiveRate(WalletConversionDirection $direction): ?ConversionRate
    {
        $row = $this->rowFor($direction);
        if ($row === []) {
            return null;
        }

        $enabled = (bool) ($row['enabled'] ?? false);
        $publicVisible = (bool) ($row['public_visible'] ?? false);
        if (! $enabled || ! $publicVisible) {
            return null;
        }

        $rateScale = max(2, min(8, (int) $this->config->get('conversions.fee.rate_scale', 4)));

        $executionPath = $row['execution'] ?? null;
        if (is_string($executionPath) && trim($executionPath) !== '') {
            $liveValue = $this->config->get($executionPath);
            if ($liveValue !== null && is_scalar($liveValue)) {
                $liveStr = trim((string) $liveValue);
                if ($liveStr !== '' && is_numeric($liveStr) && bccomp($liveStr, '0', 6) >= 0) {
                    $fraction = bcdiv($liveStr, '100', $rateScale);

                    return ConversionRate::fromString($fraction, $rateScale);
                }
            }
        }

        $staticRate = $row['rate'] ?? null;
        if ($staticRate !== null && is_scalar($staticRate)) {
            $staticStr = trim((string) $staticRate);
            if ($staticStr !== '' && is_numeric($staticStr) && bccomp($staticStr, '0', 8) >= 0) {
                try {
                    return ConversionRate::fromString($staticStr, $rateScale);
                } catch (InvalidArgumentException) {
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFor(WalletConversionDirection $direction): array
    {
        $raw = $this->config->get($direction->feeConfigPath());

        return is_array($raw) ? $raw : [];
    }
}
