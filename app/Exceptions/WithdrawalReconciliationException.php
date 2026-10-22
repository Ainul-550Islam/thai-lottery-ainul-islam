<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Stable non-sensitive refusal; never include evidence bodies or driver errors. */
final class WithdrawalReconciliationException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Withdrawal reconciliation refused: '.$reason.'.');
    }
}
