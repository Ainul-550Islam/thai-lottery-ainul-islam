<?php

declare(strict_types=1);

namespace App\Services\ResponsibleGaming;

use App\Enums\DepositStatus;
use App\Enums\ResponsibleGamingLimitType;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Exceptions\ResponsibleGamingLimitException;
use App\Exceptions\SelfExclusionException;
use App\Models\Deposit;
use App\Models\FinancialTransaction;
use App\Models\ResponsibleGamingLimit;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Carbon;

/**
 * ResponsibleGamingEnforcementService — the central FAIL-CLOSED
 * facade consumed by betting / deposit / withdrawal flows.
 *
 * It answers one question per act: MAY this player perform this act
 * right now? The facade never implements money logic; it only reads
 * evidence (deposit rows, wallet-ledger transactions) and compares
 * with exact bcmath rules pronounced by the limit service. Every
 * refusal carries the desk's named code — never a bare boolean.
 */
class ResponsibleGamingEnforcementService
{
    public function __construct(
        private readonly SelfExclusionService $selfExclusions,
        private readonly ResponsibleGamingLimitService $limits,
    ) {}

    /**
     * THE GATE for betting: exclusion first, per-act stake ceilings,
     * periodic loss ceilings (evaluated over the rolling window with
     * the projected stake included).
     *
     * @throws SelfExclusionException|ResponsibleGamingLimitException
     */
    public function assertBetAllowed(User $user, string $stakeAmount, ?string $currency = 'THB'): void
    {
        $this->refuseIfExcluded($user);

        foreach ([ResponsibleGamingLimitType::SingleBet, ResponsibleGamingLimitType::StakeLimit] as $cap) {
            $binding = $this->limits->bindingLimitFor((int) $user->id, $cap, $currency);

            if ($binding !== null && bccomp($stakeAmount, (string) $binding->amount, 2) > 0) {
                throw ResponsibleGamingLimitException::invalidAmount(sprintf(
                    'stake %s exceeds the %s ceiling of %s %s',
                    $stakeAmount, $cap->label(), (string) $binding->amount, $binding->currency,
                ));
            }
        }

        foreach ([ResponsibleGamingLimitType::DailyWagering] as $cap) {
            $this->assertWageringHeadroom($user, $cap, $stakeAmount, $currency);
        }

        foreach ([ResponsibleGamingLimitType::DailyLoss, ResponsibleGamingLimitType::WeeklyLoss, ResponsibleGamingLimitType::MonthlyLoss] as $cap) {
            $this->assertLossHeadroom($user, $cap, $stakeAmount, $currency);
        }
    }

    /**
     * THE GATE for deposits: exclusion, then every periodic deposit
     * ceiling with the projected amount included.
     *
     * @throws SelfExclusionException|ResponsibleGamingLimitException
     */
    public function assertDepositAllowed(User $user, string $amount, ?string $currency = 'THB', ?int $excludeDepositId = null): void
    {
        $this->refuseIfExcluded($user);

        foreach ([
            ResponsibleGamingLimitType::DailyDeposit,
            ResponsibleGamingLimitType::WeeklyDeposit,
            ResponsibleGamingLimitType::MonthlyDeposit,
        ] as $cap) {
            $binding = $this->limits->bindingLimitFor((int) $user->id, $cap, $currency);

            if ($binding === null) {
                continue;
            }

            $days = (int) ($cap->rollingPeriodDays() ?? 1);
            $spent = $this->depositVolume((int) $user->id, $days, $currency, $excludeDepositId);
            $projected = bcadd($spent, $amount, 2);

            if (bccomp($projected, (string) $binding->amount, 2) > 0) {
                throw ResponsibleGamingLimitException::invalidAmount(sprintf(
                    'deposit %s would carry the %s window to %s, over the %s %s ceiling',
                    $amount, $cap->label(), $projected, (string) $binding->amount, $binding->currency,
                ));
            }
        }
    }

    /**
     * THE GATE for withdrawals: money OUT of the game is the player's
     * exit path — an active self-exclusion never stands in front of
     * it from the RG lane (wallet locks pronounced by compliance or
     * protection acts still gate independently of this facade).
     */
    public function assertWithdrawalAllowed(User $user): void
    {
        // Deliberately permissive: the desk closes the door to play,
        // never to the player's own money leaving.
    }

    /**
     * Post-hoc reconciliation read: the rolling loss of the window.
     */
    public function rollingLoss(User $user, int $days, ?string $currency = 'THB'): string
    {
        return $this->netLoss((int) $user->id, $days, $currency);
    }

    private function refuseIfExcluded(User $user): void
    {
        $active = $this->selfExclusions->currentActiveFor((int) $user->id);

        if ($active !== null) {
            throw SelfExclusionException::activeExclusion((int) $user->id, $active->ends_at->toIso8601String());
        }

        // The legacy lane. Before the self_exclusions table existed, a player's
        // protection was a pair of timestamps on responsible_gaming_limits, and the
        // active-exclusion lane still stamps self_excluded_until on that same row
        // (extending, never shortening). Reading only the new table would let anyone
        // who is protected through the legacy columns — including every row written by
        // an operator tool or an older release — place a bet. Both timestamps are read,
        // trashed rows count (deleting a live protection is not cancelling it), and a
        // value that cannot be parsed is treated as still running, because the safe
        // answer to "when does this end?" we cannot answer is "not yet".
        $legacy = ResponsibleGamingLimit::withTrashed()
            ->where('user_id', (int) $user->id)
            ->first(['id', 'self_excluded_until', 'cool_off_until']);

        foreach (['self_excluded_until', 'cool_off_until'] as $column) {
            $raw = $legacy?->getRawOriginal($column);

            if ($raw === null) {
                continue;
            }

            $until = $this->timestamp($raw);

            if ($until === null || $until->isFuture()) {
                throw SelfExclusionException::activeExclusion((int) $user->id, ($until ?? now())->toIso8601String());
            }
        }
    }

    /**
     * Strict reading of a stored timestamp. Anything unparseable yields null, which
     * callers treat as "the window is still open" rather than as an absence.
     */
    private function timestamp(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function assertLossHeadroom(User $user, ResponsibleGamingLimitType $cap, string $projectedStake, ?string $currency = 'THB'): void
    {
        $binding = $this->limits->bindingLimitFor((int) $user->id, $cap, $currency);

        if ($binding === null) {
            return;
        }

        $days = (int) ($cap->rollingPeriodDays() ?? 1);
        $loss = $this->netLoss((int) $user->id, $days, $currency);
        $projected = bcadd($loss, $projectedStake, 2);

        if (bccomp($projected, (string) $binding->amount, 2) > 0) {
            throw ResponsibleGamingLimitException::invalidAmount(sprintf(
                'stake %s would carry the %s window loss to %s, over the %s %s ceiling',
                $projectedStake, $cap->label(), $projected, (string) $binding->amount, $binding->currency,
            ));
        }
    }

    private function depositVolume(int $userId, int $days, ?string $currency = null, ?int $excludeDepositId = null): string
    {
        $query = Deposit::query()
            ->where('user_id', $userId)
            ->whereIn('status', [DepositStatus::completedCase(), DepositStatus::Pending])
            ->where('created_at', '>=', now()->subDays($days));

        if ($excludeDepositId !== null) {
            $query->whereKeyNot($excludeDepositId);
        }

        if ($currency !== null && $currency !== '') {
            $query->where('currency', $currency);
        }

        $sum = $query->sum('amount');

        return bcadd(sprintf('%0.2f', 0), (string) $sum, 2);
    }

    /**
     * Net loss over the rolling window against the wallet ledger:
     * completed stakes OUT minus completed winnings/refunds IN.
     * Read-only evidence — never arithmetic that moves money.
     */
    private function netLoss(int $userId, int $days, ?string $currency = null): string
    {
        $out = '0.00';

        foreach ($this->transactionsFor($userId, $days, $currency) as $tx) {
            $type = $tx->type instanceof TransactionType ? $tx->type : TransactionType::tryFrom((string) $tx->type);

            $out = match ($type) {
                TransactionType::BetPlacement => bcadd($out, (string) $tx->amount, 2),
                TransactionType::Payout, TransactionType::BetRefund => bcsub($out, (string) $tx->amount, 2),
                default => $out,
            };
        }

        return bccomp($out, '0', 2) < 0 ? '0.00' : $out;
    }

    /**
     * Wagering volume of the window: settled stake only, never netted.
     *
     * A refund gives a player their stake back but does not un-place the wager, so a
     * refunded bet still counts against a daily wagering ceiling. This is deliberately
     * NOT netLoss (which does subtract refunds and payouts): the two ceilings measure
     * different things and must not be computed from one another.
     */
    private function wageringVolume(int $userId, int $days, ?string $currency = null): string
    {
        $out = '0.00';

        foreach ($this->transactionsFor($userId, $days, $currency) as $tx) {
            $type = $tx->type instanceof TransactionType ? $tx->type : TransactionType::tryFrom((string) $tx->type);

            if ($type === TransactionType::BetPlacement) {
                $out = bcadd($out, (string) $tx->amount, 2);
            }
        }

        return $out;
    }

    /**
     * Completed money rows across EVERY wallet in the currency, including soft-deleted
     * ones.
     *
     * Every wallet counts because a player's stake on the bonus wallet is still stake.
     * Soft-deleted rows count because a financial transaction is a durable historical
     * fact: hiding it is not a way to restore headroom (the same rule the exclusion lane
     * applies to a deleted protection row).
     *
     * @return iterable<int, FinancialTransaction>
     */
    private function transactionsFor(int $userId, int $days, ?string $currency = null): iterable
    {
        $walletQuery = Wallet::withTrashed()->where('user_id', $userId);

        if ($currency !== null && $currency !== '') {
            $walletQuery->where('currency', $currency);
        }

        $walletIds = $walletQuery->pluck('id');

        if ($walletIds->isEmpty()) {
            return [];
        }

        return FinancialTransaction::withTrashed()
            ->whereIn('wallet_id', $walletIds->all())
            ->where('created_at', '>=', now()->subDays($days))
            ->where('status', TransactionStatus::Completed->value)
            ->orderBy('id')
            ->get(['id', 'type', 'amount', 'status'])
            ->all();
    }

    private function assertWageringHeadroom(User $user, ResponsibleGamingLimitType $cap, string $projectedStake, ?string $currency = 'THB'): void
    {
        $binding = $this->limits->bindingLimitFor((int) $user->id, $cap, $currency);

        if ($binding === null) {
            return;
        }

        $days = (int) ($cap->rollingPeriodDays() ?? 1);
        $wagered = $this->wageringVolume((int) $user->id, $days, $currency);
        $projected = bcadd($wagered, $projectedStake, 2);

        if (bccomp($projected, (string) $binding->amount, 2) > 0) {
            throw ResponsibleGamingLimitException::invalidAmount(sprintf(
                'stake %s would carry the %s window volume to %s, over the %s %s ceiling',
                $projectedStake, $cap->label(), $projected, (string) $binding->amount, $binding->currency,
            ));
        }
    }
}
