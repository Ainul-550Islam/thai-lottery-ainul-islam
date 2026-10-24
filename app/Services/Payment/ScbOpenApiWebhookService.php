<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Services\Deposit\DepositProcessingService;
use App\Services\Fraud\FraudDetectionService;
use App\Services\Lock\DistributedLockService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, status: string, transaction_id: string, trans_ref: string}
     */
    public function processWebhook(array $payload, string $signature, string $clientIp): array
    {
        // 1. Verify SCB HMAC Signature
        //
        // B1 FIX — FAIL CLOSED ON A MISSING SECRET.
        //
        // This line previously read:
        //
        //   config('services.scb.webhook_secret', env('SCB_WEBHOOK_SECRET', 'test-scb-secret'))
        //
        // There was no `scb` key in config/services.php, so the first argument
        // was always null and the fallback always fired. On any deployment
        // that did not export SCB_WEBHOOK_SECRET — and the key was missing
        // from .env.example, so that was every deployment by default — the
        // HMAC key was the literal string 'test-scb-secret', published in this
        // repository.
        //
        // Anyone who has read this source can then compute a valid HMAC-SHA256
        // over an arbitrary payload and post it here, crediting their own
        // account through the real DepositProcessingService. That is
        // unauthenticated money creation.
        //
        // A missing secret must therefore be a hard refusal, not a default.
        $secret = (string) config('services.scb.webhook_secret', '');

        if ($secret === '') {
            Log::critical('SCB webhook refused: services.scb.webhook_secret is not configured. '
                .'No notification can be verified, so none is trusted. Set SCB_WEBHOOK_SECRET.', [
                    'client_ip' => $clientIp,
                ]);

            throw new RuntimeException('SCB webhook lane is not configured; notification refused.');
        }

        $rawContent = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        $expectedSignature = hash_hmac('sha256', $rawContent, $secret);

        if (! hash_equals($expectedSignature, $signature)) {
            Log::warning("SCB Webhook signature mismatch from IP [{$clientIp}].");
            throw new InvalidArgumentException('Invalid SCB signature.');
        }

        $transRef = trim((string) ($payload['transactionId'] ?? $payload['transRef'] ?? ''));
        $rawAmount = $payload['amount'] ?? null;
        $amount = PaymentDecimalNormalizer::normalizeInboundWebhookPositive($rawAmount, 4, 'scb.amount');
        $currency = strtoupper(trim((string) ($payload['currency'] ?? 'THB')));
        if ($currency !== 'THB') {
            throw new InvalidArgumentException("Unsupported SCB payment currency [{$currency}].");
        }
        $userId = trim((string) ($payload['billPaymentRef1'] ?? $payload['ref1'] ?? ''));

        if ($transRef === '' || $userId === '') {
            throw new InvalidArgumentException('Invalid SCB payment notification payload parameters.');
        }

        // 2. Distributed Lock on Transaction Reference
        $lockKey = "lock:payment:scb:{$transRef}";
        $lock = $this->lockService->acquireLock($lockKey, 30);

        if (! $lock) {
            throw new RuntimeException("Concurrent SCB webhook processing for transRef [{$transRef}].");
        }

        try {
            // 3. Pre-flight Fraud Assessment
            $fraudData = [
                'event_id' => (string) Str::uuid(),
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
                rawPayload: PaymentLogSanitizer::sanitize($payload),
            );

            return [
                'success' => true,
                'status' => 'COMPLETED',
                'transaction_id' => $deposit->id,
                'trans_ref' => $transRef,
            ];
        } finally {
            $this->lockService->releaseLock($lockKey, $lock);
        }
    }
}
