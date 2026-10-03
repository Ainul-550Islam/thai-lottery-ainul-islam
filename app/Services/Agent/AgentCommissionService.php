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
     */
    public function accrueForBet(Bet $bet): ?AgentCommission
    {
        return $this->accrual->accrue($bet);
    }

    /**
     * Settle accrued commissions for a completed draw.
     */
    public function settleForDraw(int $drawId): CommissionSettlementResult
    {
        return $this->settlement->settleDraw($drawId);
    }

    /**
     * Reverse commission associated with a cancelled/refunded bet.
     */
    public function reverseForBet(Bet $bet, string $reason): ?AgentCommission
    {
        return $this->reversal->reverseForBet($bet, $reason);
    }
}
