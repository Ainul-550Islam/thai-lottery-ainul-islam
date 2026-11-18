<?php

declare(strict_types=1);

namespace App\Services\BetSlip;

use RuntimeException;
use Throwable;

/**
 * A refusal that carries its own reason code and HTTP status.
 *
 * WHY AN EXCEPTION AND NOT A RESULT OBJECT
 * Every refusal in this lane is absolute: there is no partial success, the
 * caller has nothing to merge and nothing to retry differently. Throwing means
 * the service cannot accidentally return "the slip" after a refusal (the bug
 * that produces an empty slip and a 200), and the API layer maps the code to a
 * stable machine-readable error without a switch over message strings.
 *
 * The message is member-facing prose: it says what was wrong and what is
 * allowed. The code is stable and is what clients may branch on.
 */
final class BetSlipRefused extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message,
        public readonly int $status = 422,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'error' => 'bet_slip_refused',
            'reason' => $this->reasonCode,
            'message' => $this->getMessage(),
        ];
    }
}
