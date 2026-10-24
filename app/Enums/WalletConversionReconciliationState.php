<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The reconciliation verdict for one conversion row's ledger evidence. Used by
 * ReconcileConversionLedgerJob: Balanced means the posted entries match the
 * movement, Pending means the settling window has not elapsed, and Mismatched
 * means the books and the movement disagree and an operator must see it.
 */
enum WalletConversionReconciliationState: string
{
    case Pending = 'pending';
    case Balanced = 'balanced';
    case Mismatched = 'mismatched';

    public function isHealthy(): bool
    {
        return $this === self::Balanced;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Balanced => 'Balanced',
            self::Mismatched => 'Mismatched',
        };
    }

    public function labelKey(): string
    {
        return 'wallet_conversion.reconciliation_'.$this->value;
    }
}
