<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditAction;
use App\Enums\Currency;
use App\Enums\RiskLevel;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Deposit\DepositProcessingService;
use App\Services\Payment\PaymentDecimalNormalizer;
use App\Services\Payment\PaymentLogSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

/** Non-production legacy sandbox ingestion only; never a real provider protocol. */
final class DepositWebhookController extends Controller
{
    public function __construct(private readonly DepositProcessingService $depositService) {}

    public function handleWebhook(Request $request, string $provider): JsonResponse
    {
        // This old payload lets its signer nominate a local user, so it must
        // never be usable with real money or masquerade as a live provider.
        if ($provider !== 'sandbox' || ! app()->environment(['testing', 'local'])
            || (bool) config('real_money.enabled', false)
            || ! (bool) config('payment.gateways.sandbox.enabled', false)) {
            return $this->error('WEBHOOK_NOT_CONFIGURED', 503);
        }
        $secret = config('payment.gateways.sandbox.webhook_secret');
        if (! is_string($secret) || trim($secret) === '') {
            return $this->error('WEBHOOK_NOT_CONFIGURED', 503);
        }
        $signature = $request->header('X-Webhook-Signature');
        $timestamp = $request->header('X-Webhook-Timestamp');
        $body = $request->getContent();
        if ($timestamp !== null && (! is_string($timestamp)
            || preg_match('/\A[0-9]{10}\z/D', $timestamp) !== 1
            || abs(now()->timestamp - (int) $timestamp) > 300)) {
            return $this->error('INVALID_WEBHOOK_SIGNATURE', 401);
        }
        $signed = $timestamp === null ? $body : $timestamp.'.'.$body;
        if (! is_string($signature) || preg_match('/\A[a-f0-9]{64}\z/D', $signature) !== 1
            || ! hash_equals(hash_hmac('sha256', $signed, $secret), $signature)) {
            return $this->error('INVALID_WEBHOOK_SIGNATURE', 401);
        }

        $payload = $request->json()->all();
        $userId = $payload['user_id'] ?? null;
        $reference = $payload['transaction_id'] ?? $payload['reference'] ?? null;
        $currency = $payload['currency'] ?? null;
        try {
            if ((! is_int($userId) && ! is_string($userId))
                || preg_match('/\A[1-9][0-9]{0,17}\z/D', (string) $userId) !== 1
                || ! is_string($reference) || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $reference) !== 1
                || ! is_string($currency) || Currency::tryFrom($currency) === null
                || (! is_int($payload['amount'] ?? null) && ! is_string($payload['amount'] ?? null))
                || (! is_int($payload['fee'] ?? '0') && ! is_string($payload['fee'] ?? '0'))) {
                throw new InvalidArgumentException('Invalid sandbox facts.');
            }
            $amount = PaymentDecimalNormalizer::normalizePositive($payload['amount'], 4);
            $fee = PaymentDecimalNormalizer::normalizeNonNegative($payload['fee'] ?? '0', 4, 'fee');
            if (bccomp($fee, $amount, 4) >= 0) {
                throw new InvalidArgumentException('Invalid sandbox net amount.');
            }
        } catch (InvalidArgumentException) {
            return $this->error('INVALID_WEBHOOK_PAYLOAD', 400);
        }

        try {
            $deposit = $this->depositService->processConfirmedDeposit(
                userId: (string) $userId,
                amount: $amount,
                gatewayProvider: 'SANDBOX',
                gatewayReferenceId: $reference,
                currency: $currency,
                feeAmount: $fee,
                rawPayload: PaymentLogSanitizer::sanitize($payload),
            );
            AuditLog::query()->create([
                'action' => AuditAction::Deposit, 'risk_level' => RiskLevel::Medium,
                'auditable_type' => User::class, 'auditable_id' => (int) $userId,
                'description' => 'Signed non-production sandbox deposit observed; not provider-authenticated real money.',
                'metadata' => ['gateway' => 'sandbox', 'deposit_uuid' => (string) $deposit->id, 'reference_hash' => hash('sha256', $reference)],
            ]);

            return response()->json(['success' => true, 'status' => 'DEPOSIT_PROCESSED',
                'deposit_id' => $deposit->id, 'deposit_number' => $deposit->deposit_number]);
        } catch (InvalidArgumentException) {
            return $this->error('WEBHOOK_TERMS_CONFLICT', 409);
        } catch (Throwable) {
            // Never serialize provider payloads, SQL, credentials or diagnostics.
            return $this->error('PROCESSING_FAILED', 500);
        }
    }

    private function error(string $code, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'error' => $code], $status);
    }
}
