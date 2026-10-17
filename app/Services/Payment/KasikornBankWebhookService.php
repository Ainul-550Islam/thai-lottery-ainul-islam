<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Services\Deposit\DepositProcessingService;
use App\Services\Fraud\FraudDetectionService;
use App\Services\Lock\DistributedLockService;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class KasikornBankWebhookService
{
    public function __construct(
        private readonly DepositProcessingService $depositService,
        private readonly FraudDetectionService $fraudDetectionService,
        private readonly DistributedLockService $lockService
    ) {}

    /**
     * Process Kasikornbank (KBank) Payment Notification webhook.
     *
     * @param array<string, mixed> $payload
     * @param string $signature
     * @param string $clientIp
     * @return array{success: bool, status: string, transaction_id: string, trans_ref: string}
     */
    public function processWebhook(array $payload, string $signature, string $clientIp): array
    {
        $secret = (string) config('services.kbank.webhook_secret', env('KBANK_WEBHOOK_SECRET', 'test-kbank-secret'));
        $rawContent = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        $expectedSignature = hash_hmac('sha256', $rawContent, $secret);

        if (!hash_equals($expectedSignature, $signature)) {
            Log::warning("KBank Webhook signature mismatch from IP [{$clientIp}].");
            throw new InvalidArgumentException("Invalid KBank signature.");
        }

        $transRef = (string) ($payload['tx_id'] ?? $payload['transaction_no'] ?? '');
        $amount = number_format((float) ($payload['amount'] ?? 0), 4, '.', '');
        $userId = (string) ($payload['customer_id'] ?? $payload['reference_1'] ?? '');

        if (empty($transRef) || empty($userId) || bccomp($amount, '0.0000', 4) <= 0) {
            throw new InvalidArgumentException("Invalid KBank payment payload parameters.");
        }

        $lockKey = "lock:payment:kbank:{$transRef}";
        $lock = $this->lockService->acquireLock($lockKey, 30);

        if (!$lock) {
            throw new RuntimeException("Concurrent KBank webhook processing for transRef [{$transRef}].");
        }

        try {
            // Pre-flight Fraud Assessment
            $fraudData = [
                'event_id' => (string) \Illuminate\Support\Str::uuid(),
                'event_type' => 'DEPOSIT',
                'user_id' => $userId,
                'amount' => $amount,
                'currency' => 'THB',
                'client_ip' => $clientIp,
                'reference_id' => $transRef,
            ];

            $this->fraudDetectionService->evaluateTransactionRisk($fraudData);

            // Process Deposit via Core Double-Entry Deposit Engine
            $deposit = $this->depositService->processConfirmedDeposit(
                userId: $userId,
                amount: $amount,
                gatewayProvider: 'KBANK_PROMPTPAY',
                gatewayReferenceId: $transRef,
                currency: 'THB',
                feeAmount: '0.0000',
                rawPayload: $payload
            );

            return [
                'success' => true,
                'status' => 'COMPLETED',
                'transaction_id' => $deposit->id,
                'trans_ref' => $transRef,
            ];
        } finally {
            $this->lockService->releaseLock($lock);
        }
    }
}
