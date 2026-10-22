<?php

declare(strict_types=1);

namespace App\Services\Wallet\Conversion;

use App\Contracts\Wallet\ConversionFeeProviderContract;
use App\DTOs\Wallet\ConversionFeeBreakdownData;
use App\Enums\ConversionFeeBearer;
use App\Enums\WalletConversionDirection;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use InvalidArgumentException;

/** Computes the published wallet conversion charge using exact decimal arithmetic. */
final class ConversionFeeCalculator implements ConversionFeeProviderContract
{
    public function __construct(private readonly ConfigRepository $config) {}

    public function breakdownFor(WalletConversionDirection $direction, string $amount, string $currency): ConversionFeeBreakdownData
    {
        if (! preg_match('/^\d+(?:\.\d+)?$/', $amount) || ! is_finite((float) $amount)) {
            throw new InvalidArgumentException('Conversion amount must be a non-negative decimal.');
        }
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Conversion currency must be a three-letter code.');
        }

        $key = $direction->value;
        $row = $this->config->get('fees.categories.'.$key, []);
        $row = is_array($row) ? $row : [];
        $precision = max(0, min(8, (int) $this->config->get('conversions.fee.scale', 2)));
        $rateScale = max(0, min(8, (int) $this->config->get('conversions.fee.rate_scale', 4)));
        $bearer = ConversionFeeBearer::tryFrom((string) $this->config->get('conversions.fee.bearer', 'source')) ?? ConversionFeeBearer::Source;
        $ruleVersion = (string) ($row['rule_version'] ?? $this->config->get('conversions.fee.rule_version_fallback', '1'));
        $ruleVersion = $ruleVersion !== '' ? $ruleVersion : '1';
        $calculation = (string) ($row['calculation'] ?? 'percentage');
        $rate = $this->resolveRate($row);
        $configured = (bool) ($row['enabled'] ?? false)
            && (bool) ($row['public_visible'] ?? false)
            && in_array($calculation, ['percentage', 'fixed'], true)
            && $rate !== null
            && (string) ($row['currency'] ?? $currency) === $currency;

        $zero = bcadd('0', '0', $precision);
        $fee = $zero;
        $percent = '0.00';
        $normalizedRate = '0'.($rateScale > 0 ? '.'.str_repeat('0', $rateScale) : '');
        if ($configured) {
            $normalizedRate = bcadd($rate, '0', $rateScale);
            if ($calculation === 'percentage') {
                // Published static rates are fractions (0.0100 = 1%); a live execution
                // percentage is explicitly in whole-percent units and is converted below.
                $fraction = $normalizedRate;
                $percent = bcmul($fraction, '100', 2);
                $fee = bcadd(bcmul($amount, $fraction, $precision + 4), '0', $precision);
            } else {
                $fee = bcadd($normalizedRate, '0', $precision);
                $percent = bccomp($amount, '0', $precision) === 1
                    ? bcdiv(bcmul($fee, '100', 8), $amount, 2)
                    : '0.00';
            }
        }
        $net = bccomp($amount, $fee, $precision) >= 0
            ? bcsub($amount, $fee, $precision)
            : $zero;

        return new ConversionFeeBreakdownData(
            ruleKey: $key,
            ruleVersion: $ruleVersion,
            direction: $direction,
            rate: $normalizedRate,
            percentage: $percent,
            basisPoints: (int) bcmul($percent, '100', 0),
            feeAmount: $fee,
            netAmount: $net,
            currency: $currency,
            calculation: $calculation,
            bearer: $bearer,
            isConfigured: $configured,
            effectiveFrom: isset($row['effective_from']) ? (string) $row['effective_from'] : null,
            effectiveTo: isset($row['effective_to']) ? (string) $row['effective_to'] : null,
            labelKey: isset($row['label_key']) ? (string) $row['label_key'] : null,
            descriptionKey: isset($row['description_key']) ? (string) $row['description_key'] : null,
        );
    }

    public function isConfigured(WalletConversionDirection $direction): bool
    {
        $currency = (string) $this->config->get('conversions.currency', 'THB');
        return $this->breakdownFor($direction, '100.00', $currency)->isConfigured;
    }

    public function publishedPercentage(WalletConversionDirection $direction): ?string
    {
        $currency = (string) $this->config->get('conversions.currency', 'THB');
        $breakdown = $this->breakdownFor($direction, '100.00', $currency);
        return $breakdown->isConfigured ? $breakdown->percentage : null;
    }

    /** @param array<string, mixed> $row */
    private function resolveRate(array $row): ?string
    {
        $published = $row['rate'] ?? null;
        if (is_string($row['execution'] ?? null) && $row['execution'] !== '') {
            $live = $this->config->get($row['execution']);
            if (is_numeric($live) && (float) $live >= 0) {
                return bcdiv((string) $live, '100', 10);
            }
        }
        if (! is_numeric($published) || (float) $published < 0) {
            return null;
        }
        return (string) $published;
    }
}
