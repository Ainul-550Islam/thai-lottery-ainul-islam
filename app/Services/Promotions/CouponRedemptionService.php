<?php

declare(strict_types=1);

namespace App\Services\Promotions;

use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\WalletType;
use App\Exceptions\FinancialException;
use App\Models\PromoCoupon;
use App\Models\PromoCouponRedemption;
use App\Models\User;
use App\Services\Wallet\WalletService;
use App\Support\ResponsibleGamingGate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Atomic, ledger-backed promotional coupon redemption engine (Audit §D6.1).
 *
 * SAFETY & ACCOUNTING INVARIANTS
 * ------------------------------
 * 1. Self-excluded players are unconditionally refused before touching any row.
 * 2. Every eligibility check (active flag, schedule window, global redemption
 *    cap, and per-user cap) runs inside a single DB transaction holding a row
 *    lock on `promo_coupons`, eliminating double-spend race conditions.
 * 3. Monetary grants are calculated strictly with `bcmath` (zero float math),
 *    bounded by `conversion_tooling.coupons.max_grant_ceiling`, credited to
 *    `WalletType::Bonus` by default, and posted as `FinancialTransactionType::Bonus`
 *    so promotional funds never inflate `total_deposited`.
 */
final class CouponRedemptionService
{
    public function __construct(
        private readonly WalletService $wallets,
    ) {}

    public function normaliseCode(string $rawCode): string
    {
        return strtoupper(trim($rawCode));
    }

    /**
     * Redeem a promotional code for an authenticated player.
     *
     * @throws FinancialException
     */
    public function redeem(User $user, string $rawCode, ?string $ipAddress = null): PromoCouponRedemption
    {
        if (! (bool) config('conversion_tooling.coupons.enabled', true)) {
            throw FinancialException::withCode(
                'coupons_disabled',
                'Promotional code redemption is currently disabled.',
            );
        }

        if (ResponsibleGamingGate::isSelfExcluded($user)) {
            throw FinancialException::withCode(
                'self_excluded_promotional_block',
                'Promotional bonuses cannot be redeemed while a self-exclusion is active.',
                ['user_id' => $user->getKey()],
            );
        }

        $code = $this->normaliseCode($rawCode);
        $pattern = (string) config('conversion_tooling.coupons.code_pattern', '/^[A-Z0-9][A-Z0-9_-]{2,31}$/');

        if ($code === '' || preg_match($pattern, $code) !== 1) {
            throw FinancialException::withCode(
                'invalid_coupon_format',
                'Enter a valid promotional code.',
            );
        }

        return DB::transaction(function () use ($user, $code, $ipAddress): PromoCouponRedemption {
            /** @var PromoCoupon|null $coupon */
            $coupon = PromoCoupon::query()
                ->where('code', $code)
                ->lockForUpdate()
                ->first();

            if (! $coupon instanceof PromoCoupon) {
                throw FinancialException::withCode(
                    'coupon_not_found',
                    'This promotional code is invalid or expired.',
                );
            }

            $now = Carbon::now();

            if (! $coupon->is_active) {
                throw FinancialException::withCode(
                    'coupon_inactive',
                    'This promotional code is no longer active.',
                );
            }

            if ($coupon->starts_at !== null && $now->lt($coupon->starts_at)) {
                throw FinancialException::withCode(
                    'coupon_not_started',
                    'This promotional code is not yet open for redemption.',
                );
            }

            if ($coupon->expires_at !== null && $now->gte($coupon->expires_at)) {
                throw FinancialException::withCode(
                    'coupon_expired',
                    'This promotional code has expired.',
                );
            }

            if ($coupon->max_redemptions !== null && (int) $coupon->redemptions_count >= (int) $coupon->max_redemptions) {
                throw FinancialException::withCode(
                    'coupon_exhausted',
                    'This promotional code has reached its redemption limit.',
                );
            }

            $userRedemptionCount = PromoCouponRedemption::query()
                ->where('coupon_id', $coupon->getKey())
                ->where('user_id', $user->getKey())
                ->count();

            $perUserLimit = max(1, (int) $coupon->per_user_limit);
            if ($userRedemptionCount >= $perUserLimit) {
                throw FinancialException::withCode(
                    'coupon_already_redeemed',
                    'You have already redeemed this promotional code.',
                );
            }

            $currencyCode = strtoupper((string) ($coupon->currency ?: config('conversion_tooling.coupons.currency', 'THB')));
            $currency = Currency::from($currencyCode);

            $primaryWallet = $this->wallets->getOrCreateWallet($user, $currency, WalletType::Primary);
            $depositedTotal = bcadd((string) ($primaryWallet->total_deposited ?? '0.00'), '0', 2);
            $minDeposit = bcadd((string) ($coupon->min_deposit_total ?? '0.00'), '0', 2);

            if (bccomp($depositedTotal, $minDeposit, 2) < 0) {
                throw FinancialException::withCode(
                    'coupon_minimum_deposit_unmet',
                    sprintf('A qualifying deposit of at least %s %s is required for this code.', $minDeposit, $currency->value),
                    ['required' => $minDeposit, 'currency' => $currency->value],
                );
            }

            if ($coupon->first_deposit_only) {
                $priorFirstDepositPromo = PromoCouponRedemption::query()
                    ->where('user_id', $user->getKey())
                    ->get()
                    ->contains(function (PromoCouponRedemption $row): bool {
                        $linked = PromoCoupon::query()->find($row->coupon_id);

                        return $linked instanceof PromoCoupon && (bool) $linked->first_deposit_only;
                    });

                if ($priorFirstDepositPromo) {
                    throw FinancialException::withCode(
                        'first_deposit_bonus_already_claimed',
                        'A welcome deposit promotion has already been claimed on this account.',
                    );
                }
            }

            $grantAmount = $this->calculateGrantAmount($coupon, $depositedTotal);
            $ceiling = (string) config('conversion_tooling.coupons.max_grant_ceiling', '5000.00');

            if (bccomp($grantAmount, '0.00', 2) <= 0) {
                throw FinancialException::withCode(
                    'coupon_zero_grant',
                    'Calculated bonus grant must be greater than zero.',
                );
            }

            if (bccomp($grantAmount, $ceiling, 2) > 0) {
                throw FinancialException::withCode(
                    'coupon_exceeds_ceiling',
                    'Promotional grant exceeds the configured safety ceiling.',
                    ['grant' => $grantAmount, 'ceiling' => $ceiling],
                );
            }

            $targetType = $coupon->target_wallet_type === WalletType::Primary->value
                ? WalletType::Primary
                : WalletType::Bonus;

            $targetWallet = $this->wallets->getOrCreateWallet($user, $currency, $targetType);
            $sequence = $userRedemptionCount + 1;
            $idempotencyKey = sprintf('coupon:%d:user:%d:seq:%d', (int) $coupon->getKey(), (int) $user->getKey(), $sequence);

            $tx = $this->wallets->credit(
                wallet: $targetWallet,
                amount: $grantAmount,
                type: FinancialTransactionType::Bonus,
                idempotencyKey: $idempotencyKey,
                options: [
                    'description' => sprintf('Promo code %s redeemed (%s)', $coupon->code, $coupon->title),
                    'reference_type' => PromoCoupon::class,
                    'reference_id' => (int) $coupon->getKey(),
                    'metadata' => [
                        'coupon_code' => $coupon->code,
                        'discount_type' => $coupon->discount_type,
                        'target_wallet_type' => $targetType->value,
                    ],
                ],
            );

            $redemption = PromoCouponRedemption::query()->create([
                'coupon_id' => (int) $coupon->getKey(),
                'user_id' => (int) $user->getKey(),
                'wallet_id' => (int) $targetWallet->getKey(),
                'financial_transaction_id' => (int) $tx->getKey(),
                'idempotency_key' => $idempotencyKey,
                'granted_amount' => $grantAmount,
                'currency' => $currency->value,
                'ip_address' => $ipAddress,
                'redeemed_at' => $now,
            ]);

            $coupon->redemptions_count = (int) $coupon->redemptions_count + 1;
            $coupon->save();

            return $redemption;
        });
    }

    private function calculateGrantAmount(PromoCoupon $coupon, string $depositedTotal): string
    {
        $rawAmount = (string) $coupon->amount;

        if ($coupon->discount_type === 'percentage_match') {
            $computed = bcdiv(bcmul($depositedTotal, $rawAmount, 4), '100', 2);

            if ($coupon->max_bonus_amount !== null && bccomp($computed, (string) $coupon->max_bonus_amount, 2) > 0) {
                return bcadd((string) $coupon->max_bonus_amount, '0', 2);
            }

            return $computed;
        }

        return bcadd($rawAmount, '0', 2);
    }
}
