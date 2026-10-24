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
}
