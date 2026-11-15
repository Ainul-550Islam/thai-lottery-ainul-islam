<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * WHAT PAID FOR A BET'S STAKE.
 *
 *   real      the player's own withdrawable balance. Subject to the real-money
 *             admission gate (App\Services\Compliance\RealMoneyGate), to the
 *             wallet/ledger purchase path, and to cashback accrual.
 *   bonus     promotional credit that is not withdrawable. Purchased through the
 *             same wallet/ledger path as real money — the ledger records which
 *             pot moved — and excluded from the cashback basis.
 *   free_bet  a voucher-funded stake. No player money enters the ledger; the
 *             voucher is consumed by the funding adapter and reversed if the bet
 *             is later cancelled, in that order (release exposure first, then
 *             reverse the voucher).
 *
 * WHY THIS IS AN ENUM AND NOT A STRING COLUMN
 * Bets written before this batch have NO funding_source value at all: the column
 * is added nullable and is left NULL on every existing row. Such a row was
 * bought with real money, because nothing else existed, so of() maps null to
 * Real. That mapping is the one place where the legacy meaning is written down.
 * Every other reader goes through of() and therefore cannot disagree with it.
 *
 * WHY free_bet IS NOT bonus
 * A bonus stake is money the operator already granted and can claw back through
 * the wallet. A free bet is an entitlement to place one bet, consumed on
 * placement; it must be reversed, not credited, when the bet is cancelled, and
 * it changes the effective payout (a free-bet win normally does not return the
 * stake — see PayoutCalculator, which reads
 * config('lottery_games.funding.free_bet_returns_stake') instead of assuming).
 *
 * NO MONEY LIVES HERE
 * These cases carry no amounts, no multipliers and no wallet names. The wallet
 * and ledger services own movement; this enum only names the pot.
 */
enum BetFundingSource: string
{
    case Real = 'real';
    case Bonus = 'bonus';
    case FreeBet = 'free_bet';

    /**
     * Human readable name for admin screens and audit records.
     */
    public function label(): string
    {
        return match ($this) {
            self::Real => 'Real money',
            self::Bonus => 'Bonus credit',
            self::FreeBet => 'Free bet voucher',
        };
    }

    /**
     * Whether the stake came out of the player's withdrawable balance.
     *
     * Real only. Bonus and free-bet stakes are excluded from the cashback basis
     * and do not trigger the real-money admission gate on their own.
     */
    public function usesRealMoney(): bool
    {
        return $this === self::Real;
    }

    /**
     * Whether a cancelled bet must hand an entitlement back to the player rather
     * than credit a balance.
     *
     * True for FreeBet only, and it is why GameBetPlacementService::unwind()
     * releases the number exposure BEFORE asking the voucher provider to reverse:
     * if the voucher reversal fails, the exposure must already be gone or the
     * operator's liability limit stays consumed by a bet that no longer exists.
     */
    public function requiresVoucherReversal(): bool
    {
        return $this === self::FreeBet;
    }

    /**
     * Whether a stake from this source counts towards the Batch-1 cashback basis.
     *
     * The excluded list is operator policy and is read from
     * config('lottery_games.funding.cashback_basis_excludes_funding_sources')
     * by App\Listeners\Cashback\ReverseCashbackOnBetCancelled. This method takes
     * that list as an argument so the enum never becomes a second policy source.
     *
     * @param  list<string>  $excludedValues
     */
    public function participatesInCashbackBasis(array $excludedValues): bool
    {
        return ! in_array($this->value, $excludedValues, true);
    }

    /**
     * Resolve a stored, configured or submitted value without throwing.
     *
     * Null and unrecognised values resolve to Real, which is the safe reading of
     * a pre-batch-2 row and the only source that requires the real-money gate
     * and full wallet movement. Treating an unknown source as free_bet would
     * hand out stakes nobody paid for.
     */
    public static function of(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_string($value)) {
            return self::tryFrom(trim($value)) ?? self::Real;
        }

        return self::Real;
    }

    /**
     * Every value, for validation rules that must not restate the enum.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
