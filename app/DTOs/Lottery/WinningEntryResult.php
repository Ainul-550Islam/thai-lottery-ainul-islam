<?php

declare(strict_types=1);

namespace App\DTOs\Lottery;

use App\Enums\LotteryPrizeTier;

readonly class WinningEntryResult
{
    public function __construct(
        public bool $isWinner,
        public ?LotteryPrizeTier $prizeTier,
        public string $betAmount,
        public string $payoutMultiplier,
        public string $grossPayoutAmount,
        public string $whtTaxAmount,
        public string $netPayoutAmount,
        public array $matchedNumbers = [],
        public ?string $description = null
    ) {}

    public static function nonWinner(string $betAmount): self
    {
        return new self(
            isWinner: false,
            prizeTier: null,
            betAmount: $betAmount,
            payoutMultiplier: '0.00',
            grossPayoutAmount: '0.0000',
            whtTaxAmount: '0.0000',
            netPayoutAmount: '0.0000',
            matchedNumbers: [],
            description: 'Non-winning ticket entry'
        );
    }

    public static function winner(
        LotteryPrizeTier $prizeTier,
        string $betAmount,
        string $multiplier,
        string $grossPayout,
        string $whtTax,
        string $netPayout,
        array $matchedNumbers,
        string $description
    ): self {
        return new self(
            isWinner: true,
            prizeTier: $prizeTier,
            betAmount: $betAmount,
            payoutMultiplier: $multiplier,
            grossPayoutAmount: $grossPayout,
            whtTaxAmount: $whtTax,
            netPayoutAmount: $netPayout,
            matchedNumbers: $matchedNumbers,
            description: $description
        );
    }
}
