<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\CommissionStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\LedgerEntryType;
use App\Enums\TransactionStatus;
use App\Exceptions\BetCancellationException;
use App\Exceptions\FinancialException;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\Bet;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use App\Services\Finance\FinancialReversalService;
use App\Services\Finance\FinancialTransactionService;
use App\Services\Finance\IdempotencyService;
use App\Services\Finance\LedgerBalanceValidator;
use App\Services\Finance\LedgerPostingService;
use App\Services\Finance\Money;
use App\Services\Finance\ReversalIntegrityGuard;
use App\Services\Finance\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Atomic member cancellation of proved unpaid and explicitly policy-bound paid commissions. */
final class BetCommissionCancellation
{
    public function refuse(): never
    {
        throw BetCancellationException::withCode('bet_cancellation_commission_workflow_required',
            'Commission source ownership, unpaid state or aggregate coverage is unproved; preserve the purchase for coordinated review.');
    }

    private function assertTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            $this->refuse();
        }
    }

    private function rows(int $betId)
    {
        return AgentCommission::withTrashed()->select('agent_commissions.*')
            ->selectRaw('CAST(commission_amount AS CHAR) AS exact_amount, CAST(base_amount AS CHAR) AS exact_base, CAST(commission_rate AS CHAR) AS exact_rate')
            ->where('bet_id', $betId)->orderBy('id')->lockForUpdate()->get();
    }

    private function terms(AgentCommission $row): array
    {
        $amount = bcadd((string) $row->exact_amount, '0', 2);
        if ($row->trashed() || ! $row->accrued_at || ! preg_match('/\A(?:0|[1-9][0-9]{0,17})\.[0-9]{2}\z/D', $amount)
            || bccomp($amount, '0', 2) <= 0) {
            $this->refuse();
        }
        $hash = hash('sha256', json_encode([$row->id, $row->reference_number, $row->agent_id, $row->user_id,
            $row->bet_id, $row->draw_id, $row->currency->value, $amount,
            bcadd((string) $row->exact_base, '0', 2), bcadd((string) $row->exact_rate, '0', 4),
            $row->accrued_at->toDateTimeString(), $row->metadata], JSON_THROW_ON_ERROR));

        return [$amount, $hash];
    }

    private function assertNoFinancialHistory(AgentCommission $row): void
    {
        if ($row->financial_transaction_id !== null || $row->paid_at !== null || $row->reversed_at !== null
            || DB::table('agent_commission_payouts')->where('commission_id', $row->id)->lockForUpdate()->exists()
            || FinancialTransaction::withTrashed()->whereIn('reference_type', [AgentCommission::class, 'agent_commission'])
                ->where('reference_id', $row->id)->lockForUpdate()->exists()
            || LedgerEntry::withTrashed()->whereIn('reference_type', [AgentCommission::class, 'agent_commission'])
                ->where('reference_id', $row->id)->lockForUpdate()->exists()) {
            $this->refuse();
        }
    }

    /** Called exclusively from the freshly funded purchase ownership writer. */
    public function capture(Bet $bet, int $purchaseId): void
    {
        $this->assertTransaction();
        if (! $bet->wasRecentlyCreated) {
            $this->refuse();
        }
        $purchase = DB::table('bet_purchase_ownerships')->where('id', $purchaseId)->lockForUpdate()->first();
        if (! $purchase || (int) $purchase->bet_id !== (int) $bet->id || $purchase->state !== 'active'
            || $purchase->commission_snapshot_hash !== null || $purchase->commission_snapshot_count !== null) {
            $this->refuse();
        }
        $rows = $this->rows((int) $bet->id);
        $snapshot = [];
        foreach ($rows as $row) {
            [$amount, $hash] = $this->terms($row);
            $this->assertNoFinancialHistory($row);
            if ($row->status !== CommissionStatus::Accrued || (int) $row->user_id !== (int) $bet->user_id
                || (int) $row->draw_id !== (int) $bet->draw_id || $row->currency !== $bet->currency) {
                $this->refuse();
            }
            DB::table('bet_commission_ownerships')->insert([
                'purchase_ownership_id' => $purchaseId, 'commission_id' => $row->id, 'agent_id' => $row->agent_id,
                'currency' => $row->currency->value, 'amount' => $amount, 'terms_hash' => $hash,
                'state' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $snapshot[] = [(int) $row->id, (int) $row->agent_id, $hash];
        }
        DB::table('bet_purchase_ownerships')->where('id', $purchaseId)->update([
            'commission_snapshot_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
            'commission_snapshot_count' => count($snapshot), 'updated_at' => now(),
        ]);
    }

    /** Lock agents before the commission book; initial IDs are routing hints, never authority. */
    private function verified(Bet $bet, object $purchase): array
    {
        $this->assertTransaction();
        $hints = DB::table('bet_commission_ownerships')->where('purchase_ownership_id', $purchase->id)->get();
        $ids = $hints->pluck('agent_id')->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
        $agents = Agent::withTrashed()->select('agents.*')
            ->selectRaw('CAST(total_commission_earned AS CHAR) AS exact_earned, CAST(total_commission_paid AS CHAR) AS exact_paid')
            ->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $claims = DB::table('bet_commission_ownerships')->where('purchase_ownership_id', $purchase->id)
            ->orderBy('commission_id')->lockForUpdate()->get();
        $currentIds = $claims->pluck('agent_id')->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
        if ($currentIds !== $ids || $agents->count() !== count($ids)) {
            $this->refuse(); // Never acquire an additional late agent lock from stale hints.
        }
        $rows = $this->rows((int) $bet->id);
        if ($purchase->commission_snapshot_hash === null) {
            // Existing Part42 DIRECT purchases stay usable only with no history at all.
            if ($rows->isNotEmpty() || $claims->isNotEmpty() || $purchase->commission_snapshot_count !== null) {
                $this->refuse();
            }

            return [$rows, $claims, $agents];
        }
        if ($rows->count() !== $claims->count() || (int) $purchase->commission_snapshot_count !== $claims->count()
            || $rows->pluck('id')->map(fn ($id): int => (int) $id)->all() !== $claims->pluck('commission_id')->map(fn ($id): int => (int) $id)->all()) {
            $this->refuse();
        }
        $snapshot = [];
        foreach ($rows as $index => $row) {
            $claim = $claims[$index];
            [$amount, $hash] = $this->terms($row);
            $agent = $agents->get($claim->agent_id);
            if (! $agent || $agent->trashed() || $agent->currency !== $bet->currency || $row->currency !== $bet->currency
                || $claim->currency !== $bet->currency->value || (int) $row->agent_id !== (int) $claim->agent_id
                || (int) $row->user_id !== (int) $bet->user_id || (int) $row->draw_id !== (int) $bet->draw_id
                || $amount !== $claim->amount || ! hash_equals($claim->terms_hash, $hash)) {
                $this->refuse();
            }
            $snapshot[] = [(int) $row->id, (int) $row->agent_id, $hash];
        }
        if (! hash_equals($purchase->commission_snapshot_hash, hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)))) {
            $this->refuse();
        }

        return [$rows, $claims, $agents];
    }

    private function assertBook(Agent $agent, ?string $earned = null, ?string $paid = null): void
    {
        try {
            app(AgentCommissionPayoutService::class)->assertBook($agent, $earned, $paid);
        } catch (FinancialException) {
            $this->refuse();
        }
    }

    /** Internal coordinator primitive; the enclosing member transaction owns the complete refund. */
    public function cancelUnpaid(Bet $bet, object $purchase, string $reason, string $stamp, array $wallets = [], ?Wallet $member = null): void
    {
        [$rows, $claims, $agents] = $this->verified($bet, $purchase);
        $prepared = $earned = $paid = [];
        $compensation = app(BetCommissionCompensation::class);
        foreach ($agents as $agent) {
            $this->assertBook($agent);
        }
        foreach ($rows as $index => $row) {
            $claim = $claims[$index];
            if ($claim->state !== 'active' || $claim->cancelled_at !== null || $claim->cancelled_reason !== null) {
                $this->refuse();
            }
            if ($row->status === CommissionStatus::Paid) {
                $receipt = DB::table('agent_commission_payouts')->where('commission_id', $row->id)->lockForUpdate()->first();
                $wallet = $receipt ? ($wallets[(int) $receipt->wallet_id] ?? null) : null;
                if (! $member || ! $wallet) {
                    $this->refuse();
                } // No late locks from stale routing hints.
                $prepared[$row->id] = $compensation->prepare($row, $agents->get($row->agent_id), $wallet, $member, $claim);
                $paid[$row->agent_id] = bcadd($paid[$row->agent_id] ?? '0.00', $claim->amount, 2);
            } else {
                if (! in_array($row->status, [CommissionStatus::Accrued, CommissionStatus::Calculated, CommissionStatus::Payable], true)) {
                    $this->refuse();
                }
                $this->assertNoFinancialHistory($row);
            }
            $earned[$row->agent_id] = bcadd($earned[$row->agent_id] ?? '0.00', $claim->amount, 2);
        }
        foreach ($rows as $index => $row) {
            $claim = $claims[$index];
            if (isset($prepared[$row->id])) {
                $receipt = $prepared[$row->id][1];
                $this->compensate($bet, $purchase, $claim, $wallets[(int) $receipt->wallet_id], $prepared[$row->id], $reason, $stamp);
                $row->forceFill(['status' => CommissionStatus::Reversed, 'reversed_at' => $stamp])->save();
            } else {
                $row->forceFill(['status' => CommissionStatus::Cancelled])->save();
            }
            DB::table('bet_commission_ownerships')->where('id', $claim->id)->update([
                'state' => 'cancelled', 'cancelled_at' => $stamp, 'cancelled_reason' => $reason, 'updated_at' => now(),
            ]);
        }
        foreach ($agents as $agent) {
            $afterEarned = bcsub((string) $agent->exact_earned, $earned[$agent->id], 2);
            $afterPaid = bcsub((string) $agent->exact_paid, $paid[$agent->id] ?? '0.00', 2);
            $this->assertBook($agent, $afterEarned, $afterPaid);
            $values = ['total_commission_earned' => $afterEarned, 'updated_at' => now()];
            if (isset($paid[$agent->id])) {
                $values['total_commission_paid'] = $afterPaid;
            }
            DB::table('agents')->where('id', $agent->id)->update($values);
        }
    }

    /** Private: there is no standalone public paid-compensation writer. */
    private function compensate(Bet $bet, object $purchase, object $source, Wallet $wallet, array $prepared, string $reason, string $stamp): void
    {
        if (DB::transactionLevel() < 1 || (int) $source->purchase_ownership_id !== (int) $purchase->id
            || (int) $purchase->bet_id !== (int) $bet->id || $purchase->state !== 'active') {
            $this->refuse();
        }
        [$original, $receipt] = $prepared;
        $guard = app(ReversalIntegrityGuard::class);
        $entries = $guard->entries($original);
        if ($guard->assertAccounting($original, $wallet, $entries) !== LedgerEntryType::Credit
            || ! hash_equals($receipt->original_hash, $guard->fingerprint($original, $entries))) {
            $this->refuse();
        }
        $key = app(FinancialReversalService::class)->reversalKeyFor($original);
        $claimed = app(IdempotencyService::class)->claim($key, [
            'type' => FinancialTransactionType::Reversal, 'amount' => $source->amount, 'currency' => $source->currency,
            'wallet_id' => $wallet->id, 'user_id' => $wallet->user_id,
        ], function (string $canonical) use ($original, $receipt, $purchase, $bet, $reason): FinancialTransaction {
            $tx = new FinancialTransaction;
            $tx->fill([
                'reference_number' => 'REV-'.strtoupper(bin2hex(random_bytes(10))), 'type' => FinancialTransactionType::Reversal->toTransactionType(),
                'user_id' => $original->user_id, 'wallet_id' => $original->wallet_id, 'currency' => $original->currency,
                'amount' => (string) $original->amount, 'fee' => '0.00', 'description' => 'Commission compensation within member cancellation',
                'idempotency_key' => $canonical, 'reference_type' => FinancialTransaction::class, 'reference_id' => (int) $original->id,
                'metadata' => ['engine_type' => FinancialTransactionType::Reversal->value, 'reversal_of_transaction_id' => (int) $original->id,
                    'reversal_source_hash' => $receipt->original_hash, 'reason' => $reason, 'purchase_ownership_id' => (int) $purchase->id,
                    'member_user_id' => (int) $bet->user_id, 'commission_payout_id' => (int) $receipt->id,
                    'cancellation_policy' => $receipt->cancellation_policy],
            ]);
            $tx->uuid = (string) Str::uuid();
            $tx->status = TransactionStatus::Pending;
            $tx->save();

            return $tx;
        });
        if ($claimed['replayed']) {
            $this->refuse();
        }
        $tx = $claimed['transaction'];
        $transactions = app(FinancialTransactionService::class);
        $transactions->transitionTo($tx, TransactionStatus::Processing)->save();
        $balance = app(WalletService::class)->applyReversal($wallet, Money::fromDatabase($source->amount, $wallet->currency), LedgerEntryType::Credit);
        app(LedgerPostingService::class)->post($tx, app(FinancialReversalService::class)->mirrorEntries($entries, $original), [0 => $balance->toString()]);
        app(LedgerBalanceValidator::class)->assertTransactionBalanced($tx);
        $transactions->transitionTo($tx, TransactionStatus::Completed);
        $tx->processed_at = now();
        $tx->save();
        $guard->assertCompensation($original, $tx, $entries, $key);
        $transactions->transitionTo($original, TransactionStatus::Reversed);
        $original->reversed_at = $stamp;
        $original->reversed_by = $bet->user_id;
        $original->save();
        DB::table('agent_commission_compensations')->insert([
            'payout_id' => $receipt->id, 'ownership_id' => $source->id, 'original_transaction_id' => $original->id,
            'compensation_transaction_id' => $tx->id, 'wallet_id' => $wallet->id, 'amount' => $source->amount, 'currency' => $source->currency,
            'policy_reference' => $receipt->cancellation_policy, 'payout_fact_hash' => app(BetCommissionCompensation::class)->factHash($receipt),
            'compensation_hash' => $guard->fingerprint($tx, $guard->entries($tx)), 'cancelled_reason' => $reason,
            'cancelled_at' => $stamp, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function assertCancelled(Bet $bet, object $purchase, array $wallets = []): void
    {
        [$rows, $claims, $agents] = $this->verified($bet, $purchase);
        foreach ($rows as $index => $row) {
            $claim = $claims[$index];
            if ($claim->state !== 'cancelled' || ! $claim->cancelled_at || (string) $claim->cancelled_at !== (string) $purchase->cancelled_at
                || $claim->cancelled_reason !== $purchase->cancelled_reason) {
                $this->refuse();
            }
            if ($row->status === CommissionStatus::Reversed) {
                $receipt = DB::table('agent_commission_payouts')->where('commission_id', $row->id)->lockForUpdate()->first();
                $wallet = $receipt ? ($wallets[(int) $receipt->wallet_id] ?? null) : null;
                if (! $wallet) {
                    $this->refuse();
                }
                app(BetCommissionCompensation::class)->assertClosed($bet, $purchase, $row, $agents->get($row->agent_id), $wallet, $claim);
            } else {
                if ($row->status !== CommissionStatus::Cancelled) {
                    $this->refuse();
                }
                $this->assertNoFinancialHistory($row);
            }
        }
    }
}
