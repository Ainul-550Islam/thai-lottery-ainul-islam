<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyTotpRequest;
use App\Services\Auth\TotpMfaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MfaController extends Controller
{
    public function __construct(
        private readonly TotpMfaService $mfaService
    ) {}

    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $secret = $this->mfaService->generateSecret();
        $accountName = (string) ($user->email ?? $user->id);
        $otpAuthUri = $this->mfaService->getOtpAuthUri($secret, $accountName);
        $recoveryCodes = $this->mfaService->generateRecoveryCodes();

        // Store unconfirmed secret in session or user cache
        $request->session()->put('mfa_setup_secret', $secret);
        $request->session()->put('mfa_setup_recovery_codes', $recoveryCodes);

        return response()->json([
            'success' => true,
            'data' => [
                'secret' => $secret,
                'otpauth_uri' => $otpAuthUri,
                'recovery_codes' => $recoveryCodes,
            ],
        ], Response::HTTP_OK);
    }

    public function confirm(VerifyTotpRequest $request): JsonResponse
    {
        $user = $request->user();
        $secret = (string) $request->session()->get('mfa_setup_secret', $user?->mfa_secret ?? '');
        $code = (string) $request->input('code');

        if (empty($secret) || !$this->mfaService->verifyCode($secret, $code)) {
            return response()->json([
                'success' => false,
                'error_code' => 'INVALID_MFA_CODE',
                'message' => 'The provided TOTP verification code is invalid or expired.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Activate MFA for user
        $request->session()->put('mfa_verified', true);
        $request->session()->forget(['mfa_setup_secret', 'mfa_setup_recovery_codes']);

        return response()->json([
            'success' => true,
            'message' => 'Multi-Factor Authentication enabled and verified successfully.',
        ], Response::HTTP_OK);
    }

    public function verify(VerifyTotpRequest $request): JsonResponse
    {
        $user = $request->user();
        $secret = (string) ($user?->mfa_secret ?? '');
        $code = (string) $request->input('code');

        if (empty($secret) || !$this->mfaService->verifyCode($secret, $code)) {
            return response()->json([
                'success' => false,
                'error_code' => 'INVALID_MFA_CODE',
                'message' => 'Invalid TOTP code.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $request->session()->put('mfa_verified', true);

        return response()->json([
            'success' => true,
            'message' => 'MFA verification confirmed for current session.',
        ], Response::HTTP_OK);
    }
}
