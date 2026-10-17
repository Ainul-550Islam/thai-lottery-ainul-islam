<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Services\Deposit\DepositProcessingService;
use App\Services\Fraud\FraudDetectionService;
use App\Services\Lock\DistributedLockService;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class CryptoUsdtGatewayService
{
    private const REQUIRED_TRON_CONFIRMATIONS = 19;
    private const DEFAULT_USDT_THB_RATE = '36.5000';

    public function __construct(
        private readonly DepositProcessingService $depositService,
        private readonly FraudDetectionService $fraudDetectionService,
        private readonly DistributedLockService $lockService
    ) {}

    /**
     * Generate a deterministic user deposit address for USDT (TRC-20).
     */
    public function getUserDepositAddress(string $userId): string
    {
        $masterPubkey = (string) config('services.crypto.trc20_master_pubkey', env('CRYPTO_TRC20_MASTER_PUBKEY', '41a614f803b6fd7c629f032c504764bd14aedda4'));
        $derivedHash = hash_hmac('sha256', $userId, $masterPubkey);

        // Standard TRON Base58 representation simulation
        return 'T' . strtoupper(substr($derivedHash, 0, 33));
    }

    /**
     * Process confirmed incoming TRC-20 USDT transfer event from TRON node / webhook.
     *
     * @param string $txHash Blockchain Transaction Hash (64 hex characters)
     * @param string $toAddress Receiving TRON address
     * @param string $userId
     * @param string $usdtAmount Amount of USDT deposited (e.g. '100.000000')
     * @param int $confirmations Block confirmations
     * @param string $clientIp
     * @return array{success: bool, thb_credited: string, deposit_id: string}
     */
    public function processUsdtDeposit(
        string $txHash,
        string $toAddress,
        string $userId,
        string $usdtAmount,
        int $confirmations,
        string $clientIp
    ): array {
        if ($confirmations < self::REQUIRED_TRON_CONFIRMATIONS) {
            throw new RuntimeException("Insufficient block confirmations ({$confirmations}/" . self::REQUIRED_TRON_CONFIRMATIONS . ").");
        }

        if (!$this->isValidTronAddress($toAddress)) {
            throw new InvalidArgumentException("Invalid TRON TRC-20 address format.");
        }

        $lockKey = "lock:payment:crypto:usdt:{$txHash}";
        $lock = $this->lockService->acquireLock($lockKey, 60);

        if (!$lock) {
            throw new RuntimeException("Concurrent processing for blockchain tx [{$txHash}].");
        }

        try {
            // Convert USDT to THB using exchange rate
            $exchangeRate = (string) config('services.crypto.usdt_thb_rate', self::DEFAULT_USDT_THB_RATE);
            $thbAmount = bcmul($usdtAmount, $exchangeRate, 4);

            // Pre-flight Fraud Assessment
            $fraudData = [
                'event_id' => (string) \Illuminate\Support\Str::uuid(),
                'event_type' => 'DEPOSIT',
                'user_id' => $userId,
                'amount' => $thbAmount,
                'currency' => 'THB',
                'client_ip' => $clientIp,
                'reference_id' => "TRC20-{$txHash}",
            ];

            $this->fraudDetectionService->evaluateTransactionRisk($fraudData);

            // Post to Authoritative Double-Entry Ledger
            $deposit = $this->depositService->processConfirmedDeposit(
                userId: $userId,
                amount: $thbAmount,
                gatewayProvider: 'CRYPTO_USDT_TRC20',
                gatewayReferenceId: "TRC20-{$txHash}",
                currency: 'THB',
                feeAmount: '0.0000',
                rawPayload: [
                    'tx_hash' => $txHash,
                    'to_address' => $toAddress,
                    'usdt_amount' => $usdtAmount,
                    'exchange_rate' => $exchangeRate,
                    'confirmations' => $confirmations,
                ]
            );

            Log::info("Successfully credited crypto deposit [{$txHash}] for user [{$userId}]: {$usdtAmount} USDT -> {$thbAmount} THB.");

            return [
                'success' => true,
                'thb_credited' => $thbAmount,
                'deposit_id' => $deposit->id,
            ];
        } finally {
            $this->lockService->releaseLock($lock);
        }
    }

    /**
     * Validates TRON Base58 address format.
     */
    public function isValidTronAddress(string $address): bool
    {
        return (bool) preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $address);
    }
}
