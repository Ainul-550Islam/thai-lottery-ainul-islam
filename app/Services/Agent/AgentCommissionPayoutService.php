<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\DTOs\Agent\CommissionSettlementResult;
use App\Enums\AuditAction;
use App\Enums\CommissionStatus;
use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\RiskLevel;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\AuditLog;
use App\Models\Draw;
use App\Models\Wallet;
use App\Services\Finance\Money;
use App\Services\Finance\WalletLockService;
use App\Services\Finance\WalletService;
use Illuminate\Support\Facades\DB;

/** Shared internal draw/manual writer. No new route, reviewer authority or provider operation. */
final class AgentCommissionPayoutService
{
    public function __construct(private readonly AgentCommissionPayoutProof $proof,
        private readonly WalletLockService $locks, private readonly WalletService $wallets) {}

    /** One transaction and one currency; never add amounts denominated in different currencies. */
    public function settle(?int $drawId = null, ?int $agentId = null, ?int $reviewerId = null): CommissionSettlementResult
    {
        Money::assertExactArithmeticIsAvailable();
        if (($drawId === null) === ($agentId === null)) {
            $this->proof->refuse();
        }

        return DB::transaction(function () use ($drawId, $agentId, $reviewerId): CommissionSettlementResult {
            $scope = fn () => $this->proof->rows()->when($drawId !== null, fn ($q) => $q->where('draw_id', $drawId))
                ->when($agentId !== null, fn ($q) => $q->where('agent_id', $agentId))
                ->whereNotIn('status', [CommissionStatus::Cancelled, CommissionStatus::Reversed])->orderBy('id');
            // Ordinary reads ONLY route locks. Missing/stale routing refuses;
            // it never authorizes payment or causes a late unsorted wallet lock.
            $hints = $scope()->get();
            $routes = [];
            $walletIds = [];
            $agentIds = $hints->pluck('agent_id')->map(fn ($id): int => (int) $id)->all();
            if ($agentId !== null) {
                $agentIds[] = $agentId;
            }
            foreach ($hints as $hint) {
                $agent = Agent::withTrashed()->find($hint->agent_id);
                $purchase = DB::table('bet_purchase_ownerships')->where('bet_id', $hint->bet_id)->first();
                $walletId = $agent ? Wallet::query()->where('user_id', $agent->user_id)->where('currency', $hint->currency->value)->orderBy('id')->value('id') : null;
                if (! $agent || ! $purchase || ! $walletId) {
                    $this->proof->refuse();
                }
                $routes[$hint->id] = [(int) $hint->agent_id, (int) $hint->bet_id, (int) $walletId, (int) $purchase->wallet_id];
                $walletIds[] = (int) $walletId;
                $walletIds[] = (int) $purchase->wallet_id;
            }
            $wallets = $walletIds === [] ? [] : $this->locks->lockMany($walletIds);
            $agentIds = array_values(array_unique($agentIds));
            sort($agentIds);
            $agents = Agent::withTrashed()->select('agents.*')
                ->selectRaw('CAST(total_commission_earned AS CHAR) AS exact_earned, CAST(total_commission_paid AS CHAR) AS exact_paid')
                ->whereIn('id', $agentIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($agents->count() !== count($agentIds) || $agents->contains(fn ($row) => $row->trashed())) {
                $this->proof->refuse();
            }
            $rows = $scope()->lockForUpdate()->get();
            $currency = $agentId === null ? Currency::tryFrom((string) config('lottery.betting.currency', 'THB')) : $agents->get($agentId)?->currency;
            $currency ??= Currency::THB;
            if ($rows->isNotEmpty()) {
                $currency = $rows->first()->currency;
            }
            $total = '0.00';
            $paid = [];
            $held = 0;
            foreach ($rows as $row) {
                $route = $routes[$row->id] ?? null;
                $agent = $agents->get($row->agent_id);
                if (! $route || ! $agent || $route[0] !== (int) $row->agent_id || $route[1] !== (int) $row->bet_id
                    || $row->currency !== $currency || $agent->currency !== $currency) {
                    $this->proof->refuse('commission_payout_routing_or_currency_conflict');
                }
                $wallet = $wallets[$route[2]];
                $member = $wallets[$route[3]];
                if ((int) $wallet->user_id !== (int) $agent->user_id || $wallet->currency !== $currency) {
                    $this->proof->refuse();
                }
                $this->assertBook($agent);
                $source = $this->proof->source($row, $member);
                if ($row->status === CommissionStatus::Paid) {
                    $this->proof->replay($row, $agent, $wallet, $source);

                    continue;
                }
                $this->proof->assertFresh($row);
                if (! $agent->canEarnCommission()) {
                    $held++;

                    continue; // Suspension is a hold, NOT forfeiture or a claim/counter cancellation.
                }
                if (! $wallet->canCredit()) {
                    $this->proof->refuse('commission_payout_wallet_unavailable');
                }
                $transaction = $this->wallets->credit($wallet, Money::of($source->amount, $currency), FinancialTransactionType::Commission,
                    $this->proof->key((int) $row->id), [
                        'description' => 'Owned agent commission '.$row->reference_number,
                        'reference_type' => AgentCommission::class, 'reference_id' => (int) $row->id,
                        'metadata' => ['agent_id' => (int) $agent->id, 'commission_id' => (int) $row->id,
                            'draw_id' => (int) $row->draw_id, 'reviewer_id' => $reviewerId],
                    ]);
                $row->forceFill(['financial_transaction_id' => $transaction->id, 'status' => CommissionStatus::Paid, 'paid_at' => now()])->save();
                $this->proof->capture($row, $agent, $wallet, $source, $transaction, $drawId === null ? 'manual' : 'draw');
                $agent->exact_paid = bcadd((string) $agent->exact_paid, $source->amount, 2);
                DB::table('agents')->where('id', $agent->id)->update(['total_commission_paid' => $agent->exact_paid, 'updated_at' => now()]);
                $this->assertBook($agent);
                $total = bcadd($total, $source->amount, 2);
                $paid[] = $row;
            }
            $result = new CommissionSettlementResult($drawId ?? 0, count($paid), $total, $currency->value,
                $paid === [] && $held === 0, $paid, ['held_commissions' => $held]);
            if ($paid !== []) {
                AuditLog::create([
                    'user_id' => $reviewerId, 'action' => AuditAction::Payout, 'risk_level' => RiskLevel::Low,
                    'auditable_type' => $drawId === null ? Agent::class : Draw::class, 'auditable_id' => $drawId ?? $agentId,
                    'description' => sprintf('Settled %d owned commission(s): %s %s.', count($paid), $total, $currency->value),
                    'metadata' => ['draw_id' => $drawId, 'agent_id' => $agentId, 'commissions_settled' => count($paid),
                        'total_paid' => $total, 'currency' => $currency->value, 'held_commissions' => $held],
                ]);
            }

            return $result;
        }, DB::transactionLevel() === 0 ? 3 : 1);
    }

    /** Preserve known unowned book entries too; their existence is NOT permission to pay them. */
    public function assertBook(Agent $agent, ?string $earned = null, ?string $paid = null): void
    {
        $owners = DB::table('bet_commission_ownerships')->where('agent_id', $agent->id)->where('state', 'active')
            ->orderBy('id')->lockForUpdate()->get()->keyBy('commission_id');
        $book = $this->proof->rows()->where('agent_id', $agent->id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $pending = '0.00';
        $knownPaid = '0.00';
        foreach ($owners as $owner) {
            if (! $book->has($owner->commission_id) || $owner->currency !== $agent->currency->value
                || ! preg_match('/\A(?:0|[1-9][0-9]{0,17})\.[0-9]{2}\z/D', $owner->amount)
                || bccomp($owner->amount, '0', 2) <= 0 || $owner->cancelled_at !== null || $owner->cancelled_reason !== null) {
                $this->proof->refuse('commission_payout_aggregate_conflict');
            }
        }
        foreach ($book as $row) {
            $owner = $owners->get($row->id);
            if (in_array($row->status, [CommissionStatus::Cancelled, CommissionStatus::Reversed], true)) {
                if ($owner) {
                    $this->proof->refuse('commission_payout_aggregate_conflict');
                }

                continue;
            }
            if ($row->trashed() || $row->currency !== $agent->currency || bccomp((string) $row->exact_amount, '0', 2) < 0) {
                $this->proof->refuse('commission_payout_aggregate_conflict');
            }
            $amount = bcadd((string) $row->exact_amount, '0', 2);
            if ($owner && bccomp($owner->amount, $amount, 2) > 0) {
                $amount = $owner->amount;
            }
            if ($row->status === CommissionStatus::Paid) {
                $knownPaid = bcadd($knownPaid, $amount, 2);
            } else {
                $pending = bcadd($pending, $amount, 2);
            }
        }
        if (bccomp(($paid ?? (string) $agent->exact_paid), $knownPaid, 2) < 0 || bccomp(($paid ?? (string) $agent->exact_paid), '0', 2) < 0
            || bccomp(($earned ?? (string) $agent->exact_earned), bcadd(($paid ?? (string) $agent->exact_paid), $pending, 2), 2) < 0) {
            $this->proof->refuse('commission_payout_aggregate_conflict');
        }
    }
}
