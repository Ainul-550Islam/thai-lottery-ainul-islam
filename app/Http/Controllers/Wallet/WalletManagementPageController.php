<?php

declare(strict_types=1);

namespace App\Http\Controllers\Wallet;

use App\Http\Controllers\Api\V1\WalletController as CanonicalWalletController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Compatibility adapter for the retired wallet management surface.
 *
 * The previous page embedded balances, transactions, fees and random
 * references in the controller. This adapter never creates or invents a
 * financial value. Wallet reads are delegated to the canonical owner-scoped
 * API controller; unsupported legacy mutations fail closed.
 */
final class WalletManagementPageController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        return $request->user() !== null
            ? redirect()->route('player.wallet')
            : redirect()->route('login');
    }

    public function getSummaryApi(Request $request): JsonResponse
    {
        if ($request->user() === null) {
            return $this->authenticationRequired();
        }

        return app(CanonicalWalletController::class)->show($request);
    }

    public function getTransactionsApi(Request $request): JsonResponse
    {
        if ($request->user() === null) {
            return $this->authenticationRequired();
        }

        return app(CanonicalWalletController::class)->transactions($request);
    }

    public function createDepositApi(Request $request): JsonResponse
    {
        return $this->notConfigured('Use the canonical authenticated deposit endpoint. No wallet balance is changed by this compatibility route.');
    }

    public function createWithdrawApi(Request $request): JsonResponse
    {
        return $this->notConfigured('Use the canonical authenticated withdrawal endpoint. No wallet balance is changed by this compatibility route.');
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
            'message' => 'Authentication is required for wallet information.',
        ], 401);
    }
}
