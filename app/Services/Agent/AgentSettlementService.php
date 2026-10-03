<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\DTOs\Agent\CommissionSettlementResult;
use App\Enums\AuditAction;
use App\Enums\CommissionStatus;
use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\RiskLevel;
use App\Exceptions\FinancialException;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\AuditLog;
use App\Models\Draw;
use App\Models\Wallet;
use App\Services\Finance\Money;
use App\Services\Finance\WalletLockService;
use App\Services\Finance\WalletService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Universal Agent Settlement and Payout Service.
 *
 * Handles settling accrued agent commissions per draw or agent-specific settlement requests,
 * balance validations, and wallet disbursements within ACID database transactions.
 */
class AgentSettlementService
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly WalletService $wallets,
        private readonly WalletLockService $walletLocks,
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
     * Settle outstanding accrued commissions for a specific agent.
     *
     * @return array{settled_count: int, total_amount: string, currency: string}
     */
    public function settleForAgent(Agent $agent, ?int $reviewerId = null): array
    {
        return DB::transaction(function () use ($agent, $reviewerId): array {
            $lockedAgent = Agent::query()->whereKey($agent->id)->lockForUpdate()->firstOrFail();

            if (! $lockedAgent->canEarnCommission()) {
                throw new InvalidArgumentException(sprintf('Agent %s is not active to receive commission settlements.', $lockedAgent->agent_code));
            }

            $commissions = AgentCommission::query()
                ->where('agent_id', $lockedAgent->id)
                ->whereIn('status', [CommissionStatus::Accrued, CommissionStatus::Calculated, CommissionStatus::Payable])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($commissions->isEmpty()) {
                return [
                    'settled_count' => 0,
                    'total_amount' => '0.00',
                    'currency' => ($lockedAgent->currency ?? Currency::THB)->value,
                ];
            }

            $settledCount = 0;
            $totalAmount = '0.00';
            $currency = $lockedAgent->currency ?? Currency::THB;

            foreach ($commissions as $commission) {
                $commissionCurrency = $commission->currency ?? $currency;
                $amount = (string) $commission->commission_amount;

                if (bccomp($amount, '0.00', 2) <= 0) {
                    continue;
                }

                $wallet = $this->resolveAndLockAgentWallet($lockedAgent, $commissionCurrency);
                $idempotencyKey = sprintf('agent-manual-settle-agent-%06d-comm-%06d', $lockedAgent->id, (int) $commission->id);

                $transaction = $this->wallets->credit(
                    wallet: $wallet,
                    amount: Money::of($amount, $commissionCurrency),
                    type: FinancialTransactionType::Commission,
                    idempotencyKey: $idempotencyKey,
                    options: [
                        'description' => sprintf('Manual commission settlement (Ref %s)', $commission->reference_number),
                        'reference_type' => AgentCommission::class,
                        'reference_id' => (int) $commission->id,
                        'metadata' => [
                            'agent_id' => $lockedAgent->id,
                            'agent_code' => $lockedAgent->agent_code,
                            'commission_id' => $commission->id,
                            'reviewer_id' => $reviewerId,
                        ],
                    ],
                );

                $commission->financial_transaction_id = (int) $transaction->getKey();
                $commission->status = CommissionStatus::Paid;
                $commission->paid_at = Carbon::now();
                $commission->save();

                $lockedAgent->total_commission_paid = bcadd((string) $lockedAgent->total_commission_paid, $amount, 2);
                $settledCount++;
                $totalAmount = bcadd($totalAmount, $amount, 2);
            }

            $lockedAgent->save();

            AuditLog::create([
                'user_id' => $reviewerId,
                'action' => AuditAction::Payout,
                'risk_level' => RiskLevel::Low,
                'auditable_type' => Agent::class,
                'auditable_id' => $lockedAgent->id,
                'description' => sprintf(
                    'Manually settled %d commission(s) for Agent %s totaling %s %s.',
                    $settledCount,
                    $lockedAgent->agent_code,
                    $totalAmount,
                    $currency->value
                ),
                'metadata' => [
                    'agent_id' => $lockedAgent->id,
                    'agent_code' => $lockedAgent->agent_code,
                    'settled_count' => $settledCount,
                    'total_amount' => $totalAmount,
                    'currency' => $currency->value,
                ],
            ]);

            return [
                'settled_count' => $settledCount,
                'total_amount' => $totalAmount,
                'currency' => $currency->value,
            ];
        });
    }

    private function resolveAndLockAgentWallet(Agent $agent, Currency $currency): Wallet
    {
        $wallet = Wallet::query()
            ->where('user_id', $agent->user_id)
            ->where('currency', $currency->value)
            ->orderBy('id')
            ->first();

        if (! $wallet instanceof Wallet) {
            throw FinancialException::withCode(
                'agent_wallet_not_found',
                sprintf('Agent %s (User #%d) has no wallet in %s to receive commission.', $agent->agent_code, $agent->user_id, $currency->value),
                ['agent_id' => $agent->id, 'currency' => $currency->value],
            );
        }

        return $this->walletLocks->lockForCredit((int) $wallet->getKey(), $currency);
    }
}
