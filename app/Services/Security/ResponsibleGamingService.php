<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\DTOs\ResponsibleGaming\ResponsibleGamingLimitData;
use App\Enums\AuditAction;
use App\Enums\DepositStatus;
use App\Enums\ResponsibleGamingLimitType;
use App\Models\AuditLog;
use App\Models\Deposit;
use App\Models\ResponsibleGamingLimit;
use App\Models\User;
use App\Services\ResponsibleGaming\ResponsibleGamingLimitService;
use App\Services\ResponsibleGaming\ResponsibleGamingPlayerLock;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Responsible Gaming Limits & Player Self-Exclusion Enforcement Service.
 */
final class ResponsibleGamingService
{
    public function __construct(
        private readonly ResponsibleGamingLimitService $limitVersions,
    ) {}

    /**
     * Set or update player deposit / wagering / single-bet limits atomically
     * in the configured betting currency.
     */
    public function setLimits(
        User $user,
        ?string $dailyDepositLimit = null,
        ?string $singleBetLimit = null,
        ?string $dailyWageringLimit = null,
    ): ResponsibleGamingLimit {
        $currency = strtoupper((string) config('lottery.betting.currency', config('betting.currency', 'THB')));

        return DB::transaction(function () use ($user, $dailyDepositLimit, $singleBetLimit, $dailyWageringLimit, $currency): ResponsibleGamingLimit {
            app(ResponsibleGamingPlayerLock::class)->lock((int) $user->id);

            $record = ResponsibleGamingLimit::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first() ?? new ResponsibleGamingLimit(['user_id' => $user->id]);

            $choices = [
                [ResponsibleGamingLimitType::DailyDeposit, 'daily_deposit_limit', $dailyDepositLimit],
                [ResponsibleGamingLimitType::SingleBet, 'single_bet_limit', $singleBetLimit],
                [ResponsibleGamingLimitType::DailyWagering, 'daily_wagering_limit', $dailyWageringLimit],
            ];

            foreach ($choices as [$type, $column, $amount]) {
                if ($amount === null || $amount === '') {
                    continue;
                }

                $this->limitVersions->choose(ResponsibleGamingLimitData::fromInput([
                    'user_id' => (int) $user->id,
                    'limit_type' => $type->value,
                    'amount' => $amount,
                    'currency' => $currency,
                ]));

                $binding = $this->limitVersions->bindingLimitFor((int) $user->id, $type, $currency, true);
                $record->{$column} = $binding !== null ? (string) $binding->amount : $amount;
            }

            $record->save();

            AuditLog::create([
                'user_id' => $user->id,
                'action' => AuditAction::Update,
                'auditable_type' => ResponsibleGamingLimit::class,
                'auditable_id' => $record->id,
                'metadata' => [
                    'action_type' => 'responsible_gaming_limits_updated',
                    'currency' => $currency,
                    'daily_deposit_limit' => $record->daily_deposit_limit,
                    'single_bet_limit' => $record->single_bet_limit,
                    'daily_wagering_limit' => $record->daily_wagering_limit,
                ],
            ]);

            return $record;
        });
    }

    /**
     * Request player self-exclusion for a specified duration in days (e.g. 7, 30, 90, 365).
     */
    public function selfExclude(User $user, int $days, ?string $reason = null): ResponsibleGamingLimit
    {
        if ($days < 1) {
            throw new InvalidArgumentException('Self-exclusion duration must be at least 1 day.');
        }

        $record = ResponsibleGamingLimit::firstOrNew(['user_id' => $user->id]);
        $record->self_excluded_until = now()->addDays($days);
        $record->self_exclusion_reason = $reason;
        $record->save();

        AuditLog::create([
            'user_id' => $user->id,
            'action' => AuditAction::Update,
            'auditable_type' => ResponsibleGamingLimit::class,
            'auditable_id' => $record->id,
            'metadata' => [
                'action_type' => 'player_self_excluded',
                'days' => $days,
                'self_excluded_until' => $record->self_excluded_until->toIso8601String(),
                'reason' => $reason,
            ],
        ]);

        return $record;
    }

    /**
     * Assert player has not breached daily deposit limits.
     *
     * @throws InvalidArgumentException
     */
    public function assertDepositAllowed(User $user, string $amount): void
    {
        $limits = $user->responsibleGamingLimit;

        if ($limits === null || $limits->daily_deposit_limit === null) {
            return;
        }

        $limit = $limits->daily_deposit_limit;

        $todayDeposits = Deposit::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [DepositStatus::completedCase(), DepositStatus::Pending])
            ->where('created_at', '>=', now()->subDay())
            ->sum('amount');

        $projectedTotal = bcadd((string) $todayDeposits, $amount, 2);

        if (bccomp($projectedTotal, $limit, 2) === 1) {
            throw new InvalidArgumentException(sprintf(
                'Deposit exceeds your configured daily deposit limit of %s THB.',
                $limit
            ));
        }
    }

    /**
     * Assert bet amount is within player single-bet limits.
     *
     * @throws InvalidArgumentException
     */
    public function assertBetAllowed(User $user, string $stakeAmount): void
    {
        if ($user->isSelfExcluded()) {
            throw new InvalidArgumentException('Account is currently in self-exclusion or cool-off period.');
        }

        $limits = $user->responsibleGamingLimit;

        if ($limits === null) {
            return;
        }

        if ($limits->single_bet_limit !== null && bccomp($stakeAmount, $limits->single_bet_limit, 2) === 1) {
            throw new InvalidArgumentException(sprintf(
                'Bet stake exceeds your configured single bet limit of %s THB.',
                $limits->single_bet_limit
            ));
        }
    }
}
