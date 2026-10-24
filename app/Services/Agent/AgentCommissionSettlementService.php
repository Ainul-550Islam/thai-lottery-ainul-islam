<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\DTOs\Agent\CommissionSettlementResult;
use App\Exceptions\FinancialException;

/**
 * Settlement entry point for accrued agent commissions.
 *
 * A commission payout is a proof-bearing financial fact: it needs its sealed receipt,
 * its ownership claim and its reversible original credit. Exactly one writer may create
 * that fact, so this historical entry point no longer pays commissions itself — it
 * delegates to the owning payout writer instead of producing a second, unsealed credit
 * that nothing could ever compensate or reverse.
 */
class AgentCommissionSettlementService
{
    public function __construct(private readonly AgentCommissionPayoutService $payouts) {}

    /**
     * Settle all accrued commissions for a completed draw.
     *
     * @throws FinancialException
     */
    public function settleForDraw(int $drawId): CommissionSettlementResult
    {
        return $this->payouts->settle(drawId: $drawId);
    }
}
