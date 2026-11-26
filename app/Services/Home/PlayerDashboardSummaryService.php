<?php

declare(strict_types=1);

namespace App\Services\Home;

use App\Enums\BetStatus;
use App\Enums\Currency;
use App\Enums\DrawStatus;
use App\Enums\WalletType;
use App\Models\Bet;
use App\Models\Draw;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Services\Affiliate\ReferralCenterService;
use App\Services\Cashback\CashbackBalanceReader;
use App\Services\Finance\WalletHoldService;
use App\Services\Finance\WalletService;
use App\Services\Loyalty\LoyaltyPointsService;
use App\Services\Rewards\FreeBetVoucherService;
use App\Services\Rewards\VipProgressService;
use App\Services\Security\LoginHistory\LoginHistoryQueryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Pure read-only composition across existing canonical player services.
 * Introduces zero database tables and caches per member up to configured TTL.
 */
final class PlayerDashboardSummaryService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly WalletHoldService $walletHolds,
        private readonly LoyaltyPointsService $loyaltyPoints,
        private readonly VipProgressService $vipProgress,
        private readonly CashbackBalanceReader $cashbackReader,
        private readonly FreeBetVoucherService $freeBetVouchers,
        private readonly ReferralCenterService $referrals,
        private readonly LoginHistoryQueryService $loginHistory,
        private readonly HomeCountdownService $countdown,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summaryFor(User $member, bool $bypassCache = false): array
    {
        $ttl = max(0, (int) config('rewards.dashboard.cache_ttl_seconds', 30));
        $cacheKey = $this->cacheKeyFor($member);

        if ($bypassCache || $ttl === 0) {
            return $this->compose($member);
        }

        /** @var array<string, mixed> $payload */
        $payload = Cache::remember($cacheKey, $ttl, fn (): array => $this->compose($member));

        return $payload;
    }

    public function invalidate(User|int $member): void
    {
        app(DashboardCacheInvalidator::class)->forget($member);
    }

    public function cacheKeyFor(User|int $member): string
    {
        return app(DashboardCacheInvalidator::class)->keyFor($member);
    }

    /**
     * @return array<string, mixed>
     */
    private function compose(User $member): array
    {
        $now = Carbon::now();
        $currency = Currency::from(strtoupper((string) config('rewards.currency', 'THB')));

        $primaryWallet = $this->wallets->getOrCreateWallet($member, $currency, WalletType::Primary);
        $bonusWallet = $this->wallets->getOrCreateWallet($member, $currency, WalletType::Bonus);
        $commissionWallet = $this->wallets->getOrCreateWallet($member, $currency, WalletType::Commission);

        // Single-query aggregate over member bets for counts + lifetime winnings (zero N+1).
        /** @var object{total_bets: int|string|null, active_bets: int|string|null, settled_bets: int|string|null, won_bets: int|string|null, total_winnings: string|int|null}|null $betStats */
        $betStats = Bet::query()
            ->where('user_id', $member->getKey())
            ->selectRaw(
                'COUNT(*) as total_bets, '
                .'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as active_bets, '
                .'SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as settled_bets, '
                .'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as won_bets, '
                .'COALESCE(SUM(CASE WHEN status = ? THEN actual_payout ELSE 0 END), 0) as total_winnings',
                [
                    BetStatus::Active->value,
                    BetStatus::Won->value,
                    BetStatus::Lost->value,
                    BetStatus::Won->value,
                    BetStatus::Won->value,
                ],
            )
            ->first();

        $recentActivity = FinancialTransaction::query()
            ->where('user_id', $member->getKey())
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'type', 'status', 'amount', 'currency', 'description', 'created_at'])
            ->map(static fn (FinancialTransaction $tx): array => [
                'id' => (int) $tx->getKey(),
                'type' => is_object($tx->type) && property_exists($tx->type, 'value') ? (string) $tx->type->value : (string) $tx->type,
                'status' => is_object($tx->status) && property_exists($tx->status, 'value') ? (string) $tx->status->value : (string) $tx->status,
                'amount' => bcadd((string) $tx->amount, '0.00', 2),
                'currency' => is_object($tx->currency) && property_exists($tx->currency, 'value') ? (string) $tx->currency->value : (string) $tx->currency,
                'description' => $tx->description,
                'occurred_at' => $tx->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $upcomingDraws = Draw::query()
            ->whereIn('status', [DrawStatus::Scheduled->value, DrawStatus::Open->value])
            ->where('scheduled_at', '>=', $now)
            ->orderBy('scheduled_at')
            ->limit(5)
            ->get(['id', 'draw_number', 'type', 'status', 'scheduled_at'])
            ->map(static fn (Draw $draw): array => [
                'id' => (int) $draw->getKey(),
                'draw_number' => $draw->draw_number,
                'type' => $draw->type->value,
                'status' => $draw->status->value,
                'scheduled_at' => $draw->scheduled_at->toIso8601String(),
            ])
            ->values()
            ->all();

        $vip = $this->vipProgress->progressFor($member, $now);
        $cashback = $this->cashbackReader->summary($member, $now, recentLimit: 5);
        $vouchers = $this->freeBetVouchers->usableSummaryForMember($member, $now);
        $referral = $this->referrals->overviewFor($member, $now);
        $security = $this->loginHistory->summaryForMember($member, $now);
        $nextDraw = $this->countdown->nextDrawCountdown();

        return [
            'member_id' => (int) $member->getKey(),
            'currency' => $currency->value,
            'wallets' => [
                'main' => [
                    'balance' => bcadd((string) $primaryWallet->balance, '0.00', 2),
                    'available' => $this->walletHolds->availableAmount($primaryWallet)->toString(),
                    'locked' => bcadd((string) $primaryWallet->locked_balance, '0.00', 2),
                ],
                'bonus' => [
                    'balance' => bcadd((string) $bonusWallet->balance, '0.00', 2),
                    'available' => $this->walletHolds->availableAmount($bonusWallet)->toString(),
                ],
                'commission' => [
                    'balance' => bcadd((string) $commissionWallet->balance, '0.00', 2),
                    'available' => $this->walletHolds->availableAmount($commissionWallet)->toString(),
                ],
            ],
            'winnings' => [
                'total_won' => bcadd((string) ($betStats->total_winnings ?? '0.00'), '0.00', 2),
                'won_bets_count' => (int) ($betStats->won_bets ?? 0),
                'currency' => $currency->value,
            ],
            'bets' => [
                'total_bets' => (int) ($betStats->total_bets ?? 0),
                'active_bets' => (int) ($betStats->active_bets ?? 0),
                'settled_bets' => (int) ($betStats->settled_bets ?? 0),
            ],
            'loyalty' => [
                'points_balance' => $this->loyaltyPoints->currentBalance($member),
            ],
            'vip' => $vip,
            'cashback' => [
                'claimable_amount' => $cashback['claimable_amount'],
                'can_claim' => $cashback['can_claim'],
                'effective_rate_bps' => $cashback['effective_rate_bps'],
                'next_expiry_at' => $cashback['next_expiry_at'],
            ],
            'free_bet_vouchers' => $vouchers,
            'referrals' => [
                'referral_code' => $referral['referral_code'],
                'commission_rate_percent' => $referral['commission_rate_percent'],
                'total_referrals' => $referral['stats']['total_referrals'],
                'active_referrals' => $referral['stats']['active_referrals'],
                'available_commission_balance' => $referral['stats']['available_commission_balance'],
            ],
            'recent_activity' => $recentActivity,
            'upcoming_draws' => $upcomingDraws,
            'security' => $security,
            'next_draw' => $nextDraw,
            'generated_at' => $now->toIso8601String(),
        ];
    }
}
