<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\Enums\TicketStatus;
use App\Exceptions\BetPurchaseIdempotencyException;
use App\Models\Bet;
use App\Models\FinancialTransaction;
use App\Models\Ticket;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

/** Immutable original single-selection receipt, not current prize/refund/balance authority. */
final class BetPurchaseReceipt
{
    public function refuse(int $betId): never
    {
        throw BetPurchaseIdempotencyException::replayIncomplete($betId, 'sealed original paid purchase receipt; controlled review is required');
    }

    public function hash(Bet $bet, object $purchase, Ticket $ticket, string $financialKey): string
    {
        if (DB::transactionLevel() < 1) {
            $this->refuse((int) $bet->id);
        }
        $row = Ticket::withTrashed()->select('tickets.*')->selectRaw('CAST(total_amount AS CHAR) AS exact_total')
            ->whereKey($ticket->id)->lockForUpdate()->first();
        if (! $row || $row->trashed() || (int) $bet->ticket_id !== (int) $row->id
            || (int) $row->user_id !== (int) $bet->user_id || (int) $row->draw_id !== (int) $bet->draw_id
            || $row->currency !== $bet->currency || ! $row->issued_at || ! $row->confirmed_at
            || ! $row->uuid || ! $row->ticket_number || $row->total_bets !== 1 || $row->total_numbers !== 1
            || bccomp((string) $row->exact_total, $purchase->stake_amount, 2) !== 0
            || Bet::withTrashed()->where('ticket_id', $row->id)->where('id', '!=', $bet->id)->lockForUpdate()->exists()) {
            $this->refuse((int) $bet->id);
        }

        return hash('sha256', json_encode(['purchase-receipt-v1', $purchase->id, $purchase->bet_id, $purchase->wallet_id,
            $purchase->original_transaction_id, $purchase->number_limit_id, $purchase->currency, $purchase->stake_amount,
            $purchase->potential_payout, $purchase->terms_hash, $purchase->original_hash,
            $purchase->commission_snapshot_hash, $purchase->commission_snapshot_count, $financialKey,
            $row->id, $row->uuid, $row->ticket_number, $row->user_id, $row->draw_id, $row->currency->value,
            bcadd((string) $row->exact_total, '0', 2), $row->total_bets, $row->total_numbers,
            $row->issued_at->toDateTimeString(), $row->confirmed_at->toDateTimeString(), $row->metadata], JSON_THROW_ON_ERROR));
    }

    /** Only called within a NEW fully paid purchase commit, after ownership and commissions. */
    public function record(Bet $bet, Wallet $wallet, FinancialTransaction $original, Ticket $ticket): void
    {
        if (DB::transactionLevel() < 1 || ! $bet->wasRecentlyCreated || ! $original->wasRecentlyCreated
            || ! $ticket->wasRecentlyCreated || $ticket->status !== TicketStatus::Confirmed) {
            $this->refuse((int) $bet->id);
        }
        $purchase = app(BetPurchaseOwnership::class)->funded($bet, $wallet);
        if ((int) $purchase->original_transaction_id !== (int) $original->id || $purchase->state !== 'active') {
            $this->refuse((int) $bet->id);
        }
        DB::table('bet_purchase_receipts')->insert(['purchase_ownership_id' => $purchase->id, 'ticket_id' => $ticket->id,
            'original_transaction_id' => $original->id, 'original_financial_key' => $original->idempotency_key,
            'receipt_hash' => $this->hash($bet, $purchase, $ticket, $original->idempotency_key), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function verify(Bet $bet, object $purchase, Ticket $ticket, FinancialTransaction $original): object
    {
        $receipt = DB::table('bet_purchase_receipts')->where('purchase_ownership_id', $purchase->id)->lockForUpdate()->first();
        if (! $receipt || (int) $receipt->ticket_id !== (int) $ticket->id
            || (int) $receipt->original_transaction_id !== (int) $original->id
            || $receipt->original_financial_key !== $original->idempotency_key
            || ! hash_equals($receipt->receipt_hash, $this->hash($bet, $purchase, $ticket, $receipt->original_financial_key))) {
            $this->refuse((int) $bet->id);
        }

        return $receipt;
    }
}
