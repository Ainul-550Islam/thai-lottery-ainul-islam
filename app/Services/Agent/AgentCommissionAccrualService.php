<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\BetStatus;
use App\Enums\CommissionStatus;
use App\Enums\Currency;
use App\Enums\TransactionType;
use App\Exceptions\FinancialException;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\Bet;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Betting\BetAccountingProof;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Service handling commission accrual on placed bets.
 */
class AgentCommissionAccrualService
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly AgentReferralService $referrals,
        private readonly CommissionCalculationService $calculator,
    ) {}

    /**
     * Accrue commissions for a newly placed bet across the agent hierarchy.
     *
     * @return list<AgentCommission>
     */
    public function accrueForBet(Bet $bet): array
    {
        $insidePurchase = DB::transactionLevel() > 0;

        return DB::transaction(function () use ($bet, $insidePurchase): array {
            // Accrual is a receivable, not a fresh sale: running it again for the same
            // bet must return the rows that already exist. Without this the agent's
            // earned counter would be credited twice for one stake, and a later
            // cancellation would only be able to cancel the rows it can see.
            $existing = AgentCommission::query()
                ->where('bet_id', (int) $bet->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($existing->isNotEmpty()) {
                return $existing->all();
            }

            $owner = DB::table('bet_purchase_ownerships')->where('bet_id', (int) $bet->getKey())->lockForUpdate()->first();
            if ($owner) {
                // A committed, originally unattributed sale must remain so:
                // changing a referral later cannot manufacture a receivable.
                $emptyHash = hash('sha256', json_encode([], JSON_THROW_ON_ERROR));
                if ((int) $owner->commission_snapshot_count === 0 && is_string($owner->commission_snapshot_hash)
                    && hash_equals($emptyHash, $owner->commission_snapshot_hash)) {
                    return [];
                }
                $this->refuse();
            }
            if (! $insidePurchase || ! $bet->wasRecentlyCreated || $bet->status !== BetStatus::Active || ! $bet->placed_at) {
                $this->refuse();
            }
            // A fresh model flag is not money. Require this bet's original paid
            // journal, wallet and balanced ledger in the same transaction too.
            $journals = FinancialTransaction::withTrashed()->where('reference_type', Bet::class)
                ->where('reference_id', (int) $bet->getKey())->where('type', TransactionType::BetPlacement->value)
                ->limit(2)->lockForUpdate()->get();
            if ($journals->count() !== 1) {
                $this->refuse();
            }
            $journal = $journals->first();
            $wallet = Wallet::withTrashed()->whereKey((int) $journal->wallet_id)->lockForUpdate()->first();
            if (! $wallet instanceof Wallet) {
                $this->refuse();
            }
            app(BetAccountingProof::class)->transaction((int) $journal->id, $bet, $wallet);

            // The player is already locked by wager admission. Obtain current
            // referral facts, then lock the known agent hierarchy in id order.
            $user = User::query()->whereKey((int) $bet->user_id)->lockForUpdate()->first();
            $agentId = $user instanceof User ? ($user->preferences['referred_by_agent_id'] ?? null) : null;
            if (! is_numeric($agentId) || (int) $agentId <= 0) {
                return [];
            }
            $hint = Agent::withTrashed()->whereKey((int) $agentId)->first();
            $parentHint = $hint?->parent_agent_id === null ? null : (int) $hint->parent_agent_id;
            $ids = array_values(array_unique(array_filter([(int) $agentId, $parentHint])));
            sort($ids);
            $agents = Agent::withTrashed()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $directAgent = $agents->get((int) $agentId);
            if (! $directAgent instanceof Agent || $directAgent->trashed() || ! $directAgent->canEarnCommission()) {
                return [];
            }
            $currentParent = $directAgent->parent_agent_id === null ? null : (int) $directAgent->parent_agent_id;
            if ($currentParent !== $parentHint || $directAgent->currency !== $bet->currency) {
                $this->refuse(); // never add a late hierarchy lock or mix currencies
            }
            $parentAgent = $currentParent === null ? null : $agents->get($currentParent);
            if ($parentAgent instanceof Agent && ($parentAgent->trashed() || $parentAgent->currency !== $bet->currency)) {
                $this->refuse();
            }
            $book = app(AgentCommissionPayoutService::class);
            $book->assertBook($directAgent, (string) $directAgent->total_commission_earned, (string) $directAgent->total_commission_paid);
            if ($parentAgent instanceof Agent && $parentAgent->canEarnCommission()) {
                $book->assertBook($parentAgent, (string) $parentAgent->total_commission_earned, (string) $parentAgent->total_commission_paid);
            }

            $created = [];

            // 1. Level 1: Direct Agent Commission
            $directCalc = $this->calculator->calculate($bet, $directAgent, 1);

            if (bccomp($directCalc->commissionAmount, '0.00', 2) > 0) {
                $directCommission = $this->createCommissionRecord($directAgent, $bet, $directCalc->commissionRate, $directCalc->baseAmount, $directCalc->commissionAmount, 1);
                $created[] = $directCommission;

                $directAgent->total_commission_earned = bcadd((string) $directAgent->total_commission_earned, $directCalc->commissionAmount, 2);
                $directAgent->save();
            }

            // 2. Level 2: Parent Agent Hierarchy Commission (if configured & parent is active)
            $hierarchyEnabled = (bool) $this->config->get('agent.hierarchy.enabled', true);
            if ($hierarchyEnabled && $parentAgent instanceof Agent) {
                if ($parentAgent->canEarnCommission()) {
                    $parentCalc = $this->calculator->calculate($bet, $parentAgent, 2);

                    if (bccomp($parentCalc->commissionAmount, '0.00', 2) > 0) {
                        $parentCommission = $this->createCommissionRecord($parentAgent, $bet, $parentCalc->commissionRate, $parentCalc->baseAmount, $parentCalc->commissionAmount, 2);
                        $created[] = $parentCommission;

                        $parentAgent->total_commission_earned = bcadd((string) $parentAgent->total_commission_earned, $parentCalc->commissionAmount, 2);
                        $parentAgent->save();
                    }
                }
            }

            return $created;
        });
    }

    private function refuse(): never
    {
        throw FinancialException::withCode('commission_accrual_original_unproven',
            'Commission can only be established with its freshly funded purchase; no historical receivable was created.');
    }

    private function createCommissionRecord(
        Agent $agent,
        Bet $bet,
        string $rate,
        string $baseAmount,
        string $commissionAmount,
        int $hierarchyLevel,
    ): AgentCommission {
        $commission = new AgentCommission;
        $commission->fill([
            'reference_number' => $this->generateCommissionReferenceNumber(),
            'agent_id' => $agent->id,
            'user_id' => $bet->user_id,
            'bet_id' => $bet->id,
            'draw_id' => $bet->draw_id,
            'status' => CommissionStatus::Accrued,
            'currency' => $bet->currency ?? Currency::THB,
            'base_amount' => $baseAmount,
            'commission_rate' => $rate,
            'commission_amount' => $commissionAmount,
            'accrued_at' => Carbon::now(),
            'metadata' => [
                'hierarchy_level' => $hierarchyLevel,
                'bet_number' => $bet->bet_number,
                'draw_id' => $bet->draw_id,
            ],
        ]);
        $commission->save();

        return $commission;
    }

    private function generateCommissionReferenceNumber(): string
    {
        return sprintf(
            'COM-%s-%s',
            Carbon::now()->format('Ymd'),
            strtoupper(bin2hex(random_bytes(6))),
        );
    }
}
