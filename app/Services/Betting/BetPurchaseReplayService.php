<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\DTOs\BetPurchaseData;
use App\DTOs\BetPurchaseResult;
use App\Exceptions\BetPurchaseException;
use App\Exceptions\BetPurchaseIdempotencyException;
use App\Models\Bet;
use App\Models\FinancialTransaction;
use App\Models\Ticket;
use App\Services\Finance\Money;
use App\Services\Finance\WalletLockService;
use App\ValueObjects\BetAmount;
use Illuminate\Support\Facades\DB;

/** Current locked original-funding replay. Never enroll, debit, reserve, refund or resend. */
final class BetPurchaseReplayService
{
    public function assertRoot(): void
    {
        if (DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()) {
            throw BetPurchaseException::invariantViolated('A purchase or replay must own its root transaction.');
        }
    }

    public function forRequest(BetPurchaseData $data): ?BetPurchaseResult
    {
        $this->assertRoot();
        $keys = app(BetPurchaseIdempotencyService::class);
        $hint = $keys->findExistingBet($keys->betKeyFor($data));

        return $hint ? $this->read($hint, $data) : null;
    }

    public function forBet(Bet $hint): BetPurchaseResult
    {
        $this->assertRoot();

        return $this->read($hint, null);
    }

    private function read(Bet $hint, ?BetPurchaseData $data): BetPurchaseResult
    {
        $proof = app(BetPurchaseReceipt::class);
        $route = DB::table('bet_purchase_ownerships')->where('bet_id', $hint->id)->first();
        if (! $route || ! $hint->ticket_id) {
            $proof->refuse((int) $hint->id);
        }

        return DB::transaction(function () use ($hint, $data, $route, $proof): BetPurchaseResult {
            $wallet = app(WalletLockService::class)->lock((int) $route->wallet_id);
            $ticket = Ticket::withTrashed()->whereKey($hint->ticket_id)->lockForUpdate()->first();
            $bet = Bet::withTrashed()->whereKey($hint->id)->lockForUpdate()->first();
            if (! $bet || $bet->trashed() || ! $ticket || $ticket->trashed()
                || $bet->idempotency_key !== $hint->idempotency_key || (int) $bet->ticket_id !== (int) $ticket->id) {
                $proof->refuse((int) $hint->id);
            }
            $keys = app(BetPurchaseIdempotencyService::class);
            $purchase = app(BetPurchaseOwnership::class)->funded($bet, $wallet);
            if ((int) $purchase->id !== (int) $route->id) {
                $proof->refuse((int) $bet->id);
            }
            $original = FinancialTransaction::withTrashed()->whereKey($purchase->original_transaction_id)->lockForUpdate()->first();
            if (! $original) {
                $proof->refuse((int) $bet->id);
            }
            $receipt = $proof->verify($bet, $purchase, $ticket, $original);
            if ($data !== null && $receipt->original_financial_key !== $keys->financialKeyFor($data)) {
                $proof->refuse((int) $bet->id);
            }
            [, $stake, $payout, $item] = app(BetPurchaseOwnership::class)->terms($bet, false);
            if ($data !== null && ($bet->idempotency_key !== $keys->betKeyFor($data) || $keys->mismatchedFields($bet, $data, $item) !== [])) {
                throw BetPurchaseIdempotencyException::payloadMismatch((string) $bet->idempotency_key, ['original_purchase_request']);
            }
            unset($item['exact_stake'], $item['exact_payout']);

            return BetPurchaseResult::replayed($bet, $item, $ticket, $original,
                BetAmount::fromDatabase($stake, $bet->currency), Money::fromDatabase($payout, $bet->currency),
                (string) $bet->idempotency_key, $keys->ledgerEntryIdsFor($original),
                ['replay_of_bet_id' => (int) $bet->id, 'replay_of_transaction_id' => (int) $original->id,
                    'purchase_receipt_id' => (int) $receipt->id, 'proof_scope' => 'original_funding_only']);
        }, 3);
    }
}
