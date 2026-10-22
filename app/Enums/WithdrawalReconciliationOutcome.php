<?php

declare(strict_types=1);

namespace App\Enums;

/** Unknown, timeout, not-found and pending are deliberately NOT final evidence. */
enum WithdrawalReconciliationOutcome: string
{
    case Completed = 'completed';
    case DefinitivelyRejected = 'definitively_rejected';
}
