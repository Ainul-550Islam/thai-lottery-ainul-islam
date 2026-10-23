<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\DTOs\Agent\CommissionSettlementResult;
use App\Exceptions\FinancialException;

/**
 * Service settling accrued agent commissions and disbursing funds to agent wallets.
 */
class AgentCommissionSettlementService
{
    public function __construct(
        private readonly AgentCommissionPayoutService $payouts,
    ) {}

    /**
     * Settle all accrued commissions for a completed draw.
     *
     * @throws FinancialException
     */
    public function settleForDraw(int $drawId): CommissionSettlementResult
    {
        return $this->payouts->settle(drawId: $drawId);
    }

    /**
     * @throws FinancialException
     */
    public function settleDraw(int $drawId): CommissionSettlementResult
    {
        return $this->settleForDraw($drawId);
    }
}
