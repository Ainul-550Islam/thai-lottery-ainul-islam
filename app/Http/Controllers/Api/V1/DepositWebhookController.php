<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Deposit\DepositProcessingService;
use App\Services\Payment\PaymentDecimalNormalizer;
use App\Services\Payment\PaymentLogSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class DepositWebhookController extends Controller
{
    public function __construct(
        private readonly DepositProcessingService $depositService
    ) {}

    /**
     * Ingest cryptographically signed payment gateway webhook callbacks.
     *
     * FAIL-CLOSED:
     * - Requires a configured per-provider HMAC secret; unconfigured providers return 503.
     * - Verifies constant-time HMAC-SHA256 over the raw request body (plus optional timestamp
     *   replay tolerance when `X-Webhook-Timestamp` is supplied or required).
     * - Normalizes decimal strings via BCMath without floating-point casts and sanitizes logs/errors.
     */
    public function handleWebhook(Request $request, string $provider): JsonResponse
    {
        $normalizedProvider = strtolower(trim($provider));
        if (preg_match('/^[a-z0-9_-]{2,32}$/', $normalizedProvider) !== 1) {
            return response()->json([
                'success' => false,
                'error' => 'UNSUPPORTED_WEBHOOK_PROVIDER',
            ], Response::HTTP_BAD_REQUEST);
        }

        $secret = $this->resolveWebhookSecret($normalizedProvider);
        if ($secret === '') {
            Log::critical('Deposit webhook refused: provider webhook_secret is not configured.', [
                'provider' => $normalizedProvider,
                'client_ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'WEBHOOK_NOT_CONFIGURED',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (! $this->verifyRequestSignature($request, $normalizedProvider, $secret)) {
            Log::warning('Deposit webhook signature verification failed.', [
                'provider' => $normalizedProvider,
                'client_ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'INVALID_WEBHOOK_SIGNATURE',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $payload = $request->json()->all();
        if (! is_array($payload) || $payload === []) {
            $payload = $request->all();
        }

        $userId = trim((string) ($payload['user_id'] ?? $payload['custom_fields']['user_id'] ?? ''));
        $referenceId = trim((string) ($payload['transaction_id'] ?? $payload['reference'] ?? ''));
        $currency = strtoupper(trim((string) ($payload['currency'] ?? 'THB')));
        $rawAmount = $payload['amount'] ?? null;
        $rawFee = $payload['fee'] ?? '0.0000';

        if (
            $userId === ''
            || $referenceId === ''
            || strlen($referenceId) > 128
            || preg_match('/^[A-Z]{3}$/', $currency) !== 1
            || (! is_string($rawAmount) && ! is_int($rawAmount))
            || (! is_string($rawFee) && ! is_int($rawFee))
        ) {
            return response()->json([
                'success' => false,
                'error' => 'INVALID_WEBHOOK_PAYLOAD',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $amount = PaymentDecimalNormalizer::normalizePositive($rawAmount, 4, 'amount');
            $feeAmount = PaymentDecimalNormalizer::normalizeNonNegative($rawFee, 4, 'fee');
        } catch (InvalidArgumentException) {
            return response()->json([
                'success' => false,
                'error' => 'INVALID_WEBHOOK_PAYLOAD',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $deposit = $this->depositService->processConfirmedDeposit(
                userId: $userId,
                amount: $amount,
                gatewayProvider: strtoupper($normalizedProvider),
                gatewayReferenceId: $referenceId,
                currency: $currency,
                feeAmount: $feeAmount,
                rawPayload: PaymentLogSanitizer::sanitize($payload),
            );

            return response()->json([
                'success' => true,
                'status' => 'DEPOSIT_PROCESSED',
                'deposit_id' => $deposit->id,
                'deposit_number' => $deposit->deposit_number,
            ], Response::HTTP_OK);
        } catch (InvalidArgumentException) {
            return response()->json([
                'success' => false,
                'error' => 'WEBHOOK_TERMS_CONFLICT',
            ], Response::HTTP_CONFLICT);
        } catch (Throwable $e) {
            Log::error('Deposit webhook processing failed.', [
                'provider' => $normalizedProvider,
                'exception' => $e::class,
            ]);

            return response()->json([
                'success' => false,
                'error' => 'PROCESSING_FAILED',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function resolveWebhookSecret(string $provider): string
    {
        $candidates = [
            config("payment.gateways.{$provider}.webhook_secret"),
            config("services.{$provider}.webhook_secret"),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }

    private function verifyRequestSignature(Request $request, string $provider, string $secret): bool
    {
        $configuredHeader = (string) config("payment.gateways.{$provider}.signature_header", 'X-Webhook-Signature');
        $signatureHeader = trim((string) (
            $request->header($configuredHeader)
            ?? $request->header('X-Webhook-Signature')
            ?? $request->header('X-Signature')
            ?? ''
        ));

        if ($signatureHeader === '') {
            return false;
        }

        $rawBody = $request->getContent();
        $tolerance = max(1, (int) config('payment.webhooks.timestamp_tolerance', 300));

        // Support structured `t=<unix>,v1=<hex>` format as well as `X-Webhook-Timestamp` + hex HMAC.
        if (str_contains($signatureHeader, 't=') && str_contains($signatureHeader, 'v1=')) {
            $parts = [];
            foreach (explode(',', $signatureHeader) as $segment) {
                $kv = explode('=', trim($segment), 2);
                if (count($kv) === 2) {
                    $parts[trim($kv[0])] = trim($kv[1]);
                }
            }

            $ts = $parts['t'] ?? '';
            $sig = $parts['v1'] ?? '';
            if ($ts === '' || ! ctype_digit($ts) || $sig === '') {
                return false;
            }

            if (abs(now()->timestamp - (int) $ts) > $tolerance) {
                return false;
            }

            return hash_equals(hash_hmac('sha256', $ts.'.'.$rawBody, $secret), $sig);
        }

        $timestampHeader = trim((string) $request->header('X-Webhook-Timestamp', ''));
        if ($timestampHeader !== '') {
            if (! ctype_digit($timestampHeader) || abs(now()->timestamp - (int) $timestampHeader) > $tolerance) {
                return false;
            }

            $signedWithTimestamp = hash_hmac('sha256', $timestampHeader.'.'.$rawBody, $secret);
            if (hash_equals($signedWithTimestamp, $signatureHeader)) {
                return true;
            }
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $signatureHeader);
    }
}
