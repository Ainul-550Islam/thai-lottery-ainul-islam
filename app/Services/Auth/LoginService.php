<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\AuthLoginIdentifier;
use App\Enums\UserStatus;
use App\Http\Support\AuthAuditRecorder;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/*
 * PROMPT 3 — server-authoritative member login.
 *
 * ONE authoritative web login path, composed from the pieces the
 * platform already had (Auth::attempt, the throttle:login limiter,
 * the AuthAuditRecorder telemetry) plus the PROMPT 3 additions
 * (CAPTCHA gate, account-id dimension, generic-failure policy).
 *
 * ANTI-ENUMERATION: every pre-credential failure (unknown identifier,
 * wrong password, unresolvable shape) surfaces the SAME generic
 * message from config — never "email not found", never "account does
 * not exist". The status-specific message appears only AFTER valid
 * credentials proved who is asking (the authenticated user already
 * knows their own status).
 *
 * SESSION SECURITY: successful login regenerates the session id and
 * only approved keys survive (the framework keeps flash + intended
 * url); the anonymous pre-auth state is discarded. Logout semantics
 * live in the controller using the same conventions as before.
 *
 * NO credentials, tokens, CAPTCHA answers or session ids are ever
 * logged — the audit recorder stores a hashed identifier and a
 * reason code only.
 */
final class LoginService
{
    public function __construct(
        private readonly CaptchaService $captcha,
        private readonly AuthAuditRecorder $audit,
    ) {}

    /**
     * Attempt a member login.
     *
     * @param  array{login?: mixed, password?: mixed, remember?: mixed, captcha_token?: mixed, captcha_answer?: mixed}  $input
     *
     * @throws ValidationException
     */
    public function attempt(Request $request, array $input): User
    {
        $rawIdentifier = is_string($input['login'] ?? null) ? $input['login'] : '';
        $password = is_string($input['password'] ?? null) ? $input['password'] : '';
        $remember = (bool) ($input['remember'] ?? false);

        // 1) CAPTCHA gate — always server-side, never a client boolean.
        if ($this->captchaGateRequired('login') && $this->captcha->isEnabled()) {
            $token = is_string($input['captcha_token'] ?? null) ? $input['captcha_token'] : null;
            $answer = is_string($input['captcha_answer'] ?? null) ? $input['captcha_answer'] : null;

            if (! $this->captcha->verify($request, $token, $answer)) {
                throw ValidationException::withMessages([
                    'captcha' => (string) config('auth_security.messages.captcha_failed'),
                ]);
            }
        }

        $kind = AuthLoginIdentifier::resolve($rawIdentifier);
        $identifier = $kind->normalize($rawIdentifier);

        if ($identifier === '' || $password === '') {
            $this->audit->recordFailure($request, $identifier, 'malformed_input');

            throw ValidationException::withMessages([
                'login' => $this->genericFailure(),
            ]);
        }

        // 2) Credential attempt over the resolved dimensions.
        $user = $this->authenticateOver($kind, $identifier, $password, $remember);

        if ($user === null) {
            $this->audit->recordFailure($request, $identifier, 'invalid_credentials');

            throw ValidationException::withMessages([
                'login' => $this->genericFailure(),
            ]);
        }

        // 3) Status enforcement — the EXISTING semantics, unchanged.
        if ($user->status !== UserStatus::Active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $this->audit->recordFailure($request, $identifier, 'account_'.$user->status->value, $user->id);

            throw ValidationException::withMessages([
                'login' => 'Your account is currently '.$user->status->value
                    .'. Please contact customer support.',
            ]);
        }

        // 4) Session hardening + login telemetry (existing behaviour).
        $request->session()->regenerate();

        $user->last_login_at = now();
        $user->last_login_ip = $request->ip();
        $user->save();

        $this->audit->recordLogin($request, $user, 'web');

        // 5) A successful login resets the identifier's failure window
        //    (project policy: config-driven).
        if ((bool) config('auth_security.throttling.clear_on_success', true)) {
            RateLimiter::clear('login', 'login:id:'.sha1(mb_strtolower($identifier)));
        }

        return $user;
    }

    /**
     * Try each candidate credential column for the resolved kind. All
     * failures collapse into one null — the caller answers generically.
     */
    private function authenticateOver(AuthLoginIdentifier $kind, string $identifier, string $password, bool $remember): ?User
    {
        foreach ($kind->candidateFields() as $field) {
            $credentials = [$field => $identifier, 'password' => $password];

            if (Auth::attempt($credentials, $remember)) {
                /** @var User $user */
                $user = Auth::user();

                return $user;
            }

            // The session must not linger half-authenticated between
            // candidate attempts.
            Auth::logout();
        }

        return null;
    }

    /**
     * The one outward pre-credential failure message.
     */
    private function genericFailure(): string
    {
        return (string) config('auth_security.messages.generic_failure', 'These credentials are invalid.');
    }

    /**
     * Whether a surface carries the CAPTCHA gate (config per surface).
     */
    private function captchaGateRequired(string $surface): bool
    {
        return (bool) config('auth_security.captcha.'.$surface, true);
    }
}
