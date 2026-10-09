<?php

declare(strict_types=1);

namespace App\DTOs\Lottery;

/**
 * The duty answer for one gross prize: what was withheld, what the winner
 * actually receives, and whether any further tax applies.
 *
 * Property names are the claim-record vocabulary (THB-suffixed) because this
 * object is what the claim lane and admin displays read.
 */
final readonly class GloStampDutyReport
{
    public function __construct(
        public string $grossPrizeThb,
        public string $stampDutyThb,
        public string $netPayoutThb,
        public bool $isIncomeTaxExempt,
        public string $rule,
    ) {}

    /**
     * @return array{gross_prize_thb: string, stamp_duty_thb: string, net_payout_thb: string, is_income_tax_exempt: bool, rule: string}
     */
    public function toArray(): array
    {
        return [
            'gross_prize_thb' => $this->grossPrizeThb,
            'stamp_duty_thb' => $this->stampDutyThb,
            'net_payout_thb' => $this->netPayoutThb,
            'is_income_tax_exempt' => $this->isIncomeTaxExempt,
            'rule' => $this->rule,
        ];
    }
}
