<?php

declare(strict_types=1);

namespace App\Http\Controllers\Player;

use App\Http\Controllers\Api\V1\ProfileController as CanonicalProfileController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Compatibility adapter for the retired profile portal.
 *
 * Profile reads and updates delegate to the canonical owner-scoped profile
 * controller. Banking, PIN, balance-transfer and session mutations are not
 * claimed here because this legacy surface has no verified contract for them.
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

    public function transferBalanceApi(Request $request): JsonResponse
    {
        return $this->notConfigured('Cross-balance transfers are not configured. No wallet state was changed.');
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
