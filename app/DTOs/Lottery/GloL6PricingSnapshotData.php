<?php

declare(strict_types=1);

namespace App\DTOs\Lottery;

use App\Enums\Currency;
use InvalidArgumentException;

/**
 * Immutable pricing & prize-basis snapshot sealed at GLO L6 reservation/purchase time.
 */
final class GloL6PricingSnapshotData
{
    public readonly string $snapshotHash;

    public function __construct(
        public readonly string $unitPrice,
        public readonly Currency $currency,
        public readonly int $digits,
        public readonly int $fullSeriesUnits,
        public readonly string $fullSeriesPrizePool,
        public readonly string $stampDutyDivisor,
        public readonly string $stampDutyPerUnitBaht,
        public readonly bool $incomeTaxExempt,
        public readonly string $pricingRuleVersion,
        public readonly string $capturedAtIso,
        ?string $snapshotHash = null,
    ) {
        if (preg_match('/^\d{1,8}\.\d{2}$/', $this->unitPrice) !== 1 || bccomp($this->unitPrice, '0.00', 2) <= 0) {
            throw new InvalidArgumentException('GLO L6 unitPrice must be a strictly positive 2-decimal string.');
        }

        if ($this->digits !== 6) {
            throw new InvalidArgumentException('GLO L6 pricing snapshot requires digits === 6.');
        }

        $computed = self::computeHash(
            unitPrice: $this->unitPrice,
            currency: $this->currency->value,
            digits: $this->digits,
            fullSeriesUnits: $this->fullSeriesUnits,
            fullSeriesPrizePool: $this->fullSeriesPrizePool,
            stampDutyDivisor: $this->stampDutyDivisor,
            stampDutyPerUnitBaht: $this->stampDutyPerUnitBaht,
            incomeTaxExempt: $this->incomeTaxExempt,
            pricingRuleVersion: $this->pricingRuleVersion,
            capturedAtIso: $this->capturedAtIso,
        );

        if ($snapshotHash !== null && ! hash_equals($computed, $snapshotHash)) {
            throw new InvalidArgumentException('GLO L6 pricing snapshot hash verification failed (tampered snapshot).');
        }

        $this->snapshotHash = $computed;
    }

    public static function fromConfig(?string $capturedAtIso = null): self
    {
        $priceRaw = trim((string) config('glo.l6.ticket_price', config('glo.ticket.price', '80.00')));
        if (preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $priceRaw) !== 1) {
            throw new InvalidArgumentException('Configured GLO L6 ticket_price is invalid.');
        }

        $unitPrice = bcadd($priceRaw, '0.00', 2);
        $digits = (int) config('glo.l6.digits', 6);
        $fullUnits = (int) config('glo.l6.full_sale_units', 1000000);
        $fullPool = bcadd((string) config('glo.l6.full_allocation', '48000000.00'), '0.00', 2);
        $divisor = trim((string) config('glo.stamp_duty.divisor', '200'));
        $perUnit = bcadd((string) config('glo.stamp_duty.per_unit_baht', '1.00'), '0.00', 2);
        $taxExempt = (bool) config('glo.stamp_duty.income_tax_exempt', true);
        $version = trim((string) config('glo.l6.purchase.pricing_rule_version', 'glo-l6-2026.1'));

        return new self(
            unitPrice: $unitPrice,
            currency: Currency::THB,
            digits: $digits,
            fullSeriesUnits: $fullUnits,
            fullSeriesPrizePool: $fullPool,
            stampDutyDivisor: $divisor,
            stampDutyPerUnitBaht: $perUnit,
            incomeTaxExempt: $taxExempt,
            pricingRuleVersion: $version !== '' ? $version : 'glo-l6-2026.1',
            capturedAtIso: $capturedAtIso ?? now()->toIso8601String(),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            unitPrice: bcadd((string) ($payload['unit_price'] ?? '0.00'), '0.00', 2),
            currency: Currency::from(strtoupper((string) ($payload['currency'] ?? 'THB'))),
            digits: (int) ($payload['digits'] ?? 6),
            fullSeriesUnits: (int) ($payload['full_series_units'] ?? 1000000),
            fullSeriesPrizePool: bcadd((string) ($payload['full_series_prize_pool'] ?? '48000000.00'), '0.00', 2),
            stampDutyDivisor: (string) ($payload['stamp_duty_divisor'] ?? '200'),
            stampDutyPerUnitBaht: bcadd((string) ($payload['stamp_duty_per_unit_baht'] ?? '1.00'), '0.00', 2),
            incomeTaxExempt: (bool) ($payload['income_tax_exempt'] ?? true),
            pricingRuleVersion: (string) ($payload['pricing_rule_version'] ?? 'glo-l6-2026.1'),
            capturedAtIso: (string) ($payload['captured_at'] ?? ''),
            snapshotHash: isset($payload['snapshot_hash']) ? (string) $payload['snapshot_hash'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'unit_price' => $this->unitPrice,
            'currency' => $this->currency->value,
            'digits' => $this->digits,
            'full_series_units' => $this->fullSeriesUnits,
            'full_series_prize_pool' => $this->fullSeriesPrizePool,
            'stamp_duty_divisor' => $this->stampDutyDivisor,
            'stamp_duty_per_unit_baht' => $this->stampDutyPerUnitBaht,
            'income_tax_exempt' => $this->incomeTaxExempt,
            'pricing_rule_version' => $this->pricingRuleVersion,
            'captured_at' => $this->capturedAtIso,
            'snapshot_hash' => $this->snapshotHash,
        ];
    }

    private static function computeHash(
        string $unitPrice,
        string $currency,
        int $digits,
        int $fullSeriesUnits,
        string $fullSeriesPrizePool,
        string $stampDutyDivisor,
        string $stampDutyPerUnitBaht,
        bool $incomeTaxExempt,
        string $pricingRuleVersion,
        string $capturedAtIso,
    ): string {
        $canonical = json_encode([
            'unit_price' => $unitPrice,
            'currency' => $currency,
            'digits' => $digits,
            'full_series_units' => $fullSeriesUnits,
            'full_series_prize_pool' => $fullSeriesPrizePool,
            'stamp_duty_divisor' => $stampDutyDivisor,
            'stamp_duty_per_unit_baht' => $stampDutyPerUnitBaht,
            'income_tax_exempt' => $incomeTaxExempt,
            'pricing_rule_version' => $pricingRuleVersion,
            'captured_at' => $capturedAtIso,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return hash('sha256', $canonical);
    }
}
