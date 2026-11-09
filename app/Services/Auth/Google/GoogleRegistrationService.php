<?php

declare(strict_types=1);

namespace App\Services\Auth\Google;

use App\Enums\Currency;
use App\Enums\UserStatus;
use App\Enums\WalletStatus;
use App\Models\SocialIdentity;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Agent\AgentReferralService;
use App\Services\Legal\AgeVerificationService;
use App\Services\Legal\ConsentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Google sign-up that keeps every rule of RegistrationService:
 * one transaction for user + wallet + referral + age attestation + consent records.
 *
 * Name, email, avatar and Google id come from the verified id_token (session), NEVER from the
 * request body. Only referral, date of birth and the three declarations come from the member.
 *
 * Money: the wallet is created at 0.00 THB, exactly like RegistrationService ("no bonus money").
 * Nothing here credits a balance.
 */
final class GoogleRegistrationService
{
    public function __construct(
        private readonly AgentReferralService $referrals,
        private readonly ConsentService $consents,
        private readonly AgeVerificationService $ageVerification,
    ) {}

    /**
     * @param  array<string, mixed>  $pending    GoogleSignInService::pending()
     * @param  array<string, mixed>  $validated  GoogleSignupConfirmRequest output
     *
     * @throws ValidationException
     */
    public function register(Request $request, array $pending, array $validated): User
    {
        $profile = GoogleProfile::fromSession($pending);
        $referralCode = strtoupper(trim((string) ($validated['referral_id'] ?? '')));

        return DB::transaction(function () use ($request, $profile, $validated, $referralCode): User {
            // UX pre-checks; the UNIQUE indexes remain the race authority.
            if (User::withTrashed()->where('email', $profile->email)->exists()
                || SocialIdentity::query()->where('provider', 'google')->where('provider_subject', $profile->subject)->exists()) {
                throw ValidationException::withMessages(['email' => __('public_pages.google_email_conflict')]);
            }

            $user = new User;
            $user->fill([
                'name' => $profile->name,
                'email' => $profile->email,
                'username' => $this->deriveUsername($profile->email),
                // Unusable random password: the column is NOT NULL and the session-binding
                // middleware hashes it, but nobody can ever type it. A member can still set a
                // real one through the normal e-mail password-reset flow.
                'password' => Hash::make(Str::random(64)),
                'avatar_url' => $profile->picture,
                'date_of_birth' => $validated['date_of_birth'],
                'preferences' => [
                    'terms_accepted_at' => now()->toIso8601String(),
                    'terms_version' => (string) config('auth_security.rule_version', '1'),
                    'registration_ip' => $request->ip(),
                    'registration_method' => 'google',
                ],
            ]);
            $user->status = UserStatus::Active;
            $user->email_verified_at = now(); // Google asserted email_verified=true (enforced in GoogleOidcClient)
            $user->save();

            $wallet = new Wallet;
            $wallet->user_id = $user->id;
            $wallet->currency = Currency::THB;
            $wallet->status = WalletStatus::Active;
            $wallet->save();

            try {
                $this->referrals->attributeUser($user, $referralCode);
            } catch (\Throwable $exception) {
                throw ValidationException::withMessages(['referral_id' => $exception->getMessage()]);
            }

            $identity = new SocialIdentity;
            $identity->user_id = $user->id;
            $identity->provider = 'google';
            $identity->provider_subject = $profile->subject;
            $identity->email_at_link = $profile->email;
            $identity->email_verified = true;
            $identity->linked_via = 'signup';
            $identity->last_login_at = now();
            $identity->save();

            // Both throw ValidationException (=> whole transaction rolls back) when the
            // declaration is missing, the DOB is under the minimum age, or the legal
            // document version/hash is stale.
            $this->ageVerification->attestFromRegistration($user, $request);
            $this->consents->recordRegistration($user, $request);

            return $user;
        });
    }

    /** Same derivation as RegistrationService::deriveUsername (kept private there). */
    private function deriveUsername(string $email): string
    {
        $base = preg_replace('/[^A-Za-z0-9_]/', '', explode('@', $email)[0]) ?? 'member';

        if ($base === '' || ! preg_match('/^[A-Za-z]/', $base)) {
            $base = 'member'.$base;
        }

        $base = mb_substr($base, 0, 24);
        $username = $base;
        $suffix = 0;

        while (User::withTrashed()->where('username', $username)->exists()) {
            $username = mb_substr($base, 0, 24).(++$suffix);
        }

        return $username;
    }
}
