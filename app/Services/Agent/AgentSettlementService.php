<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\DTOs\Agent\CommissionSettlementResult;
use App\Exceptions\FinancialException;
use App\Models\Agent;
use App\Models\Draw;

/**
 * Universal Agent Settlement and Payout Service.
 *
 * Handles settling accrued agent commissions per draw or agent-specific settlement requests,
 * balance validations, and wallet disbursements within ACID database transactions.
 */
class AgentSettlementService
{
    public function __construct(
        private readonly AgentCommissionPayoutService $payouts,
        private readonly AgentCommissionSettlementService $drawSettlement,
    ) {}

    /**
     * Settle all accrued commissions for a completed draw.
     *
     * @throws FinancialException
     */
    public function settleForDraw(int $drawId): CommissionSettlementResult
    {
        return $this->drawSettlement->settleForDraw($drawId);
    }

    /**
     * Alias of settleForDraw() accepting either a Draw model or its id —
     * the call shape most callers already hold a Draw for.
     */
    public function settleDrawCommissions(Draw|int $draw): CommissionSettlementResult
    {
        return $this->settleForDraw($draw instanceof Draw ? (int) $draw->getKey() : $draw);
    }

    /**
     * Settle outstanding accrued commissions for a specific agent.
     *
     * @return array{settled_count: int, total_amount: string, currency: string}
     */
    public function settleForAgent(Agent $agent, ?int $reviewerId = null): array
    {
        $result = $this->payouts->settle(agentId: (int) $agent->getKey(), reviewerId: $reviewerId);

        return [
            'settled_count' => $result->commissionsSettled,
            'total_amount' => $result->totalCommissionPaid,
            'currency' => $result->currency,
        ];
    }
}
