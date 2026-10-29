<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Exceptions\FinancialException;
use App\Services\Deposit\DepositProcessingService;
use App\Services\Fraud\FraudDetectionService;
use App\Services\Lock\DistributedLockService;

/**
 * Retired, unsupported cryptocurrency integration.
 *
 * Its former HMAC-to-Base58 generator did not derive a spendable TRON key or
 * verify the checksum, and the deposit method trusted caller-supplied chain
 * confirmations. A syntactically plausible address is not proof of custody or
 * an on-chain payment. Do not re-enable this service without an independently
 * reviewed custody, chain-verification and historical-obligation contract.
 */
class CryptoUsdtGatewayService
{
    // Retain the existing constructor contract for callers; neither operation
    // below touches these dependencies while the rail is unavailable.
    public function __construct(
        private readonly DepositProcessingService $depositService,
        private readonly FraudDetectionService $fraudDetectionService,
        private readonly DistributedLockService $lockService
    ) {}

    public function getUserDepositAddress(string $userId): string
    {
        $this->unavailable();
    }

    /** @return array{success: bool, thb_credited: string, deposit_id: string} */
    public function processUsdtDeposit(
        string $txHash,
        string $toAddress,
        string $userId,
        string $usdtAmount,
        int $confirmations,
        string $clientIp
    ): array {
        $this->unavailable();
    }

    /** Checks textual shape only; this is NOT Base58Check or ownership proof. */
    public function isValidTronAddress(string $address): bool
    {
        return (bool) preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $address);
    }

    private function unavailable(): never
    {
        throw FinancialException::withCode(
            'crypto_unavailable',
            'Cryptocurrency deposits are not available.',
        );
    }
}
