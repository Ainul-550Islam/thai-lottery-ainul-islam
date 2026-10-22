<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\Concerns;

use App\Enums\Currency;
use App\Services\Agent\AgentCommissionSettlementService;
use App\Services\Agent\AgentSettlementService;
use Illuminate\Support\Facades\DB;

/** Synthetic local contracts only; no provider, bank, or contractual approval. */
trait BuildsPaidCommissionCancellation
{
    use BuildsCommissionCancellation;

    protected function enablePolicy(): void
    {
        config(['agent.commission.paid_cancellation.enabled' => true,
            'agent.commission.paid_cancellation.policy_reference' => 'synthetic-local-agent-contract-v1']);
    }

    protected function paidFixture(Currency $currency = Currency::THB, bool $parent = true, bool $mixed = false): array
    {
        $this->enablePolicy();
        $f = $this->commissionFixture($currency, $parent);
        if ($mixed) {
            app(AgentSettlementService::class)->settleForAgent($f['agent']);
        } else {
            app(AgentCommissionSettlementService::class)->settleForDraw($f['draw']->id);
        }

        return $f;
    }

    protected function snapshot(): array
    {
        $out = [];
        foreach (['wallets', 'agents', 'agent_commissions', 'bet_purchase_ownerships', 'bet_commission_ownerships',
            'agent_commission_payouts', 'agent_commission_compensations', 'financial_transactions', 'ledger_entries', 'ledger_accounts',
            'number_limits', 'bets', 'tickets', 'audit_logs', 'financial_hold_claims'] as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $out;
    }
}
