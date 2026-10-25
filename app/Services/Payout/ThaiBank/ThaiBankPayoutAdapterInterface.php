<?php

declare(strict_types=1);

namespace App\Services\Payout\ThaiBank;

use App\DTOs\Payment\PayoutInstruction;
use App\DTOs\Payment\PayoutOutcome;

interface ThaiBankPayoutAdapterInterface
{
    public function bank(): string;

    public function initiateTransfer(PayoutInstruction $instruction): PayoutOutcome;

    public function queryStatus(string $reference): PayoutOutcome;

    /** @param array<string, mixed> $headers */
    public function verifyCallback(string $rawBody, array $headers): bool;

    /** @return array{reference: string, state: string, providerReference: ?string, amount?: ?string, currency?: ?string} */
    public function parseCallback(string $rawBody): array;
}
