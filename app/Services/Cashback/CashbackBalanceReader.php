<?php

declare(strict_types=1);

namespace App\Services\Cashback;

use App\Enums\CashbackAccrualStatus;
use App\Models\CashbackAccrual;
use App\Models\CashbackPolicy;
use App\Models\User;
use App\Services\Account\AccountGradeEvaluator;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

final class CashbackBalanceReader
{
    public function __construct(
        private readonly AccountGradeEvaluator $gradeEvaluator,
        private readonly CashbackAccrualService $accrualService,
        private readonly CashbackCalculator $calculator,
    ) {}

    /**
     * Read-only sum of accrued + unexpired cashback for a member.
     */
    public function claimableBalance(User $member, ?CarbonInterface $now = null): string
    {
        $pointInTime = $now ?? Carbon::now();

        $amounts = CashbackAccrual::query()
            ->where('member_id', $member->getKey())
            ->claimable($pointInTime)
            ->pluck('accrual_amount');

        $total = '0.00';
        foreach ($amounts as $amount) {
            $total = bcadd($total, (string) $amount, 2);
        }

        return $total;
    }

    /**
     * @return array{
     *     currency: string,
     *     target_wallet: string,
     *     grade: string,
     *     base_rate_bps: int,
     *     active_boost_bps: int,
     *     effective_rate_bps: int,
     *     claimable_amount: string,
     *     claimable_count: int,
     *     min_claim: string,
     *     max_per_period: string,
     *     can_claim: bool,
     *     lifetime_claimed_amount: string,
     *     next_expiry_at: string|null,
     *     recent_accruals: list<array<string, mixed>>
     * }
     */
    public function summary(User $member, ?CarbonInterface $now = null, int $recentLimit = 10): array
    {
        $pointInTime = $now ?? Carbon::now();
        $evaluation = $this->gradeEvaluator->evaluate($member);
        $grade = $evaluation->level;

        /** @var CashbackPolicy|null $policy */
        $policy = CashbackPolicy::query()
            ->activeForGrade($grade)
            ->orderByDesc('id')
            ->first();

        $baseRateBps = $policy instanceof CashbackPolicy ? (int) $policy->rate_bps : 0;
        $boostBps = $this->accrualService->activeBoostBpsForMember($member, $pointInTime);
        $minClaim = $policy instanceof CashbackPolicy
            ? bcadd((string) $policy->min_claim, '0.00', 2)
            : bcadd((string) config('rewards.cashback.default_min_claim', '10.00'), '0.00', 2);
        $maxPerPeriod = $policy instanceof CashbackPolicy
            ? bcadd((string) $policy->max_per_period, '0.00', 2)
            : '0.00';

        $claimableRows = CashbackAccrual::query()
            ->where('member_id', $member->getKey())
            ->claimable($pointInTime)
            ->orderBy('expires_at')
            ->get();

        $claimableAmount = '0.00';
        $nextExpiryAt = null;

        foreach ($claimableRows as $row) {
            $claimableAmount = bcadd($claimableAmount, (string) $row->accrual_amount, 2);
            if ($nextExpiryAt === null && $row->expires_at !== null) {
                $nextExpiryAt = $row->expires_at->toIso8601String();
            }
        }

        $claimedRows = CashbackAccrual::query()
            ->where('member_id', $member->getKey())
            ->where('status', CashbackAccrualStatus::Claimed->value)
            ->pluck('accrual_amount');

        $lifetimeClaimed = '0.00';
        foreach ($claimedRows as $amount) {
            $lifetimeClaimed = bcadd($lifetimeClaimed, (string) $amount, 2);
        }

        $recent = CashbackAccrual::query()
            ->where('member_id', $member->getKey())
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->limit(max(1, min(50, $recentLimit)))
            ->get()
            ->map(static fn (CashbackAccrual $row): array => [
                'id' => (int) $row->getKey(),
                'period_start' => $row->period_start->toIso8601String(),
                'period_end' => $row->period_end->toIso8601String(),
                'basis_amount' => bcadd((string) $row->basis_amount, '0.00', 2),
                'accrual_amount' => bcadd((string) $row->accrual_amount, '0.00', 2),
                'status' => $row->status->value,
                'expires_at' => $row->expires_at?->toIso8601String(),
                'claimed_at' => $row->claimed_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $canClaim = $claimableRows->isNotEmpty()
            && $this->calculator->isClaimableAmount($claimableAmount, $minClaim);

        return [
            'currency' => strtoupper((string) config('rewards.currency', 'THB')),
            'target_wallet' => (string) config('rewards.cashback.target_wallet', 'main'),
            'grade' => $grade->value,
            'base_rate_bps' => $baseRateBps,
            'active_boost_bps' => $boostBps,
            'effective_rate_bps' => $baseRateBps + $boostBps,
            'claimable_amount' => $claimableAmount,
            'claimable_count' => $claimableRows->count(),
            'min_claim' => $minClaim,
            'max_per_period' => $maxPerPeriod,
            'can_claim' => $canClaim,
            'lifetime_claimed_amount' => $lifetimeClaimed,
            'next_expiry_at' => $nextExpiryAt,
            'recent_accruals' => $recent,
        ];
    }
}
