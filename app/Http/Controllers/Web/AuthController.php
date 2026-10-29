<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Web session authentication controller for players.
 */
final class AuthController
{
    /**
     * Show player login view.
     */
    public function showLoginForm(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('player.dashboard');
        }

        return view('auth.login');
    }

    /**
     * Show player registration view.
     */
    public function showRegisterForm(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('player.dashboard');
        }

        return view('auth.register');
    }

    /**
     * This obsolete, unrouted entry point cannot create an account. Use the
     * canonical MemberAuthController/RegistrationService route instead.
     */
    public function register(Request $request): RedirectResponse
    {
        abort(404);
    }

    /**
     * Handle player login attempt.
     */
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $loginField = filter_var($credentials['login'], FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        $attemptCredentials = [
            $loginField => $credentials['login'],
            'password' => $credentials['password'],
        ];

        $remember = (bool) ($credentials['remember'] ?? false);

        if (! Auth::attempt($attemptCredentials, $remember)) {
            throw ValidationException::withMessages([
                'login' => __('auth.failed'),
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        if ($user->status !== UserStatus::Active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'login' => 'Your account is currently '.$user->status->value.'. Please contact customer support.',
            ]);
        }

        $user->last_login_at = now();
        $user->last_login_ip = $request->ip();
        $user->save();

        $request->session()->regenerate();

        return redirect()->intended(route('player.dashboard'));
    }

    /**
     * Handle player logout.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'You have been logged out.');
    }
}
