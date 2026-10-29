<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A legacy LotteryEntry writer cannot prove the canonical draw-result and
 * settlement invariants. Do not convert existing entries into Bets implicitly:
 * the two products have different pricing, eligibility and ledger semantics.
 */
final class LegacyLotteryEntryException extends RuntimeException
{
    public static function unavailable(string $operation): self
    {
        return new self(sprintf(
            'Legacy lottery-entry %s is unavailable until an audited migration and settlement path exists. Existing entries must be reconciled; no automatic payout or conversion was performed.',
            $operation,
        ));
    }
}
