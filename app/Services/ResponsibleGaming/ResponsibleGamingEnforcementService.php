<?php

declare(strict_types=1);

namespace App\Services\ResponsibleGaming;

use App\Enums\DepositStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\ResponsibleGamingLimitType;
use App\Enums\SelfExclusionStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Exceptions\ResponsibleGamingLimitException;
use App\Exceptions\SelfExclusionException;
use App\Models\Deposit;
use App\Models\FinancialTransaction;
use App\Models\ResponsibleGamingLimit;
use App\Models\ResponsibleGamingLimitVersion;
use App\Models\SelfExclusion;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ResponsibleGamingEnforcementService — enforces player protection gates
 * before deposits or bets execute.
 */
class ResponsibleGamingEnforcementService
{
    private const SCALE = 2;

    public function __construct(
        private readonly SelfExclusionService $selfExclusion,
        private readonly ResponsibleGamingLimitService $limits,
    ) {}

    public function assertWithdrawalAllowed(User $user): void
    {
        // Deliberately permissive: the desk closes the door to play,
        // never to the player's own money leaving.
    }

    public function assertDepositAllowed(
        User $user,
        string $amount,
        string $currency = 'THB',
        ?int $replacingDepositId = null,
    ): void {
        $inTransaction = DB::transactionLevel() > 0;
        $normalizedCurrency = strtoupper(trim($currency));

        $this->assertNotSelfExcluded($user, $inTransaction);

        $depositTypes = [
            [ResponsibleGamingLimitType::DailyDeposit, 1],
            [ResponsibleGamingLimitType::WeeklyDeposit, 7],
            [ResponsibleGamingLimitType::MonthlyDeposit, 30],
        ];

        foreach ($depositTypes as [$type, $days]) {
            $cap = $this->limits->bindingLimitFor($user->id, $type, $normalizedCurrency, $inTransaction);

            if (! $cap instanceof ResponsibleGamingLimitVersion) {
                continue;
            }

            $windowDeposits = $this->rollingGrossDeposits(
                $user->id,
                $days,
                $normalizedCurrency,
                $replacingDepositId,
                $inTransaction,
            );
            $projected = bcadd($windowDeposits, $amount, self::SCALE);

            if (bccomp($projected, (string) $cap->amount, self::SCALE) > 0) {
                throw ResponsibleGamingLimitException::invalidAmount(
                    "Deposit {$amount} {$normalizedCurrency} exceeds {$type->value} ceiling {$cap->amount} (projected {$projected})"
                );
            }
        }
    }

    public function assertBetAllowed(User $user, string $stakeAmount, ?string $currency = null): void
    {
        $inTransaction = DB::transactionLevel() > 0;
        $resolvedCurrency = $currency !== null
            ? strtoupper(trim($currency))
            : strtoupper((string) config('betting.currency', 'THB'));

        $this->assertNotSelfExcluded($user, $inTransaction);

        // 1. Per-act ceilings: SingleBet and StakeLimit.
        foreach ([ResponsibleGamingLimitType::SingleBet, ResponsibleGamingLimitType::StakeLimit] as $perActType) {
            $perActCap = $this->limits->bindingLimitFor($user->id, $perActType, $resolvedCurrency, $inTransaction);

            if ($perActCap instanceof ResponsibleGamingLimitVersion
                && bccomp($stakeAmount, (string) $perActCap->amount, self::SCALE) > 0) {
                throw ResponsibleGamingLimitException::invalidAmount(
                    "Bet stake {$stakeAmount} {$resolvedCurrency} exceeds {$perActType->value} limit {$perActCap->amount}"
                );
            }
        }

        // 2. Rolling daily gross wagering ceiling.
        $dailyWageringCap = $this->limits->bindingLimitFor(
            $user->id,
            ResponsibleGamingLimitType::DailyWagering,
            $resolvedCurrency,
            $inTransaction,
        );

        if ($dailyWageringCap instanceof ResponsibleGamingLimitVersion) {
            $windowStaked = $this->rollingGrossWagering($user, 1, $resolvedCurrency, $inTransaction);
            $projectedWagering = bcadd($windowStaked, $stakeAmount, self::SCALE);

            if (bccomp($projectedWagering, (string) $dailyWageringCap->amount, self::SCALE) > 0) {
                throw ResponsibleGamingLimitException::invalidAmount(
                    "Bet stake {$stakeAmount} {$resolvedCurrency} breaches daily_wagering ceiling {$dailyWageringCap->amount} (projected {$projectedWagering})"
                );
            }
        }

        // 3. Rolling net loss ceilings (daily / weekly / monthly).
        $lossTypes = [
            [ResponsibleGamingLimitType::DailyLoss, 1],
            [ResponsibleGamingLimitType::WeeklyLoss, 7],
            [ResponsibleGamingLimitType::MonthlyLoss, 30],
        ];

        foreach ($lossTypes as [$type, $days]) {
            $cap = $this->limits->bindingLimitFor($user->id, $type, $resolvedCurrency, $inTransaction);

            if (! $cap instanceof ResponsibleGamingLimitVersion) {
                continue;
            }

            $windowLoss = $this->rollingLoss($user, $days, $resolvedCurrency, $inTransaction);
            $projected = bcadd($windowLoss, $stakeAmount, self::SCALE);

            if (bccomp($projected, (string) $cap->amount, self::SCALE) > 0) {
                throw ResponsibleGamingLimitException::invalidAmount(
                    "Bet stake {$stakeAmount} {$resolvedCurrency} breaches {$type->value} ceiling {$cap->amount} (projected {$projected})"
                );
            }
        }
    }

    /**
     * Gross completed stake volume in the rolling window across all player wallets in the currency.
     */
    public function rollingGrossWagering(
        User $user,
        int $days,
        string $currency = 'THB',
        bool $forUpdate = false,
    ): string {
        $normalizedCurrency = strtoupper(trim($currency));
        $now = Carbon::now();
        $since = $now->copy()->subDays($days);

        $query = FinancialTransaction::withTrashed()
            ->where('user_id', $user->id)
            ->where('currency', $normalizedCurrency)
            ->where('status', TransactionStatus::Completed->value)
            ->whereIn('type', [
                TransactionType::BetPlacement->value,
                FinancialTransactionType::BetDebit->value,
            ])
            ->where('created_at', '>=', $since)
            ->where('created_at', '<=', $now);

        if ($forUpdate) {
            $query->lockForUpdate();
        }

        $staked = '0.00';

        foreach ($query->pluck('amount') as $amt) {
            $staked = bcadd($staked, (string) $amt, self::SCALE);
        }

        return $staked;
    }

    /**
     * Net loss in the rolling window across all player wallets in the currency,
     * floored at 0.00.
     */
    public function rollingLoss(
        User $user,
        int $days,
        string $currency = 'THB',
        bool $forUpdate = false,
    ): string {
        $normalizedCurrency = strtoupper(trim($currency));
        $now = Carbon::now();
        $since = $now->copy()->subDays($days);

        $query = FinancialTransaction::withTrashed()
            ->where('user_id', $user->id)
            ->where('currency', $normalizedCurrency)
            ->where('status', TransactionStatus::Completed->value)
            ->whereIn('type', [
                TransactionType::BetPlacement->value,
                FinancialTransactionType::BetDebit->value,
                TransactionType::Payout->value,
                TransactionType::BetRefund->value,
                'win_credit',
                'prize_credit',
            ])
            ->where('created_at', '>=', $since)
            ->where('created_at', '<=', $now);

        if ($forUpdate) {
            $query->lockForUpdate();
        }

        $staked = '0.00';
        $won = '0.00';

        foreach ($query->get(['type', 'amount']) as $row) {
            $typeValue = $row->type instanceof \BackedEnum ? (string) $row->type->value : (string) $row->type;

            if (in_array($typeValue, [
                TransactionType::BetPlacement->value,
                FinancialTransactionType::BetDebit->value,
            ], true)) {
                $staked = bcadd($staked, (string) $row->amount, self::SCALE);
            } else {
                $won = bcadd($won, (string) $row->amount, self::SCALE);
            }
        }

        $net = bcsub($staked, $won, self::SCALE);

        return bccomp($net, '0.00', self::SCALE) > 0 ? $net : '0.00';
    }

    private function rollingGrossDeposits(
        int $userId,
        int $days,
        string $currency,
        ?int $replacingDepositId,
        bool $forUpdate,
    ): string {
        $now = Carbon::now();
        $since = $now->copy()->subDays($days);

        $query = Deposit::withTrashed()
            ->where('user_id', $userId)
            ->where('currency', $currency)
            ->whereIn('status', [
                DepositStatus::Pending->value,
                DepositStatus::Approved->value,
                DepositStatus::Processing->value,
                DepositStatus::Confirmed->value,
                DepositStatus::Refunded->value,
            ])
            ->where(static function ($q) use ($since, $now): void {
                $q->where(static function ($c) use ($since, $now): void {
                    $c->whereIn('status', [
                        DepositStatus::Confirmed->value,
                        DepositStatus::Refunded->value,
                    ])->where(static function ($ts) use ($since, $now): void {
                        $ts->where(static function ($p) use ($since, $now): void {
                            $p->whereNotNull('confirmed_at')
                                ->where('confirmed_at', '>=', $since)
                                ->where('confirmed_at', '<=', $now);
                        })->orWhere(static function ($np) use ($since, $now): void {
                            $np->whereNull('confirmed_at')
                                ->where('created_at', '>=', $since)
                                ->where('created_at', '<=', $now);
                        });
                    });
                })->orWhere(static function ($u) use ($since, $now): void {
                    $u->whereIn('status', [
                        DepositStatus::Pending->value,
                        DepositStatus::Approved->value,
                        DepositStatus::Processing->value,
                    ])
                        ->where('created_at', '>=', $since)
                        ->where('created_at', '<=', $now);
                });
            });

        if ($replacingDepositId !== null) {
            $query->where('id', '!=', $replacingDepositId);
        }

        if ($forUpdate) {
            $query->lockForUpdate();
        }

        $total = '0.00';
        foreach ($query->pluck('amount') as $amt) {
            $total = bcadd($total, (string) $amt, self::SCALE);
        }

        return $total;
    }

    private function assertNotSelfExcluded(User $user, bool $forUpdate): void
    {
        $legacyQuery = ResponsibleGamingLimit::withTrashed()->where('user_id', $user->id);
        if ($forUpdate) {
            $legacyQuery->lockForUpdate();
        }

        foreach ($legacyQuery->get() as $legacy) {
            if ($legacy->self_excluded_until !== null && $legacy->self_excluded_until->isFuture()) {
                throw SelfExclusionException::activeExclusion(
                    $user->id,
                    $legacy->self_excluded_until->toIso8601String(),
                );
            }

            if ($legacy->cool_off_until !== null && $legacy->cool_off_until->isFuture()) {
                throw SelfExclusionException::activeExclusion(
                    $user->id,
                    $legacy->cool_off_until->toIso8601String(),
                );
            }
        }

        $exclusionQuery = SelfExclusion::query()
            ->where('user_id', $user->id)
            ->where('status', SelfExclusionStatus::Active->value)
            ->where('ends_at', '>', now())
            ->orderByDesc('ends_at');

        if ($forUpdate) {
            $exclusionQuery->lockForUpdate();
        }

        /** @var SelfExclusion|null $active */
        $active = $exclusionQuery->first();

        if ($active instanceof SelfExclusion) {
            throw SelfExclusionException::activeExclusion(
                $user->id,
                $active->ends_at->toIso8601String(),
            );
        }
    }
}
