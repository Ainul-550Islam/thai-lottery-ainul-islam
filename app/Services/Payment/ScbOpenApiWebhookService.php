<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\DepositTransaction;
use App\Models\IdempotencyRecord;
use App\Services\Deposit\DepositProcessingService;
use App\Services\Fraud\FraudDetectionService;
use App\Services\Lock\DistributedLockService;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class ScbOpenApiWebhookService
{
    public function __construct(
        private readonly DepositProcessingService $depositService,
        private readonly FraudDetectionService $fraudDetectionService,
        private readonly DistributedLockService $lockService
    ) {}

    /**
     * Process SCB Biller Payment Notification webhook.
     *
     * @param array<string, mixed> $payload
     * @param string $signature
     * @param string $clientIp
     * @return array{success: bool, status: string, transaction_id: string, trans_ref: string}
     */
    public function processWebhook(array $payload, string $signature, string $clientIp): array
    {
        // 1. Verify SCB HMAC Signature
        $secret = (string) config('services.scb.webhook_secret', env('SCB_WEBHOOK_SECRET', 'test-scb-secret'));
        $rawContent = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        $expectedSignature = hash_hmac('sha256', $rawContent, $secret);

        if (!hash_equals($expectedSignature, $signature)) {
            Log::warning("SCB Webhook signature mismatch from IP [{$clientIp}].");
            throw new InvalidArgumentException("Invalid SCB signature.");
        }

        $transRef = (string) ($payload['transactionId'] ?? $payload['transRef'] ?? '');
        $amount = number_format((float) ($payload['amount'] ?? 0), 4, '.', '');
        $userId = (string) ($payload['billPaymentRef1'] ?? $payload['ref1'] ?? '');

        if (empty($transRef) || empty($userId) || bccomp($amount, '0.0000', 4) <= 0) {
            throw new InvalidArgumentException("Invalid SCB payment notification payload parameters.");
        }

        // 2. Distributed Lock on Transaction Reference
        $lockKey = "lock:payment:scb:{$transRef}";
        $lock = $this->lockService->acquireLock($lockKey, 30);

        if (!$lock) {
            throw new RuntimeException("Concurrent SCB webhook processing for transRef [{$transRef}].");
        }

        try {
            // 3. Pre-flight Fraud Assessment
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

            // 4. Process Deposit via Core Double-Entry Deposit Engine
            $deposit = $this->depositService->processConfirmedDeposit(
                userId: $userId,
                amount: $amount,
                gatewayProvider: 'SCB_PROMPTPAY',
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
