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
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, status: string, transaction_id: string, trans_ref: string}
     */
    public function processWebhook(array $payload, string $signature, string $clientIp): array
    {
        // B1 FIX — FAIL CLOSED ON A MISSING SECRET.
        //
        // This line previously read:
        //
        //   config('services.kbank.webhook_secret', env('KBANK_WEBHOOK_SECRET', 'test-kbank-secret'))
        //
        // There was no `kbank` key in config/services.php, so the first
        // argument was always null and the fallback always fired. On any
        // deployment that did not export KBANK_WEBHOOK_SECRET — and the key was
        // missing from .env.example, so that was every deployment by default —
        // the HMAC key was the literal string 'test-kbank-secret', published in
        // this repository.
        //
        // Anyone who has read this source can then compute a valid HMAC-SHA256
        // over an arbitrary payload and post it here, crediting their own
        // account through the real DepositProcessingService. That is
        // unauthenticated money creation.
        //
        // A missing secret must therefore be a hard refusal, not a default.
        $secret = (string) config('services.kbank.webhook_secret', '');

        if ($secret === '') {
            Log::critical('KBank webhook refused: services.kbank.webhook_secret is not configured. '
                .'No notification can be verified, so none is trusted. Set KBANK_WEBHOOK_SECRET.', [
                    'client_ip' => $clientIp,
                ]);

            throw new RuntimeException('KBank webhook lane is not configured; notification refused.');
        }

        $rawContent = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        $expectedSignature = hash_hmac('sha256', $rawContent, $secret);

        if (! hash_equals($expectedSignature, $signature)) {
            Log::warning("KBank Webhook signature mismatch from IP [{$clientIp}].");
            throw new InvalidArgumentException('Invalid KBank signature.');
        }

        $transRef = (string) ($payload['tx_id'] ?? $payload['transaction_no'] ?? '');
        $amount = number_format((float) ($payload['amount'] ?? 0), 4, '.', '');
        $userId = (string) ($payload['customer_id'] ?? $payload['reference_1'] ?? '');

        if (empty($transRef) || empty($userId) || bccomp($amount, '0.0000', 4) <= 0) {
            throw new InvalidArgumentException('Invalid KBank payment payload parameters.');
        }

        $lockKey = "lock:payment:kbank:{$transRef}";
        $lock = $this->lockService->acquireLock($lockKey, 30);

        if (! $lock) {
            throw new RuntimeException("Concurrent KBank webhook processing for transRef [{$transRef}].");
        }

        try {
            // Pre-flight Fraud Assessment
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
