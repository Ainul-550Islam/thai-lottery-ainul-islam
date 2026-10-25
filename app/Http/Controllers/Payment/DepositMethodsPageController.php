<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payment;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Exceptions\FinancialException;
use App\Http\Controllers\Api\V1\DepositController as CanonicalDepositController;
use App\Http\Responses\ApiResponse;
use App\Models\Wallet;
use App\Services\Finance\Money;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\PaymentInitiationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Compatibility adapter for retired deposit-method pages.
 *
 * The former implementation exposed hardcoded bank accounts, exchange rates,
 * QR payloads, OCR success, random references and completed statuses. Those
 * values were not evidence of a provider or ledger transition. The canonical
 * authenticated deposit page and V1 deposit controller are now the only
 * deposit contract; unsupported legacy provider-specific operations fail
 * closed.
 */
final class DepositMethodsPageController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        return $request->user() !== null
            ? redirect()->route('player.deposit')
            : redirect()->route('login');
    }

    public function getDepositMethodsApi(Request $request): JsonResponse
    {
        if ($request->user() === null) {
            return $this->authenticationRequired();
        }

        return app(CanonicalDepositController::class)->methods();
    }

    public function generatePromptPayApi(Request $request): JsonResponse
    {
        if ($request->user() === null) {
            return $this->authenticationRequired();
        }
        if (! app(PaymentGatewayManager::class)->isDepositCapable(PaymentMethod::PromptPay)) {
            return $this->notConfigured('PromptPay is disabled or its merchant identity is not configured.');
        }

        $idempotencyKey = $request->input('idempotency_key')
            ?? $request->header('X-Idempotency-Key')
            ?? $request->header('Idempotency-Key');
        if (is_string($idempotencyKey)) {
            $request->merge(['idempotency_key' => $idempotencyKey]);
        }
        $validated = $request->validate([
            'amount' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]*)\.[0-9]{2}$/'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'user_id' => ['prohibited'],
            'wallet_id' => ['prohibited'],
            'recipient_id' => ['prohibited'],
            'merchant_id' => ['prohibited'],
            'currency' => ['prohibited'],
        ]);

        $wallet = Wallet::query()->where('user_id', $request->user()->id)->where('currency', Currency::THB->value)->first();
        if (! $wallet instanceof Wallet) {
            return ApiResponse::error('wallet_not_found', 'No THB wallet is available for this account.', 404);
        }

        try {
            $result = app(PaymentInitiationService::class)->initiateDeposit(
                wallet: $wallet,
                amount: Money::of($validated['amount'], Currency::THB),
                method: PaymentMethod::PromptPay,
                idempotencyKey: $validated['idempotency_key'],
                options: ['ip' => $request->ip()],
            );
        } catch (FinancialException $exception) {
            $code = $exception->errorCode() ?? 'deposit_request_refused';
            if (in_array($code, ['payment_gateway_disabled', 'payment_gateway_unavailable'], true)) {
                return $this->notConfigured('PromptPay is disabled or its merchant identity is not configured.');
            }

            return ApiResponse::error($code, 'PromptPay checkout could not be initiated.', 422);
        }

        $deposit = $result['deposit'];
        $gateway = $result['gateway_response'];
        $qrPayload = $gateway->metadata['qr_payload'] ?? null;
        $expiresAt = $gateway->metadata['expires_at'] ?? null;
        if (! is_string($qrPayload) || $qrPayload === '' || ! is_string($expiresAt) || $expiresAt === '') {
            return $this->notConfigured('PromptPay checkout evidence is unavailable.');
        }

        return ApiResponse::success([
            'deposit_id' => $deposit->id,
            'reference' => $deposit->reference_number,
            'reference_number' => $deposit->reference_number,
            'amount' => (string) $deposit->amount,
            'currency' => Currency::THB->value,
            'qr_payload' => $qrPayload,
            'expires_at' => $expiresAt,
        ], 'PromptPay checkout initiated.', 201);
    }

    public function createBankTransferIntentApi(Request $request): JsonResponse
    {
        return $this->notConfigured('Bank-transfer instructions must be returned by the configured payment gateway checkout.');
    }

    public function generateCryptoDepositApi(Request $request): JsonResponse
    {
        return $this->notConfigured('Crypto deposit addresses are not configured on this compatibility route.');
    }

    public function verifySlipApi(Request $request): JsonResponse
    {
        return $this->notConfigured('Slip verification is not configured. No deposit is credited by this route.');
    }

    public function pollDepositStatusApi(Request $request, string $refId): JsonResponse
    {
        if ($request->user() === null) {
            return $this->authenticationRequired();
        }

        return app(CanonicalDepositController::class)->show($refId, $request);
    }

    private function notConfigured(string $message): JsonResponse
    {
        return response()->json([
            'status' => 'NOT_CONFIGURED',
            'message' => $message,
        ], 503);
    }

    private function authenticationRequired(): JsonResponse
    {
        return response()->json([
            'status' => 'AUTHENTICATION_REQUIRED',
            'message' => 'Authentication is required for deposit information.',
        ], 401);
    }
}
