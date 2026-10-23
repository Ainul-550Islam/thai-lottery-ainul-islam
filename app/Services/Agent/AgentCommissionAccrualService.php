<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\CommissionStatus;
use App\Enums\Currency;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\Bet;
use App\Models\User;
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
        return DB::transaction(function () use ($bet): array {
            $existing = AgentCommission::withTrashed()
                ->where('bet_id', $bet->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($existing->isNotEmpty()) {
                return $existing->all();
            }

            $user = User::query()->whereKey($bet->user_id)->lockForUpdate()->first();
            $directHint = $user instanceof User ? $this->referrals->resolveAgentForUser($user) : null;

            if (! $directHint instanceof Agent) {
                return [];
            }

            $hierarchyEnabled = (bool) $this->config->get('agent.hierarchy.enabled', true);
            $parentHintId = ($hierarchyEnabled && $directHint->parent_agent_id !== null)
                ? (int) $directHint->parent_agent_id
                : null;

            $agentIds = array_values(array_unique(array_filter([(int) $directHint->id, $parentHintId])));
            sort($agentIds, SORT_NUMERIC);

            $agents = Agent::withTrashed()
                ->select('agents.*')
                ->selectRaw('CAST(total_commission_earned AS CHAR) AS exact_earned, CAST(total_commission_paid AS CHAR) AS exact_paid')
                ->whereIn('id', $agentIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $directAgent = $agents->get((int) $directHint->id);
            $betCurrency = $bet->currency ?? Currency::THB;

            if (! $directAgent instanceof Agent || $directAgent->trashed() || ! $directAgent->canEarnCommission()) {
                return [];
            }

            if ($directAgent->currency !== $betCurrency
                || ($hierarchyEnabled && $directAgent->parent_agent_id !== $parentHintId)) {
                app(BetCommissionCancellation::class)->refuse();
            }

            $created = [];

            // 1. Level 1: Direct Agent Commission
            $directCalc = $this->calculator->calculate($bet, $directAgent, 1);

            if (bccomp($directCalc->commissionAmount, '0.00', 2) > 0) {
                $directCommission = $this->createCommissionRecord(
                    $directAgent,
                    $bet,
                    $directCalc->commissionRate,
                    $directCalc->baseAmount,
                    $directCalc->commissionAmount,
                    1,
                );
                $created[] = $directCommission;

                $newEarned = bcadd((string) $directAgent->exact_earned, $directCalc->commissionAmount, 2);
                DB::table('agents')->where('id', $directAgent->id)->update([
                    'total_commission_earned' => $newEarned,
                    'updated_at' => Carbon::now(),
                ]);
                $directAgent->total_commission_earned = $newEarned;
                $directAgent->setAttribute('exact_earned', $newEarned);
            }

            // 2. Level 2: Parent Agent Hierarchy Commission (if configured & parent is active)
            if ($parentHintId !== null) {
                $parentAgent = $agents->get($parentHintId);

                if (! $parentAgent instanceof Agent || $parentAgent->trashed()) {
                    app(BetCommissionCancellation::class)->refuse();
                }

                if ($parentAgent->canEarnCommission()) {
                    if ($parentAgent->currency !== $betCurrency) {
                        app(BetCommissionCancellation::class)->refuse();
                    }

                    $parentCalc = $this->calculator->calculate($bet, $parentAgent, 2);

                    if (bccomp($parentCalc->commissionAmount, '0.00', 2) > 0) {
                        $parentCommission = $this->createCommissionRecord(
                            $parentAgent,
                            $bet,
                            $parentCalc->commissionRate,
                            $parentCalc->baseAmount,
                            $parentCalc->commissionAmount,
                            2,
                        );
                        $created[] = $parentCommission;

                        $newParentEarned = bcadd((string) $parentAgent->exact_earned, $parentCalc->commissionAmount, 2);
                        DB::table('agents')->where('id', $parentAgent->id)->update([
                            'total_commission_earned' => $newParentEarned,
                            'updated_at' => Carbon::now(),
                        ]);
                        $parentAgent->total_commission_earned = $newParentEarned;
                        $parentAgent->setAttribute('exact_earned', $newParentEarned);
                    }
                }
            }

            return $created;
        });
    }

    /**
     * @return list<AgentCommission>
     */
    public function accrue(Bet $bet): array
    {
        return $this->accrueForBet($bet);
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
