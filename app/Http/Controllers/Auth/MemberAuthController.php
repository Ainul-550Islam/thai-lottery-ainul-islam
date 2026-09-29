<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterMemberRequest;
use App\Services\Auth\CaptchaService;
use App\Services\Auth\LoginService;
use App\Services\Auth\PasswordResetService;
use App\Services\Auth\RegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/*
 * PROMPT 3 — the member auth controller.
 *
 * THIN BY CONTRACT: validate (Requests), delegate (Services), answer
 * (view/redirect). No password hashing, no credential logic, no
 * CAPTCHA verification, no state decisions live here — the controller
 * only orchestrates the surfaces:
 *
 *   GET  /login             anonymous  — Account ID/email + password + CAPTCHA
 *   POST /login             anonymous  — throttle:login
 *   GET  /register          anonymous  — benchmark registration fields
 *   POST /register          anonymous  — throttle:login
 *   POST /logout            auth       — session invalidation
 *   GET  /forgot-password   anonymous  — Account No./email + CAPTCHA
 *   POST /forgot-password   anonymous  — throttle:password-reset
 *   GET  /reset-password    anonymous  — new-password form (token-gated)
 *   POST /reset-password    anonymous  — throttle:password-reset
 */
final class MemberAuthController
{
    public function __construct(
        private readonly LoginService $login,
        private readonly RegistrationService $registration,
        private readonly PasswordResetService $passwordReset,
        private readonly CaptchaService $captcha,
    ) {
    }

    /*
    |----------------------------------------------------------------------
    | Login
    |----------------------------------------------------------------------
    */

    public function showLogin(Request $request): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('player.dashboard');
        }

        return view('auth.member-login', [
            'meta' => $this->meta('login'),
            'captcha' => $this->captcha->issue($request),
            'captchaEnabled' => $this->captcha->isEnabled()
                && (bool) config('auth_security.captcha.login', true),
        ]);
    }

    public function login(LoginRequest $request): RedirectResponse
    {
        $this->login->attempt($request, [
            'login' => $request->input('login'),
            'password' => $request->input('password'),
            'remember' => $request->boolean('remember'),
            'captcha_token' => $request->input('captcha_token'),
            'captcha_answer' => $request->input('captcha_answer'),
        ]);

        return redirect()->intended(route('player.dashboard'));
    }

    /*
    |----------------------------------------------------------------------
    | Registration
    |----------------------------------------------------------------------
    */

    public function showRegister(Request $request): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('player.dashboard');
        }

        return view('auth.member-register', [
            'meta' => $this->meta('register'),
            'genders' => ['male', 'female', 'unspecified'],
        ]);
    }

    public function register(RegisterMemberRequest $request): RedirectResponse
    {
        $user = $this->registration->register($request, $request->validated());

        // Session hardening at the moment of authentication.
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()
            ->route('player.dashboard')
            ->with('status', __('public_pages.register_welcome'));
    }

    /*
    |----------------------------------------------------------------------
    | Logout (the single authoritative path)
    |----------------------------------------------------------------------
    */

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', __('public_pages.logged_out'));
    }

    /*
    |----------------------------------------------------------------------
    | Password recovery
    |----------------------------------------------------------------------
    */

    public function showForgotPassword(Request $request): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('player.dashboard');
        }

        return view('auth.forgot-password', [
            'meta' => $this->meta('forgot'),
            'captcha' => $this->captcha->issue($request),
            'captchaEnabled' => $this->captcha->isEnabled()
                && (bool) config('auth_security.captcha.password_reset', true),
            'resetToken' => null,
            'resetEmail' => null,
        ]);
    }

    public function requestReset(ForgotPasswordRequest $request): RedirectResponse
    {
        // The SAME outward answer for every identifier outcome.
        $result = $this->passwordReset->requestReset($request, [
            'identifier' => $request->input('identifier'),
            'captcha_token' => $request->input('captcha_token'),
            'captcha_answer' => $request->input('captcha_answer'),
        ]);

        return redirect()
            ->route('password.request')
            ->with('status', $result['message']);
    }

    public function showResetForm(Request $request, string $token): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('player.dashboard');
        }

        return view('auth.forgot-password', [
            'meta' => $this->meta('reset'),
            'captcha' => $this->captcha->issue($request),
            'captchaEnabled' => false,
            'resetToken' => $token,
            'resetEmail' => (string) $request->query('email', ''),
        ]);
    }

    public function resetPassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:filter', 'max:255'],
            'password' => ['required', 'string', 'max:255', new \App\Rules\StrongPasswordRule()],
            'password_confirmation' => ['required', 'string', 'same:password'],
        ], [], [
            'password' => __('public_pages.login_password'),
        ]);

        $result = $this->passwordReset->resetPassword($request, $validated);

        return redirect()
            ->route('login')
            ->with('status', $result['message']);
    }

    /*
    |----------------------------------------------------------------------
    | Page metadata (localized)
    |----------------------------------------------------------------------
    */

    /**
     * @return array<string, string>
     */
    private function meta(string $page): array
    {
        // Suffix-form keys (login_meta_title, register_meta_title, ...),
        // matching the lang files exactly.
        $title = (string) trans('public_pages.'.$page.'_meta_title');
        $description = (string) trans('public_pages.'.$page.'_meta_description');

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => rtrim((string) config('app.url'), '/'),
            'lang' => str_replace('_', '-', (string) app()->getLocale()),
            'og_title' => $title,
            'og_description' => $description,
            'og_type' => 'website',
            'og_url' => rtrim((string) config('app.url'), '/'),
        ];
    }
}
