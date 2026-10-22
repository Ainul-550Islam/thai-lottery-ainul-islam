<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Exceptions\BetCancellationException;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\Bet;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use App\Services\Finance\FinancialReversalService;
use App\Services\Finance\ReversalIntegrityGuard;
use Illuminate\Support\Facades\DB;

/** Pure paid-leg policy/source/terminal proof. No standalone compensation writer or payment ingress. */
final class BetCommissionCompensation
{
    public static function configuredPolicy(): ?string
    {
        $reference = config('agent.commission.paid_cancellation.policy_reference');

        return config('agent.commission.paid_cancellation.enabled') === true && is_string($reference)
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:\/-]{7,127}\z/D', $reference) ? $reference : null;
    }

    private function refuse(): never
    {
        throw BetCancellationException::withCode('bet_cancellation_commission_workflow_required',
            'Original-specific commission compensation is unapproved, unproved or unavailable; preserve all obligations for controlled review.');
    }

    public function factHash(object $receipt): string
    {
        return hash('sha256', json_encode([$receipt->id, $receipt->commission_id, $receipt->source_ownership_id,
            $receipt->wallet_id, $receipt->financial_transaction_id, $receipt->payout_key, $receipt->currency,
            $receipt->amount, $receipt->terms_hash, $receipt->original_hash, $receipt->mode,
            $receipt->paid_at, $receipt->cancellation_policy], JSON_THROW_ON_ERROR));
    }

    /** Validate ALL selected paid sources before any commission is closed. */
    public function prepare(AgentCommission $row, Agent $agent, Wallet $wallet, Wallet $member, object $source): array
    {
        if (DB::transactionLevel() < 1 || self::configuredPolicy() === null
            || DB::table('agent_commission_compensations')->where('ownership_id', $source->id)->lockForUpdate()->exists()) {
            $this->refuse();
        }
        $proof = app(AgentCommissionPayoutProof::class);
        $verifiedSource = $proof->source($row, $member);
        if ((int) $source->id !== (int) $verifiedSource->id) {
            $this->refuse();
        }
        [$original, $receipt] = $proof->replay($row, $agent, $wallet, $source);
        if (LedgerEntry::withTrashed()->where('reference_type', FinancialTransaction::class)->where('reference_id', $original->id)->lockForUpdate()->exists()) {
            $this->refuse(); // An orphan prior compensation is not a free original.
        }
        if ($receipt->cancellation_policy === null || $receipt->cancellation_policy !== self::configuredPolicy()
            || (int) $receipt->wallet_id !== (int) $wallet->id || ! $wallet->canDebit()) {
            $this->refuse();
        }

        return [$original, $receipt];
    }

    public function assertClosed(Bet $bet, object $purchase, AgentCommission $row, Agent $agent, Wallet $wallet, object $source): void
    {
        $closed = DB::table('agent_commission_compensations')->where('ownership_id', $source->id)->lockForUpdate()->first();
        [$original, $receipt] = app(AgentCommissionPayoutProof::class)->replay($row, $agent, $wallet, $source, true);
        $tx = $closed ? FinancialTransaction::withTrashed()->whereKey($closed->compensation_transaction_id)->lockForUpdate()->first() : null;
        if (! $closed || ! $tx || $tx->reversed_at !== null || (int) $closed->payout_id !== (int) $receipt->id || (int) $closed->original_transaction_id !== (int) $original->id
            || (int) $closed->wallet_id !== (int) $wallet->id || $closed->currency !== $source->currency || $closed->amount !== $source->amount
            || $receipt->cancellation_policy === null || $closed->policy_reference !== $receipt->cancellation_policy
            || ! hash_equals($closed->payout_fact_hash, $this->factHash($receipt))
            || $closed->cancelled_reason !== $purchase->cancelled_reason || $closed->cancelled_at !== $purchase->cancelled_at
            || $row->reversed_at?->toDateTimeString() !== $purchase->cancelled_at
            || $original->reversed_at?->toDateTimeString() !== $purchase->cancelled_at || (int) $original->reversed_by !== (int) $bet->user_id
            || ($tx->metadata['purchase_ownership_id'] ?? null) !== (int) $purchase->id
            || ($tx->metadata['member_user_id'] ?? null) !== (int) $bet->user_id
            || ($tx->metadata['commission_payout_id'] ?? null) !== (int) $receipt->id
            || ($tx->metadata['cancellation_policy'] ?? null) !== $closed->policy_reference) {
            $this->refuse();
        }
        if (FinancialTransaction::withTrashed()->where(fn ($q) => $q
            ->where(fn ($ref) => $ref->where('reference_type', FinancialTransaction::class)->where('reference_id', $tx->id))
            ->orWhere('idempotency_key', app(FinancialReversalService::class)->reversalKeyFor($tx)))->lockForUpdate()->exists()) {
            $this->refuse();
        }
        $guard = app(ReversalIntegrityGuard::class);
        $guard->assertCompensation($original, $tx, $guard->entries($original), app(FinancialReversalService::class)->reversalKeyFor($original));
        $related = LedgerEntry::withTrashed()->where('reference_type', FinancialTransaction::class)->where('reference_id', $original->id)
            ->orderBy('id')->lockForUpdate()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($related !== $guard->entries($tx)->pluck('id')->map(fn ($id): int => (int) $id)->all()) {
            $this->refuse();
        }
        if (! hash_equals($closed->compensation_hash, $guard->fingerprint($tx, $guard->entries($tx)))) {
            $this->refuse();
        }
    }
}
