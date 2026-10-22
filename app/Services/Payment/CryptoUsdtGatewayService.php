<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Services\Deposit\DepositProcessingService;
use App\Services\Fraud\FraudDetectionService;
use App\Services\Lock\DistributedLockService;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class CryptoUsdtGatewayService
{
    private const REQUIRED_TRON_CONFIRMATIONS = 19;

    private const DEFAULT_USDT_THB_RATE = '36.5000';

    private const BASE58_ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

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
        $masterPubkey = $this->configString(
            'services.crypto.trc20_master_pubkey',
            (string) env('CRYPTO_TRC20_MASTER_PUBKEY', '41a614f803b6fd7c629f032c504764bd14aedda4')
        );
        $rawBytes = hash_hmac('sha512', $userId, $masterPubkey, true);

        $chars = '';
        $len = strlen($rawBytes);
        for ($i = 0; $i < 33; $i++) {
            $byte = ord($rawBytes[$i % $len]);
            $chars .= self::BASE58_ALPHABET[$byte % 58];
        }

        return 'T'.$chars;
    }

    /**
     * Process confirmed incoming TRC-20 USDT transfer event from TRON node / webhook.
     *
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
            throw new RuntimeException("Insufficient block confirmations ({$confirmations}/".self::REQUIRED_TRON_CONFIRMATIONS.').');
        }

        if (! $this->isValidTronAddress($toAddress)) {
            throw new InvalidArgumentException('Invalid TRON TRC-20 address format.');
        }

        $lockKey = "lock:payment:crypto:usdt:{$txHash}";
        $lock = $this->lockService->acquireLock($lockKey, 60);

        if (! $lock) {
            throw new RuntimeException("Concurrent processing for blockchain tx [{$txHash}].");
        }

        try {
            $exchangeRate = $this->configString('services.crypto.usdt_thb_rate', self::DEFAULT_USDT_THB_RATE);
            $thbAmount = bcmul($usdtAmount, $exchangeRate, 4);

            $fraudData = [
                'event_id' => (string) Str::uuid(),
                'event_type' => 'DEPOSIT',
                'user_id' => $userId,
                'amount' => $thbAmount,
                'currency' => 'THB',
                'client_ip' => $clientIp,
                'reference_id' => "TRC20-{$txHash}",
            ];

            $this->fraudDetectionService->evaluateTransactionRisk($fraudData);

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

            $app = Facade::getFacadeApplication();
            if ($app !== null && $app->bound('log')) {
                Log::info("Successfully credited crypto deposit [{$txHash}] for user [{$userId}]: {$usdtAmount} USDT -> {$thbAmount} THB.");
            }

            return [
                'success' => true,
                'thb_credited' => $thbAmount,
                'deposit_id' => (string) $deposit->id,
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

    private function configString(string $key, string $default): string
    {
        $container = Container::getInstance();
        if (! $container->bound('config')) {
            return $default;
        }

        return (string) $container->make('config')->get($key, $default);
    }
}
