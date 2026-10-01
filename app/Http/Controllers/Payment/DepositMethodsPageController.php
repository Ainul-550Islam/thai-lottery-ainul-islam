<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Api\V1\DepositController as CanonicalDepositController;
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
        return $this->notConfigured('PromptPay provider-specific generation is not registered on this compatibility route.');
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
