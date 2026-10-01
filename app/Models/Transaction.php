<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Currency;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Builder;

/**
 * Transaction Domain Model.
 *
 * Canonical representation of financial transaction movements linking
 * payment intents, deposits, withdrawals, bets, and double-entry ledger journals.
 */
class Transaction extends FinancialTransaction
{
    protected $table = 'financial_transactions';

    /**
     * Prevent mutation of monetary values after completion.
     */
    public static function boot(): void
    {
        parent::boot();

        static::updating(function (Transaction $transaction): void {
            if ($transaction->getOriginal('status') === TransactionStatus::Completed->value
                && $transaction->isDirty(['amount', 'fee', 'currency', 'wallet_id', 'user_id'])) {
                throw new \RuntimeException('Completed transactions cannot mutate amount, fee, currency, or ownership.');
            }
        });
    }
}
