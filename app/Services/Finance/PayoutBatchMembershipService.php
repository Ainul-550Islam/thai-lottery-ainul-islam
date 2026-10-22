<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\LedgerEntryType;
use App\Enums\PayoutBatchStatus;
use App\Enums\PayoutStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Exceptions\PayoutBatchException;
use App\Models\Payout;
use App\Models\PayoutBatch;
use App\Services\Payment\OutboundProviderReferenceService;
use App\Services\Payment\PayoutEvidenceService;
use App\Services\Payment\PayoutTransferGuard;
use Illuminate\Support\Facades\DB;

/** Immutable membership, exact facts and canonical rollups; no caller-count arithmetic. */
final class PayoutBatchMembershipService
{
    public function refuse(): never
    {
        throw new PayoutBatchException('Immutable batch membership, route or canonical outcome requires controlled review.', PayoutBatchException::CODE_STATE_FORBIDS);
    }

    public function members(PayoutBatch $batch, bool $lock = false)
    {
        $ids = DB::table('payout_batch_members')->where('payout_batch_id', $batch->id)->orderBy('payout_id')->pluck('payout_id');
        $query = Payout::withTrashed()->whereIn('id', $ids)->orderBy('id');

        return ($lock ? $query->lockForUpdate() : $query)->get();
    }

    public function assertMember(Payout $row): PayoutBatch
    {
        $link = DB::table('payout_batch_members')->where('payout_id', $row->id)->first();
        $batch = $link ? PayoutBatch::query()->find($link->payout_batch_id) : null;
        if (! $batch || $row->trashed() || ! in_array($batch->status, [PayoutBatchStatus::Pending, PayoutBatchStatus::Processing, PayoutBatchStatus::Completed], true)
            || ($row->metadata['batch']['batch_key'] ?? null) !== $batch->batch_key
            || $link->facts_hash !== app(PayoutApprovalGuard::class)->fingerprint($row)
            || $link->method !== app(PayoutApprovalGuard::class)->method($row)->value
            || $link->currency !== $row->currency->value || $row->currency !== $batch->currency
            || bccomp((string) $link->amount, (string) $row->amount, 2) !== 0
            || ($batch->metadata['execution_lane'] ?? null) !== $link->method) {
            $this->refuse();
        }

        return $batch;
    }

    public function verify(PayoutBatch $batch, $members): void
    {
        if ($members->isEmpty() || $members->count() !== (int) $batch->member_count) {
            $this->refuse();
        }
        $sum = '0.00';
        foreach ($members as $row) {
            if ((int) $this->assertMember($row)->id !== (int) $batch->id) {
                $this->refuse();
            }
            $sum = bcadd($sum, (string) $row->amount, 2);
        }
        if (bccomp($sum, (string) $batch->aggregate_amount, 2) !== 0) {
            $this->refuse();
        }
    }

    public function rollup(PayoutBatch $batch, $members): array
    {
        $this->verify($batch, $members);
        $paid = 0;
        $failed = 0;
        $sum = '0.00';
        foreach ($members as $row) {
            if ($row->status === PayoutStatus::Completed) {
                $tx = $row->financialTransaction;
                $wallet = ($batch->metadata['execution_lane'] ?? null) === 'wallet';
                if (! $tx || $tx->status !== TransactionStatus::Completed || $tx->type !== TransactionType::Payout
                    || $tx->reference_type !== Payout::class || (int) $tx->reference_id !== (int) $row->id
                    || (int) $tx->user_id !== (int) $row->user_id || $tx->currency !== $row->currency
                    || bccomp((string) $tx->amount, (string) $row->amount, 2) !== 0
                    || ($wallet ? ((int) $tx->wallet_id !== (int) $row->wallet_id || ! $row->wallet_id) : $tx->wallet_id !== null)) {
                    $this->refuse();
                }
                app(LedgerBalanceValidator::class)->assertTransactionBalanced($tx);
                $entries = $tx->ledgerEntries()->with('ledgerAccount')->get();
                $debits = $entries->filter(fn ($e) => $e->type === LedgerEntryType::Debit);
                $credits = $entries->filter(fn ($e) => $e->type === LedgerEntryType::Credit);
                $expenseCode = LedgerPostingService::accountCodeFor(WalletService::ACCOUNT_PRIZE_EXPENSE, $row->currency);
                $creditCode = LedgerPostingService::accountCodeFor($wallet ? WalletService::ACCOUNT_PLAYER_LIABILITY : WalletService::ACCOUNT_SYSTEM_CASH, $row->currency);
                if ($entries->count() !== 2 || $debits->count() !== 1 || $credits->count() !== 1
                    || $debits->first()->ledgerAccount?->code !== $expenseCode || $credits->first()->ledgerAccount?->code !== $creditCode
                    || $entries->contains(fn ($e) => $e->currency !== $row->currency || bccomp((string) $e->amount, (string) $row->amount, 2) !== 0)) {
                    $this->refuse();
                }
                if (! $wallet) {
                    app(PayoutEvidenceService::class)->assertAttached($row, $row->metadata['transfer'] ?? []);
                    $payment = app(PayoutTransferGuard::class)->payment($row, $row->metadata['transfer'] ?? []);
                    app(OutboundProviderReferenceService::class)->assertBound($row, $payment->gateway, $row->metadata['transfer']['account_scope'], $payment->gateway_reference, $payment);
                    $owner = DB::table('payout_transfer_references')->where('payout_id', $row->id)->first();
                    if (! $owner || (int) $owner->payment_id !== (int) $payment->id || ! is_string($payment->gateway_reference)
                        || $owner->reference_hash !== hash('sha256', $payment->gateway_reference)) {
                        $this->refuse();
                    }
                }

                $paid++;
                $sum = bcadd($sum, (string) $row->amount, 2);
            } elseif ($row->status === PayoutStatus::Failed) {
                // Only the independently confirmed external failure lane is terminal here.
                if ($row->financial_transaction_id !== null || ($row->metadata['transfer']['lane_status'] ?? null) !== 'failed'
                    || $row->failure_reason !== 'independently_confirmed_final_refusal') {
                    $this->refuse();
                }
                app(PayoutEvidenceService::class)->assertAttached($row, $row->metadata['transfer']);
                app(PayoutTransferGuard::class)->payment($row, $row->metadata['transfer']);
                $failed++;
            } elseif (! in_array($row->status, [PayoutStatus::Pending, PayoutStatus::Processing], true) || $row->financial_transaction_id !== null) {
                $this->refuse();
            }
        }

        return ['paid_count' => $paid, 'failed_count' => $failed, 'processed_count' => $paid + $failed,
            'replayed_count' => 0, 'paid_amount' => $sum];
    }
}
