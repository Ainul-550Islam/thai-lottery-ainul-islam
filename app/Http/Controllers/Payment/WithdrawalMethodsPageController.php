<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Api\V1\WithdrawalController as CanonicalWithdrawalController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Compatibility adapter for retired provider-specific withdrawal pages.
 *
 * It intentionally contains no balances, bank identities, limits, fees,
 * references, payout statuses or provider promises. The canonical
 * authenticated withdrawal page and V1 withdrawal controller own all such
 * data and mutations.
 */
final class WithdrawalMethodsPageController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        return $request->user() !== null
            ? redirect()->route('player.withdraw')
            : redirect()->route('login');
    }

    public function getWithdrawalMethodsApi(Request $request): JsonResponse
    {
        return $this->notConfigured('Withdrawal methods are returned only by the configured canonical payout contract.');
    }

    public function requestWithdrawalApi(Request $request): JsonResponse
    {
        return $this->notConfigured('Withdrawal requests must use the canonical validated payout endpoint.');
    }

    public function getRecentWithdrawalsApi(Request $request): JsonResponse
    {
        if ($request->user() === null) {
            return $this->authenticationRequired();
        }

        return app(CanonicalWithdrawalController::class)->index($request);
    }

    public function getWithdrawalStatusApi(Request $request, string $refId): JsonResponse
    {
        if ($request->user() === null) {
            return $this->authenticationRequired();
        }

        return app(CanonicalWithdrawalController::class)->show($refId, $request);
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
            'message' => 'Authentication is required for withdrawal information.',
        ], 401);
    }
}
