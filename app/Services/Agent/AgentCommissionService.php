<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\DTOs\Agent\CommissionSettlementResult;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\Bet;

/**
 * Universal Agent Commission Service Orchestrator.
 *
 * Coordinates authoritative calculation, accrual upon bet placement,
 * idempotent settlement upon draw completion, and reversals on bet cancellations.
 */
class AgentCommissionService
{
    public function __construct(
        public readonly CommissionCalculationService $calculator,
        public readonly AgentCommissionAccrualService $accrual,
        public readonly AgentCommissionSettlementService $settlement,
        public readonly AgentCommissionReversalService $reversal,
    ) {}

    /**
     * Calculate and accrue commission for a placed bet.
     *
     * Accrual is a receivable per bet: this returns the rows that now exist for it,
     * which is a list (empty for an unreferred sale) rather than a single nullable
     * row, because one sale can earn both a direct and a parent commission.
     *
     * @return list<AgentCommission>
     */
    public function accrueForBet(Bet $bet): array
    {
        return $this->accrual->accrueForBet($bet);
    }

    /**
     * Settle accrued commissions for a completed draw.
     */
    public function settleForDraw(int $drawId): CommissionSettlementResult
    {
        return $this->settlement->settleForDraw($drawId);
    }

    /**
     * Reverse commission associated with a cancelled/refunded bet.
     */
    /**
     * @return list<AgentCommission>
     */
    public function reverseForBet(Bet $bet, string $reason): array
    {
        return $this->reversal->reverseForBet($bet, $reason);
    }
}
