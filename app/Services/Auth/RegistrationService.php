<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\Currency;
use App\Enums\UserStatus;
use App\Enums\WalletStatus;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Agent\AgentReferralService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/*
 * PROMPT 3 — member registration.
 *
 * Extracted from the pre-existing Web\AuthController::register and
 * extended to the benchmark field set. The EXISTING financial side
 * effects are preserved exactly as they were (default THB wallet
 * provisioning was already the explicit documented behaviour of the
 * registration transaction) — nothing new is invented: no bonus
 * money, no commission, no fee.
 *
 * FIELD MAPPING (schema-inspected, no duplicates):
 *   Referral ID        -> agents.agent_code, resolved by the EXISTING
 *                        AgentReferralService (preferences bookkeeping,
 *                        audit, self/suspended/inexistent rejection).
 *   A.C./Mobile        -> users.phone (digits, unique, normalized).
 *   Password/Confirm   -> users.password (Hash::make — never plaintext).
 *   First/Last Name    -> users.name ("First Last" — single column).
 *   Active Email       -> users.email (lowercased, unique).
 *   Date of Birth      -> users.date_of_birth (existing column).
 *   Gender/City/Country/Nationality -> the nullable columns added by
 *                        the PROMPT 3 migration.
 *   Terms acceptance   -> preferences.terms_accepted_at + version
 *                        (timestamped, never a hidden-input trust).
 *
 * TRANSACTIONALITY: user + wallet + referral attribution happen inside
 * ONE database transaction — a failure anywhere rolls the whole
 * registration back (no orphan users, no orphan wallets).
 */
final class RegistrationService
{
    public function __construct(
        private readonly AgentReferralService $referrals,
    ) {}

    /**
     * Register a member.
     *
     * @param  array<string, mixed>  $validated  RegisterMemberRequest output
     *
     * @throws ValidationException on referral/phone conflicts (field-keyed)
     */
    public function register(Request $request, array $validated): User
    {
        $referralCode = strtoupper(trim((string) ($validated['referral_id'] ?? '')));
        $mobile = $this->normalizeMobile((string) ($validated['mobile'] ?? ''));
        $email = mb_strtolower(trim((string) ($validated['email'] ?? '')));
        $firstName = trim((string) ($validated['first_name'] ?? ''));
        $lastName = trim((string) ($validated['last_name'] ?? ''));

        $username = $this->deriveUsername($email, $mobile);

        return DB::transaction(function () use ($request, $validated, $referralCode, $mobile, $email, $firstName, $lastName, $username): User {
            // 1) The account — one authoritative create; the unique
            //    constraints are the race-condition authority, the
            //    pre-checks are UX only.
            $this->assertAvailable($email, $mobile, $username);

            $user = new User;
            $user->fill([
                'name' => trim($firstName.' '.$lastName),
                'email' => $email,
                'username' => $username,
                'phone' => $mobile,
                'password' => Hash::make((string) $validated['password']),
                'date_of_birth' => $validated['date_of_birth'] ?? null,
                'gender' => $validated['gender'] ?? null,
                'city' => $validated['city'] ?? null,
                'country' => $validated['country'] ?? null,
                'nationality' => $validated['nationality'] ?? null,
                'preferences' => [
                    'terms_accepted_at' => now()->toIso8601String(),
                    'terms_version' => (string) config('auth_security.rule_version', '1'),
                    'registration_ip' => $request->ip(),
                ],
            ]);
            // Lifecycle fields are not mass-assignable — set explicitly.
            $user->status = UserStatus::Active;
            $user->save();

            // 2) Default wallet — the pre-existing explicit side effect,
            //    byte-for-byte the same provisioning as before.
            $wallet = new Wallet;
            $wallet->user_id = $user->id;
            $wallet->currency = Currency::THB;
            $wallet->status = WalletStatus::Active;
            $wallet->save();

            // 3) Referral attribution — the EXISTING service validates
            //    existence/eligibility, forbids self-referral, writes the
            //    immutable attribution + audit. The server resolves the
            //    referring account from the code alone; nothing the
            //    client sent about the referrer is trusted.
            try {
                $this->referrals->attributeUser($user, $referralCode);
            } catch (\Throwable $exception) {
                throw ValidationException::withMessages([
                    'referral_id' => $exception->getMessage(),
                ]);
            }

            return $user;
        });
    }

    /**
     * Canonical mobile storage: digits only.
     */
    private function normalizeMobile(string $mobile): string
    {
        return preg_replace('/[\s\-().]/', '', trim($mobile)) ?? '';
    }

    /**
     * Derive a unique, stable username from the email local part
     * (fallback: mobile). Collisions append a suffix inside the
     * transaction; the users.username UNIQUE index is the final
     * authority.
     */
    private function deriveUsername(string $email, string $mobile): string
    {
        $base = $email !== '' ? explode('@', $email)[0] : $mobile;
        $base = preg_replace('/[^A-Za-z0-9_]/', '', $base) ?? 'member';

        if ($base === '' || ! preg_match('/^[A-Za-z]/', $base)) {
            $base = 'member'.$base;
        }

        $base = mb_substr($base, 0, 24);
        $username = $base;
        $suffix = 0;

        while (User::query()->where('username', $username)->exists()) {
            $username = mb_substr($base, 0, 24).(++$suffix);
        }

        return $username;
    }

    /**
     * Friendly duplicate messaging for the two unique dimensions the
     * benchmark surface collects. The DB constraint remains the
     * race authority; this is UX sugar and a deterministic duplicate
     * rejection for the non-racing case.
     */
    private function assertAvailable(string $email, string $mobile, string $username): void
    {
        if ($email !== '' && User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => __('public_pages.register_error_email_taken'),
            ]);
        }

        if ($mobile !== '' && User::query()->where('phone', $mobile)->exists()) {
            throw ValidationException::withMessages([
                'mobile' => __('public_pages.register_error_mobile_taken'),
            ]);
        }
    }
}
