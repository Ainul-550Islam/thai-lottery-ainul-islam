<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Auth\VerifyTotpRequest;
use App\Models\User;
use App\Services\Security\TotpMfaEnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Authenticated HTTP controller for TOTP Multi-Factor Authentication (Audit §E5).
 *
 * Exposes enrollment, code confirmation, step-protected TOTP verification,
 * single-use recovery code consumption, and MFA disablement behind dedicated
 * brute-force rate limiters.
 */
final class TotpMfaController
{
    public function __construct(
        private readonly TotpMfaEnrollmentService $mfa,
    ) {}

    public function status(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => $this->mfa->status($user),
        ]);
    }

    public function enroll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => $this->mfa->startEnrollment($user),
        ], 201);
    }

    public function confirm(VerifyTotpRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $result = $this->mfa->confirmEnrollment($user, (string) $request->validated('code'));

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    public function verify(VerifyTotpRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $sessionId = $request->hasSession() ? $request->session()->getId() : ('token:'.($user->currentAccessToken()?->getKey() ?? 'stateless'));
        $action = (string) $request->input('action', 'login_mfa');

        $result = $this->mfa->verifyTotp($user, (string) $request->validated('code'), $sessionId, $action);

        if ($request->hasSession()) {
            $request->session()->put('auth.mfa_verified_at', $result['expires_at_epoch']);
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    public function verifyRecoveryCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'recovery_code' => ['required', 'string', 'regex:/\A[A-Fa-f0-9]{8}-[A-Fa-f0-9]{8}\z/'],
            'action' => ['nullable', 'string', 'max:64'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $sessionId = $request->hasSession() ? $request->session()->getId() : ('token:'.($user->currentAccessToken()?->getKey() ?? 'stateless'));

        $result = $this->mfa->verifyRecoveryCode(
            $user,
            (string) $validated['recovery_code'],
            $sessionId,
            (string) ($validated['action'] ?? 'login_mfa'),
        );

        if ($request->hasSession()) {
            $request->session()->put('auth.mfa_verified_at', $result['expires_at_epoch']);
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    public function destroy(VerifyTotpRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $sessionId = $request->hasSession() ? $request->session()->getId() : ('token:'.($user->currentAccessToken()?->getKey() ?? 'stateless'));

        $this->mfa->disable($user, (string) $request->validated('code'), $sessionId);

        if ($request->hasSession()) {
            $request->session()->forget('auth.mfa_verified_at');
        }

        return response()->json([
            'success' => true,
            'data' => ['enabled' => false],
        ]);
    }
}
