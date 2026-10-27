<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\DTOs\ResponsibleGaming\ResponsibleGamingLimitData;
use App\Enums\AuditAction;
use App\Enums\DepositStatus;
use App\Enums\ResponsibleGamingLimitType;
use App\Exceptions\ResponsibleGamingLimitException;
use App\Models\AuditLog;
use App\Models\Deposit;
use App\Models\ResponsibleGamingLimit;
use App\Models\User;
use App\Services\ResponsibleGaming\ResponsibleGamingLimitService;
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
     * Set or update player deposit / wagering / single-bet limits.
     *
     * ONE OPERATION, ONE TRANSACTION. Every submitted type is pronounced through the
     * versioned policy store first; a refusal there (a raise still inside its
     * cooling-off window, a protected player, a malformed amount) aborts the whole
     * call — the summary row and the audit line never land on their own. When a
     * caller submits several types and one of them is refused, nothing is kept.
     *
     * THE SUMMARY REPORTS WHAT BINDS, NOT WHAT WAS ASKED FOR. A raise becomes a
     * pending version, so the amount that governs the player's next deposit or
     * wager is still the active one; mirroring the requested amount would show a
     * ceiling that does not exist yet.
     */
    public function setLimits(
        User $user,
        ?string $dailyDepositLimit = null,
        ?string $singleBetLimit = null,
        ?string $dailyWageringLimit = null,
    ): ResponsibleGamingLimit {
        $currency = $this->bettingCurrency();

        return DB::transaction(function () use ($user, $dailyDepositLimit, $singleBetLimit, $dailyWageringLimit, $currency): ResponsibleGamingLimit {
            $submitted = [
                ResponsibleGamingLimitType::DailyDeposit->value => $this->normaliseAmount($dailyDepositLimit),
                ResponsibleGamingLimitType::SingleBet->value => $this->normaliseAmount($singleBetLimit),
                ResponsibleGamingLimitType::DailyWagering->value => $this->normaliseAmount($dailyWageringLimit),
            ];

            foreach ($submitted as $type => $amount) {
                if ($amount === null) {
                    continue;
                }

                $this->limitVersions->pronounce(ResponsibleGamingLimitData::fromInput([
                    'user_id' => (int) $user->getKey(),
                    'limit_type' => $type,
                    'amount' => $amount,
                    'currency' => $currency,
                ]));
            }

            $record = ResponsibleGamingLimit::query()
                ->where('user_id', (int) $user->getKey())
                ->lockForUpdate()
                ->first()
                ?? new ResponsibleGamingLimit(['user_id' => (int) $user->getKey()]);

            $record->daily_deposit_limit = $this->limitVersions->getActiveDailyDepositLimit((int) $user->getKey(), $currency)
                ?? $this->normaliseAmount($record->daily_deposit_limit);
            $record->single_bet_limit = $this->limitVersions->getActiveSingleBetLimit((int) $user->getKey(), $currency)
                ?? $this->normaliseAmount($record->single_bet_limit);
            $record->daily_wagering_limit = $this->limitVersions->getActiveDailyWageringLimit((int) $user->getKey(), $currency)
                ?? $this->normaliseAmount($record->daily_wagering_limit);

            $record->save();

            AuditLog::create([
                'user_id' => $user->getKey(),
                'action' => AuditAction::Update,
                'auditable_type' => ResponsibleGamingLimit::class,
                'auditable_id' => $record->id,
                'metadata' => [
                    'action_type' => 'responsible_gaming_limits_updated',
                    'currency' => $currency,
                    'requested' => $submitted,
                    'binding' => [
                        ResponsibleGamingLimitType::DailyDeposit->value => $record->daily_deposit_limit,
                        ResponsibleGamingLimitType::SingleBet->value => $record->single_bet_limit,
                        ResponsibleGamingLimitType::DailyWagering->value => $record->daily_wagering_limit,
                    ],
                ],
            ]);

            return $record;
        });
    }

    /**
     * The currency limits are declared and enforced in: the configured betting
     * currency, so a limit set in the player's own money cannot silently become a
     * THB ceiling against a USD wallet.
     */
    private function bettingCurrency(): string
    {
        $configured = (string) config('lottery.betting.currency', config('payment.currency.default', 'THB'));

        return strtoupper(trim($configured)) === '' ? 'THB' : strtoupper(trim($configured));
    }

    private function normaliseAmount(?string $amount): ?string
    {
        if ($amount === null) {
            return null;
        }

        $trimmed = trim($amount);

        if ($trimmed === '') {
            return null;
        }

        if (! is_numeric($trimmed)) {
            throw ResponsibleGamingLimitException::malformed('A limit amount must be a decimal number.');
        }

        return number_format((float) $trimmed, 2, '.', '');
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

        // Sum completed & pending deposits in last 24 hours
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
