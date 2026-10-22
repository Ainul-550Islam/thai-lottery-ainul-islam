<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\BetStatus;
use App\Enums\CommissionStatus;
use App\Enums\LedgerEntryType;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Exceptions\BetDomainException;
use App\Exceptions\FinancialException;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\Bet;
use App\Models\BetItem;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use App\Services\Betting\BetAccountingProof;
use App\Services\Finance\IdempotencyService;
use App\Services\Finance\ReversalIntegrityGuard;
use Illuminate\Support\Facades\DB;

/** Local original credit proof; no external payout or historical source enrollment. */
final class AgentCommissionPayoutProof
{
    public function refuse(string $code = 'commission_payout_source_conflict'): never
    {
        throw FinancialException::withCode($code, 'Commission source, aggregate or original credit requires controlled review; nothing was disbursed.');
    }

    public function key(int $commissionId): string
    {
        return sprintf('agent-commission-%012d', $commissionId);
    }

    public function rows()
    {
        return AgentCommission::withTrashed()->select('agent_commissions.*')
            ->selectRaw('CAST(commission_amount AS CHAR) AS exact_amount, CAST(base_amount AS CHAR) AS exact_base, CAST(commission_rate AS CHAR) AS exact_rate');
    }

    private function terms(AgentCommission $row): array
    {
        $amount = bcadd((string) $row->exact_amount, '0', 2);
        if ($row->trashed() || ! $row->accrued_at || bccomp($amount, '0', 2) <= 0) {
            $this->refuse();
        }

        return [$amount, hash('sha256', json_encode([$row->id, $row->reference_number, $row->agent_id, $row->user_id,
            $row->bet_id, $row->draw_id, $row->currency->value, $amount,
            bcadd((string) $row->exact_base, '0', 2), bcadd((string) $row->exact_rate, '0', 4),
            $row->accrued_at->toDateTimeString(), $row->metadata], JSON_THROW_ON_ERROR))];
    }

    /** Complete immutable Part43 source set, plus its canonical funded member debit. */
    public function source(AgentCommission $commission, Wallet $member): object
    {
        if (DB::transactionLevel() < 1) {
            $this->refuse();
        }
        $purchase = DB::table('bet_purchase_ownerships')->where('bet_id', $commission->bet_id)->lockForUpdate()->first();
        $bet = Bet::withTrashed()->select('bets.*')->selectRaw('CAST(stake_amount AS CHAR) AS exact_stake, CAST(potential_payout AS CHAR) AS exact_payout')
            ->whereKey($commission->bet_id)->lockForUpdate()->first();
        if (! $purchase || ! $bet || $bet->trashed() || $purchase->state !== 'active' || $purchase->refund_transaction_id !== null
            || $purchase->cancelled_at !== null || (int) $purchase->wallet_id !== (int) $member->id
            || ! in_array($bet->status, [BetStatus::Active, BetStatus::Won, BetStatus::Lost], true)
            || $purchase->currency !== $bet->currency->value || $purchase->commission_snapshot_hash === null) {
            $this->refuse();
        }
        // Same original immutable bet identity as Part42, without its REFUND-only
        // requirement that settled winning items have no prize. Earnings may be paid after draw settlement.
        $items = BetItem::withTrashed()->where('bet_id', $bet->id)->orderBy('id')->lockForUpdate()->get();
        if ($items->count() !== 1 || $items->sole()->trashed() || ! $bet->placed_at) {
            $this->refuse();
        }
        $item = $items->sole();
        $stake = bcadd((string) $bet->exact_stake, '0', 2);
        $payout = bcadd((string) $bet->exact_payout, '0', 2);
        $betHash = hash('sha256', json_encode([$bet->id, $bet->uuid, $bet->bet_number, $bet->user_id,
            $bet->draw_id, $bet->ticket_id, $bet->type->value, $bet->currency->value, $stake, $payout,
            $bet->total_numbers, $bet->idempotency_key, $bet->placed_at->toDateTimeString(), $bet->metadata,
            $item->id, $item->number, $item->position, $item->payout_multiplier, $item->metadata], JSON_THROW_ON_ERROR));
        if (! hash_equals($purchase->terms_hash, $betHash) || $stake !== $purchase->stake_amount || $payout !== $purchase->potential_payout) {
            $this->refuse();
        }
        try {
            [, $originalHash] = app(BetAccountingProof::class)->transaction((int) $purchase->original_transaction_id, $bet, $member);
        } catch (BetDomainException) {
            $this->refuse();
        }
        if (! hash_equals($purchase->original_hash, $originalHash)) {
            $this->refuse();
        }
        $claims = DB::table('bet_commission_ownerships')->where('purchase_ownership_id', $purchase->id)->orderBy('commission_id')->lockForUpdate()->get();
        $rows = $this->rows()->where('bet_id', $bet->id)->orderBy('id')->lockForUpdate()->get();
        if ($rows->count() !== $claims->count() || $claims->count() !== (int) $purchase->commission_snapshot_count || $rows->isEmpty()) {
            $this->refuse();
        }
        $snapshot = [];
        $own = null;
        foreach ($rows as $index => $row) {
            $claim = $claims[$index];
            [$amount, $hash] = $this->terms($row);
            if ((int) $row->id !== (int) $claim->commission_id || (int) $row->agent_id !== (int) $claim->agent_id
                || (int) $row->user_id !== (int) $bet->user_id || (int) $row->draw_id !== (int) $bet->draw_id
                || $row->currency !== $bet->currency || $claim->currency !== $bet->currency->value
                || $claim->amount !== $amount || ! hash_equals($claim->terms_hash, $hash)
                || $claim->state !== 'active' || $claim->cancelled_at !== null || $claim->cancelled_reason !== null
                || in_array($row->status, [CommissionStatus::Reversed, CommissionStatus::Cancelled], true) || $row->reversed_at !== null) {
                $this->refuse();
            }
            $snapshot[] = [(int) $row->id, (int) $row->agent_id, $hash];
            if ((int) $row->id === (int) $commission->id) {
                $own = $claim;
            }
        }
        if (! $own || ! hash_equals($purchase->commission_snapshot_hash, hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)))) {
            $this->refuse();
        }

        return $own;
    }

    public function assertFresh(AgentCommission $row): void
    {
        $key = $this->key((int) $row->id);
        if ($row->financial_transaction_id !== null || $row->paid_at !== null || $row->reversed_at !== null
            || DB::table('agent_commission_payouts')->where('commission_id', $row->id)->lockForUpdate()->exists()
            || FinancialTransaction::withTrashed()->where(fn ($q) => $q->where('idempotency_key', $key)->orWhere(fn ($ref) => $ref
                ->whereIn('reference_type', [AgentCommission::class, 'agent_commission'])->where('reference_id', $row->id)))->lockForUpdate()->exists()
            || LedgerEntry::withTrashed()->whereIn('reference_type', [AgentCommission::class, 'agent_commission'])->where('reference_id', $row->id)->lockForUpdate()->exists()) {
            $this->refuse('commission_payout_history_conflict');
        }
    }

    public function original(AgentCommission $row, Agent $agent, Wallet $wallet, bool $compensated = false): array
    {
        $tx = FinancialTransaction::withTrashed()->whereKey($row->financial_transaction_id)->lockForUpdate()->first();
        if (! $tx || $tx->trashed() || $row->status !== ($compensated ? CommissionStatus::Reversed : CommissionStatus::Paid) || ! $row->paid_at
            || ($compensated ? $row->reversed_at === null : $row->reversed_at !== null)
            || $tx->status !== ($compensated ? TransactionStatus::Reversed : TransactionStatus::Completed)
            || ($compensated ? $tx->reversed_at === null : $tx->reversed_at !== null)
            || $tx->type !== TransactionType::Commission || $tx->idempotency_key !== $this->key((int) $row->id)
            || $tx->reference_type !== AgentCommission::class || (int) $tx->reference_id !== (int) $row->id
            || (int) $tx->wallet_id !== (int) $wallet->id || (int) $tx->user_id !== (int) $agent->user_id
            || $tx->currency !== $row->currency || bccomp((string) $tx->amount, (string) $row->exact_amount, 2) !== 0
            || ($tx->metadata['commission_id'] ?? null) !== (int) $row->id || ($tx->metadata['agent_id'] ?? null) !== (int) $agent->id) {
            $this->refuse('commission_payout_original_conflict');
        }
        $linked = FinancialTransaction::withTrashed()->whereIn('reference_type', [AgentCommission::class, 'agent_commission'])->where('reference_id', $row->id)
            ->orderBy('id')->lockForUpdate()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $reversals = FinancialTransaction::withTrashed()->where(fn ($q) => $q
            ->where(fn ($ref) => $ref->where('reference_type', FinancialTransaction::class)->where('reference_id', $tx->id))
            ->orWhere('idempotency_key', app(IdempotencyService::class)->deterministicKey('reversal', (string) $tx->id)))
            ->orderBy('id')->lockForUpdate()->get();
        if ($linked !== [(int) $tx->id] || $reversals->count() !== ($compensated ? 1 : 0)) {
            $this->refuse('commission_payout_original_conflict');
        }
        $guard = app(ReversalIntegrityGuard::class);
        $entries = $guard->entries($tx);
        if ($guard->assertAccounting($tx, $wallet, $entries) !== LedgerEntryType::Credit) {
            $this->refuse();
        }
        $relatedEntries = LedgerEntry::withTrashed()->whereIn('reference_type', [AgentCommission::class, 'agent_commission'])
            ->where('reference_id', $row->id)->orderBy('id')->lockForUpdate()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($relatedEntries !== $entries->pluck('id')->map(fn ($id): int => (int) $id)->all()) {
            $this->refuse('commission_payout_original_conflict');
        }

        return [$tx, $guard->fingerprint($tx, $entries)];
    }

    public function capture(AgentCommission $row, Agent $agent, Wallet $wallet, object $source, FinancialTransaction $fresh, string $mode): void
    {
        if (DB::transactionLevel() < 1 || ! $fresh->wasRecentlyCreated || ! in_array($mode, ['draw', 'manual'], true)
            || ! DB::table('bet_commission_ownerships')->where('id', $source->id)->where('commission_id', $row->id)
                ->where('agent_id', $agent->id)->where('state', 'active')->lockForUpdate()->exists()) {
            $this->refuse('commission_payout_history_conflict');
        }
        [$tx, $hash] = $this->original($row, $agent, $wallet);
        DB::table('agent_commission_payouts')->insert([
            'commission_id' => $row->id, 'source_ownership_id' => $source->id, 'wallet_id' => $wallet->id,
            'financial_transaction_id' => $tx->id, 'payout_key' => $this->key((int) $row->id),
            'currency' => $row->currency->value, 'amount' => $source->amount, 'terms_hash' => $source->terms_hash,
            'original_hash' => $hash, 'mode' => $mode, 'cancellation_policy' => BetCommissionCompensation::configuredPolicy(), 'paid_at' => $row->paid_at, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function replay(AgentCommission $row, Agent $agent, Wallet $wallet, object $source, bool $compensated = false): array
    {
        $receipt = DB::table('agent_commission_payouts')->where('commission_id', $row->id)->lockForUpdate()->first();
        [$tx, $hash] = $this->original($row, $agent, $wallet, $compensated);
        if (! $receipt || (int) $receipt->source_ownership_id !== (int) $source->id || (int) $receipt->wallet_id !== (int) $wallet->id
            || (int) $receipt->financial_transaction_id !== (int) $tx->id || $receipt->payout_key !== $tx->idempotency_key
            || $receipt->currency !== $row->currency->value || $receipt->amount !== $source->amount
            || ! hash_equals($receipt->terms_hash, $source->terms_hash) || ! hash_equals($receipt->original_hash, $hash)
            || $receipt->paid_at !== $row->paid_at->toDateTimeString() || ! in_array($receipt->mode, ['draw', 'manual'], true)) {
            $this->refuse('commission_payout_original_conflict');
        }

        return [$tx, $receipt];
    }
}
