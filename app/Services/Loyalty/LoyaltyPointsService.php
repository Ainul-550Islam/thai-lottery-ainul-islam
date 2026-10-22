<?php

declare(strict_types=1);

namespace App\Services\Loyalty;

use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\WalletType;
use App\Exceptions\FinancialException;
use App\Models\LoyaltyPointLedger;
use App\Models\LoyaltyRewardRedemption;
use App\Models\User;
use App\Services\Wallet\WalletService;
use App\Support\ResponsibleGamingGate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Append-only Loyalty Points & Bonus Reward Engine (Audit §D6.5).
 *
 * Bridges the tier definitions in `config/account_grades.php` (`bronze` ..
 * `diamond_plus`) to concrete point accrual, expiry sweeping, and atomic
 * redemption into `WalletType::Bonus`.
 *
 * INVARIANTS
 * ----------
 * 1. Self-excluded players neither earn promotional loyalty points nor redeem
 *    loyalty rewards.
 * 2. Every accrual and redemption carries a mandatory unique `idempotency_key`
 *    so settlement retries or double-clicked reward buttons never duplicate points.
 * 3. Reward redemptions lock the member's latest ledger entry (`lockForUpdate()`)
 *    inside the same DB transaction that credits `WalletType::Bonus`, making
 *    negative point balances impossible under concurrency.
 */
final class LoyaltyPointsService
{
    public const ENTRY_EARN = 'earn';

    public const ENTRY_REDEEM = 'redeem';

    public const ENTRY_EXPIRE = 'expire';

    public const ENTRY_ADJUST = 'adjust';

    public function __construct(
        private readonly WalletService $wallets,
    ) {}

    public function currentBalance(User|int $user): int
    {
        $userId = $user instanceof User ? (int) $user->getKey() : (int) $user;

        /** @var LoyaltyPointLedger|null $latest */
        $latest = LoyaltyPointLedger::query()
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->first();

        return $latest instanceof LoyaltyPointLedger ? (int) $latest->balance_after : 0;
    }

    /**
     * @return Collection<int, LoyaltyPointLedger>
     */
    public function recentHistory(User|int $user, int $limit = 20): Collection
    {
        $userId = $user instanceof User ? (int) $user->getKey() : (int) $user;

        return LoyaltyPointLedger::query()
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->limit(max(1, min(100, $limit)))
            ->get();
    }

    /**
     * @return array<string, array{name_key: string, points_cost: int, bonus_amount: string, enabled: bool}>
     */
    public function availableRewards(): array
    {
        /** @var array<string, array<string, mixed>> $configured */
        $configured = (array) config('conversion_tooling.loyalty.rewards', []);
        $active = [];

        foreach ($configured as $key => $reward) {
            if (! is_array($reward) || ! (bool) ($reward['enabled'] ?? false)) {
                continue;
            }

            $active[(string) $key] = [
                'name_key' => (string) ($reward['name_key'] ?? $key),
                'points_cost' => max(1, (int) ($reward['points_cost'] ?? 1)),
                'bonus_amount' => bcadd((string) ($reward['bonus_amount'] ?? '0.00'), '0', 2),
                'enabled' => true,
            ];
        }

        return $active;
    }

    public function multiplierForTier(string $tierKey): string
    {
        /** @var array<string, string> $multipliers */
        $multipliers = (array) config('conversion_tooling.loyalty.tier_multipliers', []);
        $normalised = strtolower(trim($tierKey));

        $raw = (string) ($multipliers[$normalised] ?? '1.00');

        return bccomp($raw, '0.00', 2) > 0 ? bcadd($raw, '0', 2) : '1.00';
    }

    /**
     * Calculate integer points earned for a settled stake using exact decimal math.
     */
    public function calculatePoints(string $stakeAmount, string $tierKey = 'bronze'): int
    {
        $unit = bcadd((string) config('conversion_tooling.loyalty.unit_stake_per_point', '10.00'), '0', 2);
        if (bccomp($unit, '0.00', 2) <= 0 || bccomp($stakeAmount, '0.00', 2) <= 0) {
            return 0;
        }

        $multiplier = $this->multiplierForTier($tierKey);
        $basePoints = bcdiv($stakeAmount, $unit, 4);
        $scaled = bcmul($basePoints, $multiplier, 0); // truncates toward zero cleanly

        return max(0, (int) $scaled);
    }

    /**
     * Award points for a settled wager (idempotent per settlement source).
     */
    public function awardForSettledWager(
        User $user,
        string $stakeAmount,
        string $idempotencyKey,
        string $tierKey = 'bronze',
        ?string $sourceType = null,
        ?string $sourceId = null,
    ): ?LoyaltyPointLedger {
        if (! (bool) config('conversion_tooling.loyalty.enabled', true)) {
            return null;
        }

        if (ResponsibleGamingGate::isSelfExcluded($user)) {
            return null;
        }

        $points = $this->calculatePoints($stakeAmount, $tierKey);
        if ($points <= 0) {
            return null;
        }

        return DB::transaction(function () use ($user, $points, $idempotencyKey, $tierKey, $stakeAmount, $sourceType, $sourceId): LoyaltyPointLedger {
            /** @var LoyaltyPointLedger|null $existing */
            $existing = LoyaltyPointLedger::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing instanceof LoyaltyPointLedger) {
                return $existing;
            }

            /** @var LoyaltyPointLedger|null $latest */
            $latest = LoyaltyPointLedger::query()
                ->where('user_id', $user->getKey())
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $previousBalance = $latest instanceof LoyaltyPointLedger ? (int) $latest->balance_after : 0;
            $newBalance = $previousBalance + $points;
            $expiryDays = max(1, (int) config('conversion_tooling.loyalty.point_expiry_days', 365));

            return LoyaltyPointLedger::query()->create([
                'user_id' => (int) $user->getKey(),
                'entry_type' => self::ENTRY_EARN,
                'points' => $points,
                'balance_after' => $newBalance,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'idempotency_key' => $idempotencyKey,
                'description' => sprintf('Earned %d pts on settled stake (%s tier)', $points, $tierKey),
                'expires_at' => Carbon::now()->addDays($expiryDays),
                'metadata' => [
                    'stake_amount' => bcadd($stakeAmount, '0', 2),
                    'tier_key' => $tierKey,
                    'multiplier' => $this->multiplierForTier($tierKey),
                ],
            ]);
        });
    }

    /**
     * Redeem points for a configured bonus wallet credit.
     *
     * @throws FinancialException
     */
    public function redeemReward(
        User $user,
        string $rewardKey,
        ?string $idempotencyKey = null,
    ): LoyaltyRewardRedemption {
        if (! (bool) config('conversion_tooling.loyalty.enabled', true)) {
            throw FinancialException::withCode(
                'loyalty_disabled',
                'The loyalty programme is currently unavailable.',
            );
        }

        if (ResponsibleGamingGate::isSelfExcluded($user)) {
            throw FinancialException::withCode(
                'self_excluded_promotional_block',
                'Loyalty bonus rewards cannot be redeemed while a self-exclusion is active.',
            );
        }

        $rewards = $this->availableRewards();
        if (! isset($rewards[$rewardKey])) {
            throw FinancialException::withCode(
                'loyalty_reward_not_found',
                'Selected loyalty reward is unavailable.',
            );
        }

        $reward = $rewards[$rewardKey];
        $pointsCost = $reward['points_cost'];
        $bonusAmount = $reward['bonus_amount'];

        return DB::transaction(function () use ($user, $rewardKey, $pointsCost, $bonusAmount, $idempotencyKey): LoyaltyRewardRedemption {
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                /** @var LoyaltyRewardRedemption|null $existing */
                $existing = LoyaltyRewardRedemption::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing instanceof LoyaltyRewardRedemption) {
                    return $existing;
                }
            }

            $now = Carbon::now();
            $dailyCap = max(1, (int) config('conversion_tooling.loyalty.max_daily_redemptions', 3));
            $todayCount = LoyaltyRewardRedemption::query()
                ->where('user_id', $user->getKey())
                ->where('redeemed_at', '>=', $now->copy()->startOfDay())
                ->count();

            if ($todayCount >= $dailyCap) {
                throw FinancialException::withCode(
                    'loyalty_daily_limit_reached',
                    'You have reached the maximum daily loyalty reward redemptions.',
                );
            }

            /** @var LoyaltyPointLedger|null $latest */
            $latest = LoyaltyPointLedger::query()
                ->where('user_id', $user->getKey())
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $currentBalance = $latest instanceof LoyaltyPointLedger ? (int) $latest->balance_after : 0;
            if ($currentBalance < $pointsCost) {
                throw FinancialException::withCode(
                    'insufficient_loyalty_points',
                    sprintf('You need %d points to redeem this reward (current balance: %d).', $pointsCost, $currentBalance),
                    ['required' => $pointsCost, 'available' => $currentBalance],
                );
            }

            $effectiveKey = ($idempotencyKey !== null && $idempotencyKey !== '')
                ? $idempotencyKey
                : sprintf('loyalty-redeem:user:%d:after:%d:reward:%s', (int) $user->getKey(), $latest?->getKey() ?? 0, $rewardKey);

            $newBalance = $currentBalance - $pointsCost;

            LoyaltyPointLedger::query()->create([
                'user_id' => (int) $user->getKey(),
                'entry_type' => self::ENTRY_REDEEM,
                'points' => -$pointsCost,
                'balance_after' => $newBalance,
                'source_type' => LoyaltyRewardRedemption::class,
                'source_id' => $rewardKey,
                'idempotency_key' => $effectiveKey.':ledger',
                'description' => sprintf('Redeemed %s (-%d pts)', $rewardKey, $pointsCost),
                'expires_at' => null,
                'metadata' => ['reward_key' => $rewardKey, 'bonus_amount' => $bonusAmount],
            ]);

            $currencyCode = strtoupper((string) config('conversion_tooling.loyalty.currency', 'THB'));
            $currency = Currency::from($currencyCode);
            $bonusWallet = $this->wallets->getOrCreateWallet($user, $currency, WalletType::Bonus);

            $tx = $this->wallets->credit(
                wallet: $bonusWallet,
                amount: $bonusAmount,
                type: FinancialTransactionType::Bonus,
                idempotencyKey: $effectiveKey.':wallet',
                options: [
                    'description' => sprintf('Loyalty reward %s (%d pts)', $rewardKey, $pointsCost),
                    'metadata' => [
                        'reward_key' => $rewardKey,
                        'points_spent' => $pointsCost,
                    ],
                ],
            );

            return LoyaltyRewardRedemption::query()->create([
                'user_id' => (int) $user->getKey(),
                'reward_key' => $rewardKey,
                'points_spent' => $pointsCost,
                'bonus_amount' => $bonusAmount,
                'currency' => $currency->value,
                'financial_transaction_id' => (int) $tx->getKey(),
                'idempotency_key' => $effectiveKey,
                'redeemed_at' => $now,
            ]);
        });
    }
}
