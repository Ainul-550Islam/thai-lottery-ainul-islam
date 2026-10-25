<?php

declare(strict_types=1);

namespace App\DTOs\Payment;

final readonly class PayoutInstruction
{
    public function __construct(
        public string $reference,
        public string $amount,
        public string $currency,
        public string $destinationType,
        public ?string $bankCode,
        public string $accountNumber,
        public string $accountName,
        public string $memo,
    ) {}
}
