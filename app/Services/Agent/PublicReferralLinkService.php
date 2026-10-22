<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Agent;
use App\Models\ReferralLinkClick;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Public Referral Landing Links & Member Attribution Projection (Audit §D6.4).
 *
 * Complements `AgentReferralService` without duplicating its ownership rules:
 *   - Resolves public `/r/{code}` landing visits
 *   - Deduplicates clicks using salted SHA-256 IP hashes (zero raw IP persisted)
 *   - Supplies pre-filled referral codes to `/register` via query/session/cookie
 *   - Builds the read model for the authenticated member referral dashboard
 */
final class PublicReferralLinkService
{
    public const SESSION_KEY = 'referral_agent_code';

    public function normaliseCode(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $code = strtoupper(trim($raw));
        if ($code === '' || preg_match('/^[A-Z0-9_-]{3,32}$/', $code) !== 1) {
            return null;
        }

        return $code;
    }

    public function hashIp(?string $ipAddress): string
    {
        $salt = (string) config('conversion_tooling.referrals.ip_hash_salt', '');
        if ($salt === '') {
            $salt = (string) config('app.key', 'tl-referral-fallback-salt');
        }

        return hash_hmac('sha256', (string) ($ipAddress ?? '0.0.0.0'), $salt);
    }

    public function publicUrlFor(Agent|string $agentOrCode): string
    {
        $code = $agentOrCode instanceof Agent ? (string) $agentOrCode->agent_code : (string) $agentOrCode;

        return url('/r/'.rawurlencode(strtoupper(trim($code))));
    }

    /**
     * Validate a referral code against an active intake-eligible Agent and
     * record a deduplicated visit row.
     */
    public function recordVisit(
        string $rawCode,
        ?string $ipAddress = null,
        ?string $refererUrl = null,
        string $locale = 'en',
    ): ?Agent {
        if (! (bool) config('conversion_tooling.referrals.enabled', true)) {
            return null;
        }

        $code = $this->normaliseCode($rawCode);
        if ($code === null) {
            return null;
        }

        /** @var Agent|null $agent */
        $agent = Agent::query()->where('agent_code', $code)->first();
        if (! $agent instanceof Agent || ! $agent->canAcceptPlayers()) {
            return null;
        }

        $ipHash = $this->hashIp($ipAddress);
        $now = Carbon::now();
        $dedupMinutes = max(1, (int) config('conversion_tooling.referrals.dedup_window_minutes', 60));
        $cutoff = $now->copy()->subMinutes($dedupMinutes);

        $recentDuplicate = ReferralLinkClick::query()
            ->where('agent_code', $code)
            ->where('ip_hash', $ipHash)
            ->where('clicked_at', '>=', $cutoff)
            ->exists();

        if (! $recentDuplicate) {
            $referrerHost = null;
            if ($refererUrl !== null && $refererUrl !== '') {
                $parsedHost = parse_url($refererUrl, PHP_URL_HOST);
                if (is_string($parsedHost) && $parsedHost !== '') {
                    $referrerHost = substr(strtolower($parsedHost), 0, 190);
                }
            }

            ReferralLinkClick::query()->create([
                'agent_id' => (int) $agent->getKey(),
                'agent_code' => $code,
                'ip_hash' => $ipHash,
                'referrer_host' => $referrerHost,
                'locale' => substr(strtolower(trim($locale)), 0, 8) ?: 'en',
                'converted_user_id' => null,
                'clicked_at' => $now,
            ]);
        }

        return $agent;
    }

    /**
     * Resolve the referral code to pre-populate on `GET /register`.
     * Priority: query `?ref=` -> session -> HttpOnly attribution cookie.
     */
    public function resolvePrefillCode(Request $request): ?string
    {
        $cookieName = (string) config('conversion_tooling.referrals.cookie_name', 'tl_ref');

        $candidates = [
            $request->query('ref'),
            $request->query('referral_id'),
            $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null,
            $request->cookie($cookieName),
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $normalised = $this->normaliseCode($candidate);
            if ($normalised !== null) {
                return $normalised;
            }
        }

        return null;
    }

    /**
     * Mark the latest unconverted click from the same visitor hash as converted.
     */
    public function markConversion(User $newUser, ?string $rawCode, ?string $ipAddress = null): void
    {
        $code = $this->normaliseCode($rawCode);
        if ($code === null) {
            return;
        }

        $ipHash = $this->hashIp($ipAddress);

        /** @var ReferralLinkClick|null $click */
        $click = ReferralLinkClick::query()
            ->where('agent_code', $code)
            ->where('ip_hash', $ipHash)
            ->orderByDesc('id')
            ->first();

        if ($click instanceof ReferralLinkClick && $click->converted_user_id === null) {
            $click->converted_user_id = (int) $newUser->getKey();
            $click->save();
        }
    }

    /**
     * Projection for the authenticated member referral dashboard.
     *
     * @return array{
     *     has_agent: bool,
     *     agent_code: ?string,
     *     share_url: ?string,
     *     can_accept_players: bool,
     *     total_clicks: int,
     *     link_conversions: int,
     *     total_referrals: int,
     *     commission_rate: string,
     *     total_commission_earned: string,
     *     total_commission_paid: string,
     *     referred_by_code: ?string
     * }
     */
    public function dashboardSummaryFor(User $user): array
    {
        $prefs = is_array($user->preferences) ? $user->preferences : [];
        $referredBy = isset($prefs['agent_code']) && is_string($prefs['agent_code'])
            ? $prefs['agent_code']
            : null;

        /** @var Agent|null $agent */
        $agent = Agent::query()->where('user_id', $user->getKey())->first();

        if (! $agent instanceof Agent) {
            return [
                'has_agent' => false,
                'agent_code' => null,
                'share_url' => null,
                'can_accept_players' => false,
                'total_clicks' => 0,
                'link_conversions' => 0,
                'total_referrals' => 0,
                'commission_rate' => '0.0000',
                'total_commission_earned' => '0.00',
                'total_commission_paid' => '0.00',
                'referred_by_code' => $referredBy,
            ];
        }

        $clicks = ReferralLinkClick::query()
            ->where('agent_id', $agent->getKey())
            ->get();

        $totalClicks = $clicks->count();
        $linkConversions = $clicks
            ->filter(static fn (ReferralLinkClick $row): bool => $row->converted_user_id !== null)
            ->count();

        return [
            'has_agent' => true,
            'agent_code' => (string) $agent->agent_code,
            'share_url' => $this->publicUrlFor($agent),
            'can_accept_players' => $agent->canAcceptPlayers(),
            'total_clicks' => $totalClicks,
            'link_conversions' => $linkConversions,
            'total_referrals' => (int) $agent->total_referrals,
            'commission_rate' => number_format((float) $agent->commission_rate, 4, '.', ''),
            'total_commission_earned' => number_format((float) $agent->total_commission_earned, 2, '.', ''),
            'total_commission_paid' => number_format((float) $agent->total_commission_paid, 2, '.', ''),
            'referred_by_code' => $referredBy,
        ];
    }
}
