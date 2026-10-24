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

    private const NON_PROD_FIXTURE_PUBKEY = '41a614f803b6fd7c629f032c504764bd14aedda4';

    private const BASE58_ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    public function __construct(
        private readonly DepositProcessingService $depositService,
        private readonly FraudDetectionService $fraudDetectionService,
        private readonly DistributedLockService $lockService
    ) {}

    /**
     * Generate a deterministic user deposit address for USDT (TRC-20).
     *
     * FAIL-CLOSED IN PRODUCTION / LIVE MONEY:
     * Refuses to fall back to the public test vector when `app.env === 'production'`
     * or `real_money.enabled === true`.
     */
    public function getUserDepositAddress(string $userId): string
    {
        $masterPubkey = trim($this->configString('services.crypto.trc20_master_pubkey', ''));

        if ($masterPubkey === '') {
            if ($this->isLiveOrProductionRuntime()) {
                throw new RuntimeException('Crypto TRC-20 master pubkey is not configured; refusing insecure address derivation.');
            }

            $masterPubkey = self::NON_PROD_FIXTURE_PUBKEY;
        }

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

        $normalizedUsdt = PaymentDecimalNormalizer::normalizePositive($usdtAmount, 6, 'usdt_amount');
        $cleanTxHash = trim($txHash);
        if ($cleanTxHash === '') {
            throw new InvalidArgumentException('Transaction hash cannot be empty.');
        }

        $lockKey = "lock:payment:crypto:usdt:{$cleanTxHash}";
        $lock = $this->lockService->acquireLock($lockKey, 60);

        if (! $lock) {
            throw new RuntimeException("Concurrent processing for blockchain tx [{$cleanTxHash}].");
        }

        try {
            $exchangeRateRaw = $this->configString('services.crypto.usdt_thb_rate', self::DEFAULT_USDT_THB_RATE);
            $exchangeRate = PaymentDecimalNormalizer::normalizePositive($exchangeRateRaw, 4, 'usdt_thb_rate');
            $thbAmount = bcmul($normalizedUsdt, $exchangeRate, 4);

            $fraudData = [
                'event_id' => (string) Str::uuid(),
                'event_type' => 'DEPOSIT',
                'user_id' => $userId,
                'amount' => $thbAmount,
                'currency' => 'THB',
                'client_ip' => $clientIp,
                'reference_id' => "TRC20-{$cleanTxHash}",
            ];

            $this->fraudDetectionService->evaluateTransactionRisk($fraudData);

            $deposit = $this->depositService->processConfirmedDeposit(
                userId: $userId,
                amount: $thbAmount,
                gatewayProvider: 'CRYPTO_USDT_TRC20',
                gatewayReferenceId: "TRC20-{$cleanTxHash}",
                currency: 'THB',
                feeAmount: '0.0000',
                rawPayload: PaymentLogSanitizer::sanitize([
                    'tx_hash' => $cleanTxHash,
                    'to_address' => $toAddress,
                    'usdt_amount' => $normalizedUsdt,
                    'exchange_rate' => $exchangeRate,
                    'confirmations' => $confirmations,
                ]),
            );

            $app = Facade::getFacadeApplication();
            if ($app !== null && $app->bound('log')) {
                Log::info('Credited verified TRC-20 USDT deposit.', [
                    'tx_hash' => $cleanTxHash,
                    'thb_credited' => $thbAmount,
                ]);
            }

            return [
                'success' => true,
                'thb_credited' => $thbAmount,
                'deposit_id' => (string) $deposit->id,
            ];
        } finally {
            $this->lockService->releaseLock($lockKey, $lock);
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

    private function isLiveOrProductionRuntime(): bool
    {
        $container = Container::getInstance();
        if (! $container->bound('config')) {
            return false;
        }

        $cfg = $container->make('config');
        $env = strtolower((string) $cfg->get('app.env', 'production'));

        return $env === 'production' || (bool) $cfg->get('real_money.enabled', false);
    }
}
