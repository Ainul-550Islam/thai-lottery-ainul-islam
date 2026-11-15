<?php

declare(strict_types=1);

namespace App\Services\Cashback;

use App\Enums\AuditAction;
use App\Enums\BetStatus;
use App\Enums\CashbackAccrualStatus;
use App\Enums\Currency;
use App\Enums\DrawStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\RiskLevel;
use App\Enums\WalletType;
use App\Models\AuditLog;
use App\Models\Bet;
use App\Models\CashbackAccrual;
use App\Models\CashbackPolicy;
use App\Models\Draw;
use App\Models\FinancialTransaction;
use App\Models\RewardClaim;
use App\Models\User;
use App\Services\Account\AccountGradeEvaluator;
use App\Services\Finance\IdempotencyService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletLockService;
use App\Services\Finance\WalletService;
use App\Services\Home\DashboardCacheInvalidator;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class CashbackAccrualService
{
    public function __construct(
        private readonly CashbackCalculator $calculator,
        private readonly AccountGradeEvaluator $gradeEvaluator,
        private readonly WalletService $wallets,
        private readonly WalletLockService $walletLocks,
        private readonly IdempotencyService $idempotency,
        private readonly DashboardCacheInvalidator $dashboardCache,
    ) {}

    public function accrueForMember(
        User $member,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
        ?string $period = null,
        bool $dryRun = false,
    ): ?CashbackAccrual {
        if (! (bool) config('rewards.cashback.enabled', true)) {
            return null;
        }

        $grade = $this->gradeEvaluator->evaluate($member, Carbon::instance($periodEnd->toDateTime()))->level;
        $resolvedPeriod = $period ?? (string) config('rewards.cashback.default_period', 'daily');

        /** @var CashbackPolicy|null $policy */
        $policy = CashbackPolicy::query()
            ->activeForGrade($grade)
            ->where('period', $resolvedPeriod)
            ->first();

        if (! $policy instanceof CashbackPolicy) {
            return null;
        }

        if ($dryRun) {
            return $this->previewAccrual($member, $policy, $periodStart, $periodEnd);
        }

        try {
            return DB::transaction(function () use ($member, $policy, $periodStart, $periodEnd): ?CashbackAccrual {
                User::query()->whereKey($member->getKey())->lockForUpdate()->firstOrFail();

                /** @var CashbackAccrual|null $existing */
                $existing = CashbackAccrual::query()
                    ->where('member_id', $member->getKey())
                    ->where('policy_id', $policy->getKey())
                    ->forPeriod($periodStart, $periodEnd)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof CashbackAccrual) {
                    return $existing;
                }

                $basisAmount = $this->settledRealMoneyNetLoss($member, $periodStart, $periodEnd);
                if (bccomp($basisAmount, '0.00', 2) <= 0) {
                    return null;
                }

                $boostBps = $this->activeBoostBpsForMember($member, $periodEnd);
                $accrualAmount = $this->calculator->calculateAccrualAmount(
                    $basisAmount,
                    (int) $policy->rate_bps,
                    $boostBps,
                    (string) $policy->max_per_period,
                );

                if (bccomp($accrualAmount, '0.00', 2) <= 0) {
                    return null;
                }

                $expiresAt = Carbon::instance($periodEnd->toDateTime())->addDays(max(1, (int) $policy->expiry_days));

                /** @var CashbackAccrual $accrual */
                $accrual = CashbackAccrual::query()->create([
                    'member_id' => (int) $member->getKey(),
                    'policy_id' => (int) $policy->getKey(),
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'basis_amount' => $basisAmount,
                    'accrual_amount' => $accrualAmount,
                    'status' => CashbackAccrualStatus::Accrued,
                    'expires_at' => $expiresAt,
                    'metadata' => [
                        'grade' => $policy->grade,
                        'base_rate_bps' => (int) $policy->rate_bps,
                        'boost_rate_bps' => $boostBps,
                        'effective_rate_bps' => (int) $policy->rate_bps + $boostBps,
                    ],
                ]);

                AuditLog::query()->create([
                    'user_id' => (int) $member->getKey(),
                    'action' => AuditAction::Create->value,
                    'auditable_type' => CashbackAccrual::class,
                    'auditable_id' => (int) $accrual->getKey(),
                    'risk_level' => RiskLevel::Low->value,
                    'description' => 'Cashback accrued for settled real-money net loss.',
                    'metadata' => [
                        'policy_id' => (int) $policy->getKey(),
                        'basis_amount' => $basisAmount,
                        'accrual_amount' => $accrualAmount,
                        'period_start' => $periodStart->toIso8601String(),
                        'period_end' => $periodEnd->toIso8601String(),
                    ],
                ]);

                $this->forgetDashboardCache((int) $member->getKey());

                return $accrual;
            }, 3);
        } catch (QueryException $exception) {
            /** @var CashbackAccrual|null $winner */
            $winner = CashbackAccrual::query()
                ->where('member_id', $member->getKey())
                ->where('policy_id', $policy->getKey())
                ->forPeriod($periodStart, $periodEnd)
                ->first();

            if ($winner instanceof CashbackAccrual) {
                return $winner;
            }

            throw $exception;
        }
    }

    /**
     * THE single definition of "this bet settled inside this period".
     *
     * A bet counts when its draw reached Completed inside [start, end]. The
     * accrual maths, the accrual job and the dry-run command all take this one
     * constraint, so the set of members chosen for accrual can never differ from
     * the set of bets that the accrual then counts.
     *
     * @return \Closure(QueryBuilder): void
     */
    public function drawCompletedWithin(CarbonInterface $periodStart, CarbonInterface $periodEnd): \Closure
    {
        return static function (QueryBuilder $draw) use ($periodStart, $periodEnd): void {
            $draw->selectRaw('1')
                ->from('draws')
                ->whereColumn('draws.id', 'bets.draw_id')
                ->where('draws.status', DrawStatus::Completed->value)
                ->whereBetween('draws.completed_at', [$periodStart, $periodEnd]);
        };
    }

    /**
     * Net loss of the member's settled real-money bets in one period.
     *
     * THE PERIOD IS DECIDED BY THE DRAW, NOT BY THE BET.
     * A bet belongs to the period in which its draw was completed
     * (draws.completed_at, written once when the draw reaches Settled).
     *
     * The previous version windowed Won bets on bets.won_at and Lost bets on
     * bets.updated_at. A Lost bet has no settlement timestamp of its own, and
     * updated_at moves on every later save() (payout link, metadata, reversal),
     * so a closed period's basis could change after the fact. A draw's
     * completed_at never moves, and a draw only becomes Completed once every
     * one of its bets has been settled, so a half-settled chunked run is never
     * counted.
     */
    public function settledRealMoneyNetLoss(
        User|int $member,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
    ): string {
        $memberId = $member instanceof User ? (int) $member->getKey() : $member;

        $bets = Bet::query()
            ->where('bets.user_id', $memberId)
            ->whereIn('bets.status', [BetStatus::Won->value, BetStatus::Lost->value])
            ->whereNull('bets.cancelled_at')
            ->whereExists($this->drawCompletedWithin($periodStart, $periodEnd))
            ->whereNotExists(function (QueryBuilder $sub): void {
                $sub->selectRaw('1')
                    ->from('free_bet_vouchers')
                    ->whereColumn('free_bet_vouchers.consumed_bet_id', 'bets.id');
            })
            ->whereNotExists(function (QueryBuilder $sub): void {
                $sub->selectRaw('1')
                    ->from('bet_purchase_ownerships')
                    ->join('wallets', 'wallets.id', '=', 'bet_purchase_ownerships.wallet_id')
                    ->whereColumn('bet_purchase_ownerships.bet_id', 'bets.id')
                    ->where('wallets.type', '!=', WalletType::Primary->value);
            })
            ->whereNotExists(function (QueryBuilder $sub): void {
                $sub->selectRaw('1')
                    ->from('financial_transactions')
                    ->join('wallets', 'wallets.id', '=', 'financial_transactions.wallet_id')
                    ->whereColumn('financial_transactions.reference_id', 'bets.id')
                    ->whereIn('financial_transactions.reference_type', [Bet::class, 'bet'])
                    ->where('wallets.type', '!=', WalletType::Primary->value);
            })
            ->select(['id', 'stake_amount', 'actual_payout', 'metadata'])
            ->lazyById(500);

        $totalStake = '0.00';
        $totalWinnings = '0.00';

        foreach ($bets as $bet) {
            if ($this->isExcludedMetadata($bet->metadata)) {
                continue;
            }

            $totalStake = bcadd($totalStake, (string) $bet->stake_amount, 2);
            $totalWinnings = bcadd($totalWinnings, (string) ($bet->actual_payout ?? '0.00'), 2);
        }

        return $this->calculator->netLossFromTotals($totalStake, $totalWinnings);
    }

    public function activeBoostBpsForMember(User|int $member, ?CarbonInterface $at = null): int
    {
        $claims = RewardClaim::query()
            ->activeCashbackBoosts($member, $at)
            ->get();

        $totalBoost = 0;
        foreach ($claims as $claim) {
            if ($claim->isBoostActiveAt($at)) {
                $totalBoost += $claim->extraBoostBps();
            }
        }

        return $totalBoost;
    }

    /**
     * Re-price every accrual whose period contained the cancelled/refunded bet.
     *
     * The period basis is recomputed from the bets that still count (the
     * cancelled bet is already excluded by the settled-status filter), so only
     * the bet's own contribution is removed, never the member's whole period.
     *
     *  - accrued rows: amount/basis are lowered in place, or the row is reversed
     *    when nothing is left.
     *  - claimed rows: the over-paid difference is clawed back from the wallet
     *    that received the claim (capped at its available balance); any part that
     *    cannot be recovered is recorded as clawback_shortfall and audited for
     *    manual review. Wallet writes go only through the Wallet service.
     *
     * @return int number of accrual rows changed
     */
    public function reverse(Bet $bet, string $reason = 'bet_cancelled_or_refunded'): int
    {
        $referenceTime = $this->settlementReferenceTime($bet);
        $affectedStatuses = [CashbackAccrualStatus::Accrued->value, CashbackAccrualStatus::Claimed->value];

        return DB::transaction(function () use ($bet, $referenceTime, $reason, $affectedStatuses): int {
            User::query()->whereKey($bet->user_id)->lockForUpdate()->firstOrFail();

            $accruals = CashbackAccrual::query()
                ->where('member_id', $bet->user_id)
                ->whereIn('status', $affectedStatuses)
                ->where('period_start', '<=', $referenceTime)
                ->where('period_end', '>=', $referenceTime)
                ->with('policy')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $affected = 0;

            foreach ($accruals as $accrual) {
                $newBasis = $this->settledRealMoneyNetLoss(
                    (int) $bet->user_id,
                    $accrual->period_start,
                    $accrual->period_end,
                );

                $meta = is_array($accrual->metadata) ? $accrual->metadata : [];
                $baseBps = (int) ($meta['base_rate_bps'] ?? $accrual->policy?->rate_bps ?? 0);
                $boostBps = (int) ($meta['boost_rate_bps'] ?? 0);
                $newAmount = $this->calculator->calculateAccrualAmount(
                    $newBasis,
                    $baseBps,
                    $boostBps,
                    (string) ($accrual->policy?->max_per_period ?? '0.00'),
                );

                // Entitlement already honoured = paid amount minus what earlier
                // reversals have taken back or recorded as a shortfall.
                $oldAmount = bcsub(
                    bcadd((string) $accrual->accrual_amount, '0.00', 2),
                    bcadd((string) ($accrual->clawback_amount ?? '0.00'), (string) ($accrual->clawback_shortfall ?? '0.00'), 2),
                    2,
                );
                if (bccomp($newAmount, $oldAmount, 2) >= 0) {
                    continue; // The bet did not contribute to this accrual.
                }

                $delta = bcsub($oldAmount, $newAmount, 2);

                if ($accrual->status === CashbackAccrualStatus::Accrued) {
                    $this->repriceUnclaimed($accrual, $bet, $newBasis, $newAmount, $oldAmount, $reason);
                } else {
                    $this->clawBackClaimed($accrual, $bet, $delta, $newAmount, $reason);
                }

                $affected++;
            }

            if ($affected > 0) {
                $this->forgetDashboardCache((int) $bet->user_id);
            }

            return $affected;
        });
    }

    /**
     * The instant that decides which accrual period a bet belongs to.
     *
     * Must be the same instant settledRealMoneyNetLoss() windows on, otherwise
     * reverse() would look for the bet's contribution in a different period
     * than the one that counted it: the completed_at of the bet's draw. When the
     * draw never completed (the bet was cancelled before settlement) no accrual
     * can contain it, so the older bet timestamps are only a harmless fallback;
     * reverse() recomputes the basis and skips any accrual that does not change.
     */
    private function settlementReferenceTime(Bet $bet): CarbonInterface
    {
        if ($bet->draw_id !== null) {
            $completedAt = Draw::query()->whereKey($bet->draw_id)->value('completed_at');

            if ($completedAt instanceof CarbonInterface) {
                return $completedAt;
            }

            if (is_string($completedAt) && $completedAt !== '') {
                return Carbon::parse($completedAt);
            }
        }

        return $bet->won_at ?? $bet->placed_at ?? $bet->created_at ?? Carbon::now();
    }

    private function repriceUnclaimed(
        CashbackAccrual $accrual,
        Bet $bet,
        string $newBasis,
        string $newAmount,
        string $oldAmount,
        string $reason,
    ): void {
        $fullyReversed = bccomp($newAmount, '0.00', 2) <= 0;

        $meta = is_array($accrual->metadata) ? $accrual->metadata : [];
        $meta['adjusted_after_bet_id'] = (int) $bet->getKey();
        $meta['previous_accrual_amount'] = $oldAmount;
        $accrual->metadata = $meta;

        if ($fullyReversed) {
            $accrual->status = CashbackAccrualStatus::Reversed;
            $accrual->reversed_at = Carbon::now();
            $accrual->reversal_reason = $reason;
        } else {
            $accrual->basis_amount = $newBasis;
            $accrual->accrual_amount = $newAmount;
        }
        $accrual->save();

        AuditLog::query()->create([
            'user_id' => (int) $bet->user_id,
            'action' => AuditAction::Reversal->value,
            'auditable_type' => CashbackAccrual::class,
            'auditable_id' => (int) $accrual->getKey(),
            'risk_level' => RiskLevel::Low->value,
            'description' => $fullyReversed
                ? 'Cashback accrual reversed after bet cancellation/refund.'
                : 'Cashback accrual re-priced after bet cancellation/refund.',
            'metadata' => [
                'bet_id' => (int) $bet->getKey(),
                'reason' => $reason,
                'previous_amount' => $oldAmount,
                'new_amount' => $newAmount,
            ],
        ]);
    }

    private function clawBackClaimed(
        CashbackAccrual $accrual,
        Bet $bet,
        string $delta,
        string $newAmount,
        string $reason,
    ): void {
        /** @var FinancialTransaction|null $claimTx */
        $claimTx = $accrual->claim_transaction_id !== null
            ? FinancialTransaction::query()->find($accrual->claim_transaction_id)
            : null;

        $recovered = '0.00';
        $debitTxId = null;
        $currency = Currency::from(strtoupper((string) config('rewards.currency', 'THB')));

        if ($claimTx instanceof FinancialTransaction) {
            $wallet = $this->walletLocks->lock((int) $claimTx->wallet_id);
            $currency = $wallet->currency instanceof Currency ? $wallet->currency : $currency;
            $available = $this->wallets->availableBalance($wallet)->toString();
            $recovered = bccomp($available, $delta, 2) >= 0 ? $delta : (bccomp($available, '0.00', 2) > 0 ? $available : '0.00');

            if (bccomp($recovered, '0.00', 2) > 0) {
                $tx = $this->wallets->debit(
                    wallet: $wallet,
                    amount: Money::of($recovered, $currency),
                    type: FinancialTransactionType::Adjustment,
                    idempotencyKey: $this->idempotency->deterministicKey(
                        'cashback-clawback',
                        sprintf('accrual:%d|bet:%d', (int) $accrual->getKey(), (int) $bet->getKey()),
                    ),
                    options: [
                        'description' => sprintf('Cashback clawback for cancelled bet #%d', (int) $bet->getKey()),
                        'reference_type' => CashbackAccrual::class,
                        'reference_id' => (int) $accrual->getKey(),
                        'metadata' => [
                            'source' => 'cashback_clawback',
                            'bet_id' => (int) $bet->getKey(),
                            'reason' => $reason,
                        ],
                    ],
                );
                $debitTxId = (int) $tx->getKey();
            }
        }

        $shortfall = bcsub($delta, $recovered, 2);

        $accrual->clawback_amount = bcadd((string) ($accrual->clawback_amount ?? '0.00'), $recovered, 2);
        $accrual->clawback_shortfall = bcadd((string) ($accrual->clawback_shortfall ?? '0.00'), $shortfall, 2);
        $accrual->clawback_transaction_id = $debitTxId ?? $accrual->clawback_transaction_id;
        $accrual->reversed_at = Carbon::now();
        $accrual->reversal_reason = $reason;
        $meta = is_array($accrual->metadata) ? $accrual->metadata : [];
        $meta['adjusted_after_bet_id'] = (int) $bet->getKey();
        $meta['entitled_amount_after_adjustment'] = $newAmount;
        $accrual->metadata = $meta;
        $accrual->save();

        AuditLog::query()->create([
            'user_id' => (int) $bet->user_id,
            'action' => AuditAction::Reversal->value,
            'auditable_type' => CashbackAccrual::class,
            'auditable_id' => (int) $accrual->getKey(),
            'risk_level' => bccomp($shortfall, '0.00', 2) > 0 ? RiskLevel::Medium->value : RiskLevel::Low->value,
            'description' => 'Claimed cashback clawed back after bet cancellation/refund.',
            'metadata' => [
                'bet_id' => (int) $bet->getKey(),
                'reason' => $reason,
                'over_paid' => $delta,
                'recovered' => $recovered,
                'shortfall' => $shortfall,
                'requires_manual_review' => bccomp($shortfall, '0.00', 2) > 0,
            ],
        ]);
    }

    public function expireDueAccruals(?CarbonInterface $now = null): int
    {
        $pointInTime = $now ?? Carbon::now();

        return DB::transaction(function () use ($pointInTime): int {
            $due = CashbackAccrual::query()
                ->where('status', CashbackAccrualStatus::Accrued->value)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', $pointInTime)
                ->lockForUpdate()
                ->get();

            $count = 0;
            foreach ($due as $accrual) {
                $accrual->status = CashbackAccrualStatus::Expired;
                $accrual->save();
                $this->forgetDashboardCache((int) $accrual->member_id);
                $count++;
            }

            return $count;
        });
    }

    private function previewAccrual(
        User $member,
        CashbackPolicy $policy,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
    ): ?CashbackAccrual {
        $basisAmount = $this->settledRealMoneyNetLoss($member, $periodStart, $periodEnd);
        if (bccomp($basisAmount, '0.00', 2) <= 0) {
            return null;
        }

        $boostBps = $this->activeBoostBpsForMember($member, $periodEnd);
        $accrualAmount = $this->calculator->calculateAccrualAmount(
            $basisAmount,
            (int) $policy->rate_bps,
            $boostBps,
            (string) $policy->max_per_period,
        );

        if (bccomp($accrualAmount, '0.00', 2) <= 0) {
            return null;
        }

        return new CashbackAccrual([
            'member_id' => (int) $member->getKey(),
            'policy_id' => (int) $policy->getKey(),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'basis_amount' => $basisAmount,
            'accrual_amount' => $accrualAmount,
            'status' => CashbackAccrualStatus::Accrued,
            'expires_at' => Carbon::instance($periodEnd->toDateTime())->addDays(max(1, (int) $policy->expiry_days)),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function isExcludedMetadata(?array $metadata): bool
    {
        if (! is_array($metadata)) {
            return false;
        }

        $fundingSource = strtolower((string) ($metadata['funding_source'] ?? $metadata['wallet_type'] ?? ''));
        if (in_array($fundingSource, ['bonus', 'free_bet', 'voucher'], true)) {
            return true;
        }

        return isset($metadata['free_bet_voucher_id']) && $metadata['free_bet_voucher_id'] !== null;
    }

    private function forgetDashboardCache(int $memberId): void
    {
        $this->dashboardCache->forget($memberId);
    }
}
