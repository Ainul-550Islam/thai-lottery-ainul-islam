<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\SecurityCheckKey;
use App\Models\SecurityEvent;
use App\Models\SecuritySession;
use App\Models\TotpCredential;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The security score and the facts behind it. READ-ONLY.
 *
 * THE SCORE IS A SUM, AND EVERY ADDEND IS A READ
 *   score = sum of the weights of the checks that PASSED
 *   percent = floor(score * 100 / sum of all weights)
 *
 * Weights come from config; predicates come from real columns:
 *
 *   two_factor              an active, CONFIRMED totp_credentials row exists
 *   email_verified          users.email_verified_at is not null
 *   phone_verified          users.phone_verified_at is not null
 *   kyc_verified            users.kyc_status is Verified
 *   no_suspicious_activity  no UNREVIEWED security_events row for this member
 *                           inside the configured window
 *
 * WHY THIS CANNOT SHOW 100% WITH A FAILING CHECK
 * The percent is derived from the same boolean array the payload reports, so the
 * label and the checks cannot disagree. There is no branch that returns a score
 * on its own. SecurityOverviewTest asserts that switching 2FA off lowers the
 * score and that a failing check always leaves the score below 100.
 *
 * WHAT IT REFUSES TO SAY
 * The label is one of weak/fair/good/strong-plus-one-passed, never a claim such
 * as "fully secured" or "bank-level". Any wording asserting an absolute is in the
 * forbidden-claims deny list and is scanned by ForbiddenClaimsTest.
 *
 * THE COUNTS ARE COUNTS
 * trusted_devices and active_sessions are COUNT()s on the existing tables. The
 * login list is the newest N rows from the existing security-events table, not a
 * synthetic activity feed.
 */
final class SecurityOverviewService
{
    /**
     * @return array<string, mixed>
     */
    public function overview(User $user, ?Carbon $at = null): array
    {
        $now = $at ?? Carbon::now();

        $checks = $this->checks($user, $now);

        $maxScore = 0;
        $score = 0;

        foreach ($checks as $check) {
            $maxScore += $check['weight'];

            if ($check['passed']) {
                $score += $check['weight'];
            }
        }

        $percent = $maxScore > 0 ? intdiv($score * 100, $maxScore) : 0;

        return [
            'score' => $score,
            'max_score' => $maxScore,
            'score_percent' => $percent,
            'label' => $this->labelFor($percent),
            'checks' => $checks,
            'all_checks_passed' => $score === $maxScore && $maxScore > 0,
            'trusted_devices' => $this->trustedDeviceCount($user),
            'active_sessions' => $this->activeSessionCount($user),
            'recent_logins' => $this->recentLogins($user),
            'generated_at' => $now->toIso8601String(),
        ];
    }

    /**
     * Every check, in enum order, each with its verdict and its configured
     * weight. A check whose backing table is missing fails — never passes by
     * default, because a fail-closed security score is the only defensible one.
     *
     * @return list<array{key:string, label:string, hint:string, passed:bool, weight:int, remediation_path:string|null}>
     */
    public function checks(User $user, ?Carbon $at = null): array
    {
        $now = $at ?? Carbon::now();

        $verdicts = [
            SecurityCheckKey::TwoFactor->value => $this->hasTwoFactor($user),
            SecurityCheckKey::EmailVerified->value => $user->email_verified_at !== null,
            SecurityCheckKey::PhoneVerified->value => $user->phone_verified_at !== null,
            SecurityCheckKey::KycVerified->value => $user->kyc_status?->isVerified() === true,
            SecurityCheckKey::NoSuspiciousActivity->value => ! $this->hasUnresolvedAlerts($user, $now),
        ];

        $out = [];

        foreach (SecurityCheckKey::cases() as $key) {
            $out[] = [
                'key' => $key->value,
                'label' => $key->label(),
                'hint' => $key->hint(),
                'passed' => (bool) ($verdicts[$key->value] ?? false),
                'weight' => $key->weight(),
                'remediation_path' => $key->remediationPath(),
            ];
        }

        return $out;
    }

    /**
     * A confirmed TOTP credential. Unconfirmed enrolments do not count: a
     * half-finished setup protects nothing, and counting it would tell a member
     * they are protected when they are not.
     */
    private function hasTwoFactor(User $user): bool
    {
        try {
            return TotpCredential::query()
                ->where('user_id', $user->id)
                ->whereNotNull('confirmed_at_epoch')
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Unreviewed security events inside the window.
     *
     * `reviewed = false` rather than "any event": a login from a new device that
     * an operator has already looked at is not an open question, and counting it
     * forever would make the check permanently fail for every active member.
     */
    private function hasUnresolvedAlerts(User $user, Carbon $now): bool
    {
        $window = max(1, (int) config('player_experience.security.suspicious_window_days', 30));

        try {
            return SecurityEvent::query()
                ->where('user_id', $user->id)
                ->where('reviewed', false)
                ->where('occurred_at', '>=', $now->copy()->subDays($window))
                ->exists();
        } catch (\Throwable) {
            // Fail closed: an unreadable alert table is not evidence of safety.
            return true;
        }
    }

    private function trustedDeviceCount(User $user): int
    {
        try {
            return (int) TrustedDevice::query()->where('user_id', $user->id)->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function activeSessionCount(User $user): int
    {
        try {
            return (int) SecuritySession::query()
                ->where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', Carbon::now())
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * The newest login-ish events for this member, from the existing table.
     *
     * @return list<array<string, mixed>>
     */
    public function recentLogins(User $user): array
    {
        $limit = max(1, (int) config('player_experience.security.login_events_limit', 4));

        try {
            return SecurityEvent::query()
                ->where('user_id', $user->id)
                ->orderByDesc('occurred_at')
                ->limit($limit)
                ->get()
                ->map(static fn (SecurityEvent $e): array => [
                    'event_type' => $e->event_type,
                    'risk_level' => $e->risk_level,
                    'ip_address' => $e->ip_address,
                    'occurred_at' => $e->occurred_at?->toIso8601String(),
                    'reviewed' => (bool) $e->reviewed,
                ])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The label for a percentage, from configured lower bounds, highest first.
     *
     * Deliberately not "secure"/"fully secure": the wording describes the SCORE
     * ("strong", "good"), never the platform. Words that would be a claim live in
     * the forbidden list and are tested.
     */
    private function labelFor(int $percent): string
    {
        /** @var array<string, int> $thresholds */
        $thresholds = (array) config('player_experience.security.score_labels', []);

        arsort($thresholds);

        foreach ($thresholds as $label => $floor) {
            if ($percent >= (int) $floor) {
                return ucfirst((string) $label);
            }
        }

        return 'Weak';
    }
}
