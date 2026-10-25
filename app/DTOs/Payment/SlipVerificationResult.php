<?php

declare(strict_types=1);

namespace App\DTOs\Payment;

use Carbon\CarbonImmutable;

final readonly class SlipVerificationResult
{
    /**
     * @param array<string, mixed> $rawSanitised
     */
    public function __construct(
        public string $transRef,
        public string $amount,
        public string $currency,
        public CarbonImmutable $slipDateTime,
        public string $sendingBank,
        public string $receiverAccountMasked,
        public string $receiverName,
        public string $senderName,
        public array $rawSanitised = [],
        public bool $valid = true,
        public bool $duplicate = false,
        public string $receivingBank = '',
    ) {}
}
