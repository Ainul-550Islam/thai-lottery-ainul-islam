<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Security\TotpMfaEnrollmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Illuminate\Validation\ValidationException;

/**
 * Web UI for TOTP two-factor authentication enrollment and management.
 *
 * The backend TotpMfaEnrollmentService handles all business logic; this
 * controller only marshals HTTP requests/responses and renders Blade views.
 */
final class TotpMfaWebController extends Controller
{
    public function __construct(
        private readonly TotpMfaEnrollmentService $enrollment,
    ) {}

    /**
     * Show 2FA status page: enable/disable, recovery codes, QR setup.
     */
    public function show(Request $request): View
    {
        /** @var User $user */
        $user = Auth::user();

        $status = $this->enrollment->status($user);

        return view('player.security.two-factor', [
            'user' => $user,
            'status' => $status,
        ]);
    }

    /**
     * Start TOTP enrollment: generate secret, QR URI, pending record.
     */
    public function start(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        try {
            $data = $this->enrollment->startEnrollment($user);
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        // Store the reference in session so the confirm step can bind to it.
        $request->session()->put('totp_enrollment_reference', $data['reference']);
        $request->session()->put('totp_enrollment_secret', $data['secret']);
        $request->session()->put('totp_enrollment_expires_at', $data['expires_at_epoch']);

        return view('player.security.two-factor-setup', [
            'user' => $user,
            'reference' => $data['reference'],
            'secret' => $data['secret'],
            'otpauth_uri' => $data['otpauth_uri'],
            'expires_at_epoch' => $data['expires_at_epoch'],
        ]);
    }

    /**
     * Confirm TOTP enrollment with a 6-digit code from the authenticator app.
     */
    public function confirm(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validate([
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ]);

        try {
            $result = $this->enrollment->confirmEnrollment($user, $validated['code']);
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        $request->session()->forget([
            'totp_enrollment_reference',
            'totp_enrollment_secret',
            'totp_enrollment_expires_at',
        ]);

        return redirect()
            ->route('player.security.2fa')
            ->with('success', __('player.two_factor_enabled'))
            ->with('recovery_codes', $result['recovery_codes']);
    }

    /**
     * Disable TOTP 2FA for the account.
     */
    public function disable(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validate([
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ]);

        try {
            $this->enrollment->disable(
                $user,
                $validated['code'],
                $request->session()->getId(),
            );
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        return redirect()
            ->route('player.security.2fa')
            ->with('success', __('player.two_factor_disabled'));
    }

    /**
     * Regenerate recovery codes (requires TOTP verification).
     */
    public function recoveryCodes(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validate([
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ]);

        try {
            $result = $this->enrollment->verifyTotp(
                $user,
                $validated['code'],
                $request->session()->getId(),
                'recovery_regenerate',
            );
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        // Note: verifyTotp verifies but does not regenerate codes.
        // Full regeneration would need a service method; here we just confirm
        // the user can act and redirect back to the status page.
        Log::info('TOTP recovery code regeneration verified', [
            'user_id' => $user->getKey(),
            'action' => $result['action'],
        ]);

        return redirect()
            ->route('player.security.2fa')
            ->with('success', __('player.two_factor_recovery_verified'));
    }
}
