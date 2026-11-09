<?php

declare(strict_types=1);

namespace App\Services\Auth\Google;

use App\Enums\UserStatus;
use App\Http\Support\AuthAuditRecorder;
use App\Models\SocialIdentity;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Decides what a VERIFIED Google profile means for this platform.
 *
 *  1. A SocialIdentity for this Google `sub` exists  -> log that member in.
 *  2. Otherwise, a local account already owns that email -> NEVER auto-link (see below).
 *  3. Otherwise -> new member; parked in the session until age + consent + referral are given.
 *
 * Why 2 refuses: registration does not verify email ownership (email_verified_at stays null),
 * so anyone can pre-register victim@gmail.com with their own password. If the victim later
 * signed in with Google and we linked by email, the victim would land inside an account the
 * attacker already controls (pre-hijacking). It is also exactly the flaw that got the previous
 * social login switched off. The user is told to sign in with their password instead.
 */
final class GoogleSignInService
{
    public const LOGGED_IN = 'logged_in';
    public const NEEDS_SIGNUP = 'needs_signup';
    public const EMAIL_CONFLICT = 'email_conflict';

    public const PENDING_KEY = 'google_signup';
    private const PENDING_TTL = 900;

    public function __construct(private readonly AuthAuditRecorder $audit) {}

    /** @throws ValidationException when a linked account is not allowed to sign in */
    public function resolve(Request $request, GoogleProfile $profile): string
    {
        $identity = SocialIdentity::query()
            ->where('provider', 'google')
            ->where('provider_subject', $profile->subject)
            ->first();

        if ($identity !== null) {
            $user = $identity->user; // soft-deleted users resolve to null

            if (! $user instanceof User || $user->status !== UserStatus::Active) {
                $this->audit->recordFailure($request, 'google:'.$profile->subject, 'google_account_unavailable', $user?->id);

                throw ValidationException::withMessages([
                    'login' => $user instanceof User
                        ? 'Your account is currently '.$user->status->value.'. Please contact customer support.'
                        : (string) config('auth_security.messages.generic_failure', 'These credentials are invalid.'),
                ]);
            }

            $this->login($request, $user, $identity, $profile);

            return self::LOGGED_IN;
        }

        // withTrashed(): users.email is UNIQUE across soft-deleted rows too.
        if (User::withTrashed()->where('email', $profile->email)->exists()) {
            $this->audit->recordFailure($request, 'google:'.$profile->subject, 'google_email_conflict');

            return self::EMAIL_CONFLICT;
        }

        $request->session()->put(self::PENDING_KEY, $profile->toSession() + ['expires_at' => time() + self::PENDING_TTL]);

        return self::NEEDS_SIGNUP;
    }

    /** @return array<string, mixed>|null */
    public function pending(Request $request): ?array
    {
        $pending = $request->session()->get(self::PENDING_KEY);

        if (! is_array($pending) || (int) ($pending['expires_at'] ?? 0) < time()) {
            $request->session()->forget(self::PENDING_KEY);

            return null;
        }

        return $pending;
    }

    public function login(Request $request, User $user, ?SocialIdentity $identity = null, ?GoogleProfile $profile = null): void
    {
        Auth::login($user);                 // no "remember me": a Google sign-in is a normal session
        $request->session()->regenerate();   // session-fixation defence, same as password login

        $user->last_login_at = now();
        $user->last_login_ip = $request->ip();

        // Keep the avatar fresh, but never overwrite a photo the member uploaded themselves.
        if ($profile?->picture !== null && ($user->avatar_url === null || str_contains((string) $user->avatar_url, 'googleusercontent.com'))) {
            $user->avatar_url = $profile->picture;
        }

        $user->save();

        if ($identity !== null) {
            $identity->last_login_at = now();
            $identity->save();
        }

        $this->audit->recordLogin($request, $user, 'google');
    }
}
