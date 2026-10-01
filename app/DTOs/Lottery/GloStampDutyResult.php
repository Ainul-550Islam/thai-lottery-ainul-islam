<?php

declare(strict_types=1);

namespace App\DTOs\Lottery;

/**
 * Complete GLO stamp-duty projection used by the claim and settlement lanes.
 * Amounts remain exact two-decimal strings; no floating point is introduced.
 */
final readonly class GloStampDutyResult
{
    public function __construct(
        public string $grossPrizeThb,
        public string $stampDutyThb,
        public string $netPayoutThb,
        public bool $isIncomeTaxExempt = true,
    ) {
    }
}
