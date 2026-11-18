<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Enums\KycStatus;
use App\Enums\WalletType;
use App\Models\User;
use App\Services\Wallet\WalletOverviewService;
use Illuminate\Support\Carbon;

/**
 * The member's own profile, as the profile screen needs it. READ-ONLY.
 *
 * MASKING IS DONE HERE, NOT IN THE VIEW
 * A view that masks is a view that can forget to mask; a JSON API that returns an
 * unmasked number relies on the client to hide it, and the client is not the
 * system. So the values below leave this service already masked:
 *
 *   email  a***@example.com   — first character, then the domain
 *   phone  ***-***-1234       — last four only, preserving no other digits
 *   ic_no  ****1234A          — last four only
 *
 * The member never needs the full value back from this endpoint: changing an
 * email or a phone goes through the verification flow, which re-asks for it.
 *
 * THE STATUSES ARE NOT MASKED AND NOT DERIVED TWICE
 * kyc/email/phone statuses are read from the columns the verification lanes
 * already write (users.kyc_status, users.email_verified_at,
 * users.phone_verified_at). Nothing is inferred from the presence of a document
 * row, because that inference is the KYC service's job and it already exists —
 * see User::effectiveKycStatus().
 *
 * THE BALANCE SUMMARY IS NOT COMPUTED HERE
 * account_summary delegates to WalletOverviewService, which owns the four-wallet
 * projection. Two services computing a "total balance" is how a screen ends up
 * disagreeing with itself across two tabs.
 */
final class ProfileOverviewService
{
    public function __construct(
        private readonly WalletOverviewService $walletOverview,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(User $user, ?Carbon $at = null): array
    {
        $kyc = $user->kyc_status instanceof KycStatus
            ? $user->kyc_status
            : KycStatus::Unverified;

        $wallet = $this->walletOverview->overview((int) $user->id, $at);

        return [
            'member' => [
                'id' => (int) $user->id,
                'display_name' => $this->displayName($user),
                'username' => $user->username,
                'member_since' => $user->created_at?->toDateString(),
                'status' => $user->status?->value ?? null,
            ],
            'personal' => [
                'email' => $this->maskEmail($user->email),
                'phone' => $this->maskPhone($user->phone),
                'ic_no' => $this->maskTail((string) $user->ic_no, 4),
                'date_of_birth' => $user->date_of_birth?->toDateString(),
                'gender' => $user->gender,
                'city' => $user->city,
                'country' => $user->country,
                'nationality' => $user->nationality,
            ],
            'verification' => [
                'email_verified' => $user->email_verified_at !== null,
                'phone_verified' => $user->phone_verified_at !== null,
                'kyc_status' => $kyc->value,
                'kyc_verified' => $kyc->isVerified(),
                // A member needs to know whether they can act, not what the enum
                // is called. KycStatus exposes no label() in this tree, so the
                // humanised value is used rather than inventing a second
                // vocabulary that could drift from the enum.
                'kyc_label' => ucfirst(str_replace('_', ' ', $kyc->value)),
            ],
            'balances' => [
                'main' => $wallet['balances']['main'],
                'win' => $wallet['balances']['win'],
                'bonus' => $wallet['balances']['bonus'],
                'cashback' => $wallet['balances']['cashback'],
                'currency' => $wallet['currency'],
            ],
            'wallet_types_available' => array_map(
                static fn (WalletType $t): string => $t->value,
                WalletType::cases(),
            ),
        ];
    }

    /**
     * A name safe to greet a member with. Falls back to the username, then to a
     * neutral form — never to an email address, which would be a leak in a
     * screenshot.
     */
    private function displayName(User $user): string
    {
        $name = trim((string) $user->name);

        if ($name !== '') {
            return $name;
        }

        $username = trim((string) $user->username);

        return $username !== '' ? $username : 'Member';
    }

    private function maskEmail(?string $email): ?string
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        $at = strpos($email, '@');

        if ($at === false || $at === 0) {
            return $this->maskTail($email, 0);
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at);

        $first = substr($local, 0, 1);

        return $first.str_repeat('*', max(3, strlen($local) - 1)).$domain;
    }

    /**
     * Keeps the LAST FOUR digits and replaces everything else, including the
     * separators a member might have typed — because the separators are part of
     * the pattern and a masked phone must not be reconstructible from them.
     */
    private function maskPhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) < 4) {
            return $this->maskTail($digits, 0);
        }

        return '***-***-'.substr($digits, -4);
    }

    private function maskTail(string $value, int $keep): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if ($keep <= 0) {
            return str_repeat('*', max(4, strlen($value)));
        }

        $tail = substr($value, -$keep);

        return str_repeat('*', max(0, strlen($value) - $keep)).$tail;
    }
}
