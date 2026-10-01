<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Currency;
use App\Enums\LedgerEntryType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Wallet Ledger Entry Model.
 *
 * Dedicated immutable ledger model representing financial movements
 * affecting user wallets, bound directly to double-entry ledger accounts
 * and financial transactions.
 */
class WalletLedger extends LedgerEntry
{
    protected $table = 'ledger_entries';

    protected static function booted(): void
    {
        static::addGlobalScope('wallet_entries_only', function (Builder $builder): void {
            $builder->whereNotNull('wallet_id');
        });
    }

    /**
     * Enforce immutability after creation.
     */
    public static function boot(): void
    {
        parent::boot();

        static::updating(function ($model): void {
            throw new \RuntimeException('Ledger entries are immutable and cannot be updated once posted.');
        });

        static::deleting(function ($model): void {
            throw new \RuntimeException('Ledger entries are immutable and cannot be deleted.');
        });
    }
}
