<?php

declare(strict_types=1);

namespace App\Services\Affiliate;

use App\Enums\CommissionStatus;
use App\Enums\Currency;
use App\Enums\WalletType;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\Bet;
use App\Models\CommissionRule;
use App\Models\ReferralLinkClick;
use App\Models\User;
use App\Services\Agent\PublicReferralLinkService;
use App\Services\Finance\WalletHoldService;
use App\Services\Finance\WalletService;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

final class ReferralCenterService
{
    public function __construct(
        private readonly PublicReferralLinkService $referralLinks,
        private readonly AffiliateCommissionDisplayService $commissionDisplay,
        private readonly WalletService $wallets,
        private readonly WalletHoldService $walletHolds,
    ) {}

    /**
     * Full read model for GET /api/v1/me/referral-center (link, qr_payload string,
     * stats, 30d series, masked team list, and rate from Agent / CommissionRule / AffiliateCommissionDisplayService).
     *
     * @return array{
     *     referral_code: string,
     *     referral_url: string,
     *     qr_payload: string,
     *     commission_rate_percent: string,
     *     currency: string,
     *     stats: array{
     *         total_referrals: int,
     *         active_referrals: int,
     *         total_clicks: int,
     *         lifetime_commission: string,
     *         pending_commission: string,
     *         available_commission_balance: string
     *     },
     *     earnings_30d: list<array{date: string, amount: string}>,
     *     team: array{
     *         items: list<array{
     *             masked_username: string,
     *             joined_at: string|null,
     *             is_active: bool,
     *             commission_generated: string
     *         }>,
     *         pagination: array{current_page: int, per_page: int, total: int, last_page: int}
     *     }
     * }
     */
    public function readModelFor(User $member, int $teamPerPage = 20, ?CarbonInterface $now = null): array
    {
        $overview = $this->overviewFor($member, $now);
        $overview['team'] = $this->teamFor($member, $teamPerPage, $now);

        return $overview;
    }

    /**
     * @return array{
     *     referral_code: string,
     *     referral_url: string,
     *     qr_payload: string,
     *     commission_rate_percent: string,
     *     currency: string,
     *     stats: array{
     *         total_referrals: int,
     *         active_referrals: int,
     *         total_clicks: int,
     *         lifetime_commission: string,
     *         pending_commission: string,
     *         available_commission_balance: string
     *     },
     *     earnings_30d: list<array{date: string, amount: string}>
     * }
     */
    public function overviewFor(User $member, ?CarbonInterface $now = null): array
    {
        $pointInTime = $now !== null ? Carbon::instance($now->toDateTime()) : Carbon::now();
        $currency = Currency::from(strtoupper((string) config('rewards.currency', 'THB')));

        /** @var Agent|null $agent */
        $agent = Agent::query()->where('user_id', $member->getKey())->first();

        $referralCode = $agent instanceof Agent
            ? (string) $agent->agent_code
            : strtoupper((string) ($member->username !== '' ? $member->username : 'REF'.$member->getKey()));

        $referralUrl = $agent instanceof Agent
            ? $this->referralLinks->publicUrlFor($agent)
            : url(rtrim((string) config('rewards.referral.share_path', '/register'), '/').'?ref='.rawurlencode($referralCode));

        $commissionRatePercent = $this->resolveCommissionRatePercent($agent);
        $commissionWallet = $this->wallets->getOrCreateWallet($member, $currency, WalletType::Commission);
        $availableBalance = $this->walletHolds->availableAmount($commissionWallet)->toString();

        $totalReferrals = 0;
        $activeReferrals = 0;
        $totalClicks = 0;
        $lifetimeCommission = '0.00';
        $pendingCommission = '0.00';

        if ($agent instanceof Agent) {
            $referredIds = $this->referredMemberIdsForAgent($agent);
            $totalReferrals = max((int) $agent->total_referrals, count($referredIds));

            if ($referredIds !== []) {
                $activeWindowDays = max(1, (int) config('rewards.referral.active_window_days', 30));
                $activeCutoff = $pointInTime->copy()->subDays($activeWindowDays);

                $activeReferrals = Bet::query()
                    ->whereIn('user_id', $referredIds)
                    ->where('created_at', '>=', $activeCutoff)
                    ->distinct('user_id')
                    ->count('user_id');
            }

            $totalClicks = ReferralLinkClick::query()
                ->where('agent_id', $agent->getKey())
                ->count();

            $paidRows = AgentCommission::query()
                ->where('agent_id', $agent->getKey())
                ->where('status', CommissionStatus::Paid->value)
                ->pluck('commission_amount');

            foreach ($paidRows as $amount) {
                $lifetimeCommission = bcadd($lifetimeCommission, (string) $amount, 2);
            }

            if (bccomp((string) $agent->total_commission_earned, $lifetimeCommission, 2) > 0) {
                $lifetimeCommission = bcadd((string) $agent->total_commission_earned, '0.00', 2);
            }

            $pendingRows = AgentCommission::query()
                ->where('agent_id', $agent->getKey())
                ->whereIn('status', [
                    CommissionStatus::Accrued->value,
                    CommissionStatus::Calculated->value,
                    CommissionStatus::Payable->value,
                ])
                ->pluck('commission_amount');

            foreach ($pendingRows as $amount) {
                $pendingCommission = bcadd($pendingCommission, (string) $amount, 2);
            }
        }

        return [
            'referral_code' => $referralCode,
            'referral_url' => $referralUrl,
            'qr_payload' => $referralUrl,
            'commission_rate_percent' => $commissionRatePercent,
            'currency' => $currency->value,
            'stats' => [
                'total_referrals' => $totalReferrals,
                'active_referrals' => $activeReferrals,
                'total_clicks' => $totalClicks,
                'lifetime_commission' => $lifetimeCommission,
                'pending_commission' => $pendingCommission,
                'available_commission_balance' => $availableBalance,
            ],
            'earnings_30d' => $this->buildEarningsSeries($agent, $pointInTime),
        ];
    }

    /**
     * @return array{
     *     items: list<array{
     *         masked_username: string,
     *         joined_at: string|null,
     *         is_active: bool,
     *         commission_generated: string
     *     }>,
     *     pagination: array{current_page: int, per_page: int, total: int, last_page: int}
     * }
     */
    public function teamFor(User $member, int $perPage = 20, ?CarbonInterface $now = null): array
    {
        $pointInTime = $now !== null ? Carbon::instance($now->toDateTime()) : Carbon::now();
        $defaultPerPage = max(1, (int) config('rewards.referral.team_per_page', 20));
        $maxPerPage = max($defaultPerPage, (int) config('rewards.referral.max_team_per_page', 100));
        $resolvedPerPage = max(1, min($maxPerPage, $perPage > 0 ? $perPage : $defaultPerPage));

        /** @var Agent|null $agent */
        $agent = Agent::query()->where('user_id', $member->getKey())->first();

        if (! $agent instanceof Agent) {
            return [
                'items' => [],
                'pagination' => [
                    'current_page' => 1,
                    'per_page' => $resolvedPerPage,
                    'total' => 0,
                    'last_page' => 1,
                ],
            ];
        }

        /** @var LengthAwarePaginator<User> $paginator */
        $paginator = User::query()
            ->where('preferences->referred_by_agent_id', $agent->getKey())
            ->orderByDesc('id')
            ->paginate($resolvedPerPage);

        $activeWindowDays = max(1, (int) config('rewards.referral.active_window_days', 30));
        $activeCutoff = $pointInTime->copy()->subDays($activeWindowDays);

        /** @var list<User> $members */
        $members = $paginator->items();
        $memberIds = array_map(static fn (User $u): int => (int) $u->getKey(), $members);

        $activeLookup = [];
        $commissionByMember = [];

        if ($memberIds !== []) {
            $activeUserIds = Bet::query()
                ->whereIn('user_id', $memberIds)
                ->where('created_at', '>=', $activeCutoff)
                ->distinct()
                ->pluck('user_id')
                ->all();

            foreach ($activeUserIds as $uid) {
                $activeLookup[(int) $uid] = true;
            }

            $commissions = AgentCommission::query()
                ->join('bets', 'bets.id', '=', 'agent_commissions.bet_id')
                ->where('agent_commissions.agent_id', $agent->getKey())
                ->whereIn('bets.user_id', $memberIds)
                ->whereNotIn('agent_commissions.status', [
                    CommissionStatus::Cancelled->value,
                    CommissionStatus::Reversed->value,
                ])
                ->get(['bets.user_id as referred_user_id', 'agent_commissions.commission_amount']);

            foreach ($commissions as $row) {
                $uid = (int) $row->getAttribute('referred_user_id');
                $commissionByMember[$uid] = bcadd(
                    $commissionByMember[$uid] ?? '0.00',
                    (string) $row->commission_amount,
                    2,
                );
            }
        }

        $items = [];
        foreach ($members as $referred) {
            $uid = (int) $referred->getKey();
            $prefs = is_array($referred->preferences) ? $referred->preferences : [];
            $referredAt = isset($prefs['referred_at']) && is_string($prefs['referred_at'])
                ? $prefs['referred_at']
                : $referred->created_at?->toIso8601String();

            $items[] = [
                'masked_username' => $this->maskUsername((string) ($referred->username ?: $referred->name)),
                'joined_at' => $referredAt,
                'is_active' => isset($activeLookup[$uid]),
                'commission_generated' => $commissionByMember[$uid] ?? '0.00',
            ];
        }

        return [
            'items' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    public function maskUsername(string $raw): string
    {
        $trimmed = trim($raw);
        $length = mb_strlen($trimmed);

        if ($length <= 2) {
            return mb_substr($trimmed, 0, 1).'***';
        }

        if ($length <= 4) {
            return mb_substr($trimmed, 0, 1).'***'.mb_substr($trimmed, -1);
        }

        return mb_substr($trimmed, 0, 2).'***'.mb_substr($trimmed, -2);
    }

    private function resolveCommissionRatePercent(?Agent $agent): string
    {
        if ($agent instanceof Agent) {
            $directRate = $agent->getAttribute('direct_commission_rate');
            if ($directRate !== null && is_numeric((string) $directRate) && bccomp((string) $directRate, '0.00', 2) > 0) {
                return bcadd((string) $directRate, '0.00', 2);
            }

            return bcadd((string) $agent->commission_rate, '0.00', 2);
        }

        $ruleRate = CommissionRule::query()
            ->where('is_active', true)
            ->orderBy('tier_level')
            ->value('override_percentage');

        if ($ruleRate !== null && is_numeric((string) $ruleRate)) {
            return bcadd((string) $ruleRate, '0.00', 2);
        }

        $catalogue = $this->commissionDisplay->publicCatalogue();
        if ($catalogue['bands'] !== []) {
            return bcadd($catalogue['bands'][0]['rate_percentage'], '0.00', 2);
        }

        return bcadd((string) config('rewards.referral.default_commission_rate', '0.00'), '0.00', 2);
    }

    /**
     * @return list<int>
     */
    private function referredMemberIdsForAgent(Agent $agent): array
    {
        return User::query()
            ->where('preferences->referred_by_agent_id', $agent->getKey())
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return list<array{date: string, amount: string}>
     */
    private function buildEarningsSeries(?Agent $agent, Carbon $now): array
    {
        $days = max(1, (int) config('rewards.referral.series_days', 30));
        $startDate = $now->copy()->subDays($days - 1)->startOfDay();
        $totalsByDate = [];

        if ($agent instanceof Agent) {
            $rows = AgentCommission::query()
                ->where('agent_id', $agent->getKey())
                ->whereNotIn('status', [
                    CommissionStatus::Cancelled->value,
                    CommissionStatus::Reversed->value,
                ])
                ->where('created_at', '>=', $startDate)
                ->get(['commission_amount', 'created_at']);

            foreach ($rows as $row) {
                $dayKey = ($row->created_at ?? $now)->format('Y-m-d');
                $totalsByDate[$dayKey] = bcadd($totalsByDate[$dayKey] ?? '0.00', (string) $row->commission_amount, 2);
            }
        }

        $series = [];
        for ($offset = 0; $offset < $days; $offset++) {
            $date = $startDate->copy()->addDays($offset)->format('Y-m-d');
            $series[] = [
                'date' => $date,
                'amount' => $totalsByDate[$date] ?? '0.00',
            ];
        }

        return $series;
    }
}
