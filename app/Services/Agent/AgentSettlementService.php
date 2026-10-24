<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\DTOs\Agent\CommissionSettlementResult;
use App\Exceptions\FinancialException;
use App\Models\Agent;
use App\Models\Draw;

/**
 * Universal Agent Settlement entry point.
 *
 * Every payout shape (per draw or per agent) is a proof-bearing financial fact, so both
 * delegate to the single owning writer. Keeping one writer is what lets a cancellation
 * compensate an already-paid commission: only that writer seals the receipt such a
 * compensation must prove against.
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
     * Alias of settleForDraw() accepting either a Draw model or its id — the call shape
     * most callers already hold a Draw for.
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
