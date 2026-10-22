<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Exceptions\FinancialException;
use PDO;
use stdClass;
use WeakMap;

/**
 * Reject overlapping ledger invocations on the identical physical write PDO.
 *
 * This is a synchronous reentrancy fence, not a database or transaction lock.
 * Different service instances/connection wrappers cannot bypass the same-PDO
 * lease. Independent PDOs remain independent. The owning post releases in
 * finally; an unsuccessful acquisition never owns or clears another lease.
 */
final class LedgerPostingInvocationLease
{
    /** @var WeakMap<PDO, object>|null */
    private static ?WeakMap $active = null;

    private bool $released = false;

    private function __construct(private readonly PDO $pdo, private readonly object $token) {}

    public static function acquire(PDO $pdo): self
    {
        self::$active ??= new WeakMap;
        if (isset(self::$active[$pdo])) {
            throw FinancialException::withCode(
                'ledger_posting_reentrant',
                'A ledger posting is already active on this write handle.',
                ['stage' => 'posting_invocation'],
            );
        }
        $token = new stdClass;
        self::$active[$pdo] = $token;

        return new self($pdo, $token);
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;
        // A retained old lease must never release a later invocation's token.
        if (isset(self::$active[$this->pdo]) && self::$active[$this->pdo] === $this->token) {
            unset(self::$active[$this->pdo]);
        }
    }
}
