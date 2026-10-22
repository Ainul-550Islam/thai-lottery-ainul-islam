<?php

declare(strict_types=1);

namespace App\Http\Controllers\Player;

use App\Enums\TransferDirection;
use App\Exceptions\BalanceTransferException;
use App\Http\Controllers\Api\V1\ProfileController as CanonicalProfileController;
use App\Services\Transfer\BalanceTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Compatibility adapter for the retired profile portal.
 *
 * Profile reads and updates delegate to the canonical owner-scoped profile
 * controller. Balance transfers delegate to BalanceTransferService, the same
 * implementation the player wallet UI uses. Banking, PIN and session mutations
 * are still not claimed here, because this legacy surface has no verified
 * contract for them.
 */
final class PlayerProfilePortalController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        return $request->user() !== null
            ? redirect()->route('player.profile')
            : redirect()->route('login');
    }

    public function getProfileApi(Request $request): JsonResponse
    {
        return $this->profileResponse($request);
    }

    public function getProfileDetailsApi(Request $request): JsonResponse
    {
        return $this->profileResponse($request);
    }

    public function updateProfileApi(Request $request): JsonResponse
    {
        if ($request->user() === null) {
            return $this->authenticationRequired();
        }

        if ($request->filled('fullName') && ! $request->filled('name')) {
            $request->merge(['name' => $request->input('fullName')]);
        }

        return app(CanonicalProfileController::class)->update($request);
    }

    public function changePasswordApi(Request $request): JsonResponse
    {
        if ($request->user() === null) {
            return $this->authenticationRequired();
        }

        if ($request->filled('new_password') && ! $request->filled('password')) {
            $request->merge([
                'password' => $request->input('new_password'),
                'password_confirmation' => $request->input('new_password_confirmation', $request->input('new_password')),
            ]);
        }

        return app(CanonicalProfileController::class)->updatePassword($request);
    }

    public function setSecurityPinApi(Request $request): JsonResponse
    {
        return $this->notConfigured('A transaction PIN service is not configured on this profile compatibility route.');
    }

    public function setPinApi(Request $request): JsonResponse
    {
        return $this->setSecurityPinApi($request);
    }

    public function bindBankAccountApi(Request $request): JsonResponse
    {
        return $this->notConfigured('Bank-destination binding requires the configured KYC and payout contract.');
    }

    public function bindBankApi(Request $request): JsonResponse
    {
        return $this->bindBankAccountApi($request);
    }

    /**
     * Legacy cross-balance transfer.
     *
     * This endpoint used to answer 503 NOT_CONFIGURED for every call. The lane
     * now exists, so it delegates to the same BalanceTransferService the player
     * wallet UI uses — one implementation, two entry points. It deliberately does
     * NOT reimplement validation, pricing or locking here.
     *
     * Accepted payload (all names this retired surface was known to use):
     *   direction | transferDirection | type : cash_to_win | win_to_cash
     *   amount    | transferAmount           : plain decimal string
     *   idempotency_key | requestId, or the Idempotency-Key header
     *
     * The response keeps THIS surface's envelope (`status` + `message`) rather
     * than the canonical ApiResponse shape, because the whole reason this class
     * exists is that an old client is still calling it and expects that shape.
     */
    public function transferBalanceApi(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return $this->authenticationRequired();
        }

        $direction = TransferDirection::tryFromInput(
            $request->input('direction')
                ?? $request->input('transferDirection')
                ?? $request->input('type'),
        );

        if ($direction === null) {
            return response()->json([
                'status' => 'VALIDATION_FAILED',
                'message' => (string) trans('transfer.validation.direction_invalid', [
                    'allowed' => implode(', ', TransferDirection::values()),
                ]),
                'errors' => ['direction' => [(string) trans('transfer.validation.direction_required')]],
            ], 422);
        }

        $amount = $request->input('amount') ?? $request->input('transferAmount');

        if (! is_string($amount) && ! is_int($amount) && ! is_float($amount)) {
            return response()->json([
                'status' => 'VALIDATION_FAILED',
                'message' => (string) trans('transfer.validation.amount_required'),
                'errors' => ['amount' => [(string) trans('transfer.validation.amount_required')]],
            ], 422);
        }

        $key = $request->input('idempotency_key')
            ?? $request->input('requestId')
            ?? $request->header((string) config('transfer.idempotency.header', 'Idempotency-Key'));

        try {
            $outcome = app(BalanceTransferService::class)->transfer(
                user: $user,
                direction: $direction,
                amount: is_string($amount) ? trim($amount) : (string) $amount,
                idempotencyKey: is_string($key) && trim($key) !== '' ? trim($key) : null,
                requestContext: [
                    'ip' => $request->ip(),
                    'user_agent' => (string) $request->userAgent(),
                ],
            );
        } catch (BalanceTransferException $e) {
            $headers = [];

            if ($e->retryAfter() !== null) {
                $headers['Retry-After'] = (string) $e->retryAfter();
            }

            return response()->json([
                'status' => 'REFUSED',
                'code' => $e->machineCode(),
                // Same sentence and same filter as the player controller. This legacy
                // surface used to return getMessage() and the FULL diagnostic context,
                // which put an internal wallet id on the wire and answered in English on
                // a Thai site - two different responses to one refusal.
                'message' => $e->translatedMessage(),
                'context' => $e->publicContext(),
            ], $e->httpStatus(), $headers);
        }

        $transfer = $outcome->transfer;

        return response()->json([
            'status' => 'SUCCESS',
            'message' => $outcome->isReplay()
                ? (string) trans('transfer.messages.already_applied')
                : (string) trans('transfer.messages.completed'),
            'replayed' => $outcome->isReplay(),
            'data' => $transfer->toPlayerArray(),
            'balances' => [
                'source_after' => $transfer->source_balance_after,
                'destination_after' => $transfer->destination_balance_after,
            ],
        ], $outcome->httpStatus());
    }

    public function balanceTransferApi(Request $request): JsonResponse
    {
        return $this->transferBalanceApi($request);
    }

    public function terminateSessionApi(Request $request): JsonResponse
    {
        return $this->notConfigured('Use the authenticated security-session contract to revoke a session.');
    }

    private function profileResponse(Request $request): JsonResponse
    {
        if ($request->user() === null) {
            return $this->authenticationRequired();
        }

        return app(CanonicalProfileController::class)->show($request);
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
            'message' => (string) trans('player.auth_required_profile'),
        ], 401);
    }
}
