<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * WalletFeedType — the player-facing vocabulary of the transaction feed.
 *
 * WHY A MAPPING AND NOT A NEW COLUMN
 * The ledger already records what happened: financial_transactions.type
 * (App\Enums\TransactionType, the storage vocabulary) and, one level up, the
 * engine vocabulary App\Enums\FinancialTransactionType. Neither is what a player
 * should read: a "Payout" is a win, "BetPlacement" is a bet, and a settlement is
 * not a movement at all.
 *
 * This enum is a TOTAL function over TransactionType. `fromTransactionType()`
 * has a branch for every case, so a future ledger type cannot silently vanish
 * from a player's history: the only way to add a case is to decide which feed
 * bucket it belongs to, and Batch3OwnershipTest fails if the mapping is not
 * total.
 *
 * WHAT IT IS NOT
 * It is not a ledger type, it is not stored anywhere and it does not change the
 * money meaning of a row. Two ledger rows can map to the same feed type (a
 * deposit and a Stripe refund both read as 'deposit'), which is correct: the
 * player is looking at their money, not at the accounting.
 */
enum WalletFeedType: string
{
    case Bet = 'bet';
    case Win = 'win';
    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';
    case Convert = 'convert';
    case Transfer = 'transfer';
    case Bonus = 'bonus';
    case Cashback = 'cashback';
    case Commission = 'commission';
    case Fee = 'fee';
    case Refund = 'refund';
    case Adjustment = 'adjustment';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Total mapping from the storage vocabulary.
     *
     * Every TransactionType case is named explicitly. There is no `default`
     * branch on purpose: adding a case to TransactionType makes this method a
     * compile-time error until somebody decides what players should see, which
     * is the behaviour we want, and a test asserts the totality too.
     */
    public static function fromTransactionType(TransactionType $type): self
    {
        return match ($type) {
            TransactionType::Deposit => self::Deposit,
            TransactionType::Withdrawal => self::Withdrawal,
            TransactionType::BetPlacement => self::Bet,
            TransactionType::BetRefund => self::Refund,
            TransactionType::Payout => self::Win,
            TransactionType::Commission => self::Commission,
            TransactionType::Settlement => self::Adjustment,
            TransactionType::Adjustment => self::Adjustment,
            TransactionType::Transfer => self::Transfer,
            TransactionType::Reversal => self::Refund,
        };
    }

    /**
     * Which direction money moved, from the player's point of view.
     *
     * Returns null when the direction is not implied by the type (a transfer or
     * an adjustment can go either way) — the feed then reads the SIGN of the
     * ledger entry instead of guessing, which is why this returns null instead
     * of a plausible default.
     */
    public function direction(): ?string
    {
        return match ($this) {
            self::Deposit, self::Win, self::Bonus, self::Cashback,
            self::Commission, self::Refund => 'credit',
            self::Bet, self::Withdrawal, self::Fee => 'debit',
            self::Convert, self::Transfer, self::Adjustment => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Bet => 'Bet placed',
            self::Win => 'Prize won',
            self::Deposit => 'Deposit',
            self::Withdrawal => 'Withdrawal',
            self::Convert => 'Conversion',
            self::Transfer => 'Transfer',
            self::Bonus => 'Bonus',
            self::Cashback => 'Cashback',
            self::Commission => 'Commission',
            self::Fee => 'Fee',
            self::Refund => 'Refund',
            self::Adjustment => 'Adjustment',
        };
    }

    /**
     * The filters the API accepts. Derived from the enum so the validator and
     * the enum can never drift.
     *
     * @return array<int, string>
     */
    public static function filterValues(): array
    {
        return self::values();
    }
}
