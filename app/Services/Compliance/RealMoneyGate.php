<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Exceptions\FinancialException;
use Illuminate\Config\Repository;

/**
 * The real-money launch gate.
 *
 * One switch (`real_money.enabled`, env REAL_MONEY_ENABLED) decides whether
 * this deployment may move real money at all: deposits, withdrawals and GLO
 * prize payments. It defaults CLOSED, so a fresh deployment refuses money
 * movement until an operator deliberately opens it after licensing is
 * confirmed.
 *
 * EXACTLY TRUE, NOTHING ELSE
 * `isOpen()` accepts only the boolean `true`. A truthy string ('yes', '1')
 * arriving through a mis-typed config override must not open a money gate —
 * configuration mistakes fail closed here, by design and by test.
 *
 * FAILING CLOSED MEANS MOVING NOTHING
 * `assertOpen()` throws BEFORE any balance is read or written, so a refused
 * operation leaves balances, locks and ledger exactly as it found them.
 */
final class RealMoneyGate
{
    public function __construct(private readonly Repository $config)
    {
    }

    public function isOpen(): bool
    {
        return $this->config->get('real_money.enabled', false) === true;
    }

    /**
     * @throws FinancialException real_money_disabled when the gate is closed
     */
    public function assertOpen(string $operation = 'real_money'): void
    {
        if (! $this->isOpen()) {
            throw FinancialException::withCode(
                'real_money_disabled',
                'Real-money operations are disabled on this deployment.',
                ['operation' => $operation],
            );
        }
    }
}
