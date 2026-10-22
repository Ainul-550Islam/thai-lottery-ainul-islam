<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\Enums\BetStatus;
use App\Models\Bet;
use App\Models\BetItem;
use App\Models\FinancialTransaction;
use App\Models\NumberLimit;
use App\Models\Wallet;
use App\Services\Agent\BetCommissionCancellation;
use App\Services\Risk\BetRiskOwnershipFloor;
use Illuminate\Support\Facades\DB;

/** Established only by a newly funded atomic purchase; no adoption/backfill API. */
final class BetPurchaseOwnership
{
    public function __construct(private readonly BetAccountingProof $proof, private readonly BetRiskOwnershipFloor $floor) {}

    public function terms(Bet $bet, bool $refundable = true): array
    {
        if (DB::transactionLevel() < 1) {
            $this->proof->refuse();
        }
        $row = Bet::withTrashed()->select('bets.*')->selectRaw('CAST(stake_amount AS CHAR) AS exact_stake, CAST(potential_payout AS CHAR) AS exact_payout')
            ->whereKey($bet->id)->lockForUpdate()->first();
        $items = BetItem::withTrashed()->select('bet_items.*')->selectRaw('CAST(amount AS CHAR) AS exact_stake, CAST(potential_payout AS CHAR) AS exact_payout')
            ->where('bet_id', $bet->id)->orderBy('id')->lockForUpdate()->get();
        if (! $row || $row->trashed() || $items->count() !== 1 || $items->first()->trashed() || ! $row->placed_at
            || $row->idempotency_key === null || ! $row->ticket_id) {
            $this->proof->refuse();
        }
        $item = $items->first();
        $stake = $this->proof->decimal(bcadd((string) $row->exact_stake, '0', 2), true);
        $payout = $this->proof->decimal(bcadd((string) $row->exact_payout, '0', 2), true);
        if (bccomp($stake, (string) $item->exact_stake, 2) !== 0 || bccomp($payout, (string) $item->exact_payout, 2) !== 0
            || ($refundable && ($item->is_winner || bccomp((string) $item->actual_payout, '0', 2) !== 0))
            || bccomp(bcmul($stake, (string) $item->payout_multiplier, 2), $payout, 2) !== 0) {
            $this->proof->refuse();
        }
        $hash = hash('sha256', json_encode([$row->id, $row->uuid, $row->bet_number, $row->user_id,
            $row->draw_id, $row->ticket_id, $row->type->value, $row->currency->value, $stake, $payout,
            $row->total_numbers, $row->idempotency_key, $row->placed_at->toDateTimeString(), $row->metadata,
            $item->id, $item->number, $item->position, $item->payout_multiplier, $item->metadata], JSON_THROW_ON_ERROR));

        return [$hash, $stake, $payout, $item];
    }

    public function record(Bet $bet, Wallet $wallet, FinancialTransaction $transaction, array $reservation): void
    {
        // Fresh writer-owned model instances are required in addition to the
        // durable UNIQUE constraints and exact original proof. A loaded legacy
        // purchase cannot be enrolled by a reconciliation/replay call.
        if (! $bet->wasRecentlyCreated || ! $transaction->wasRecentlyCreated) {
            $this->proof->refuse();
        }
        [$hash, $stake, $payout, $item] = $this->terms($bet);
        [$original, $originalHash] = $this->proof->transaction((int) $transaction->id, $bet, $wallet);
        $limit = NumberLimit::query()->whereKey($reservation['number_limit_id'] ?? 0)->lockForUpdate()->first();
        if ($bet->status !== BetStatus::Active || ! $limit || (int) $limit->draw_id !== (int) $bet->draw_id
            || $limit->bet_type !== $bet->type || $limit->number !== $item->number
            || ($reservation['reserved'] ?? false) !== true || ($reservation['stake'] ?? null) !== $stake
            || ($reservation['potential_payout'] ?? null) !== $payout
            || ($original->metadata['bet_idempotency_key'] ?? null) !== $bet->idempotency_key
            || (int) ($original->metadata['ticket_id'] ?? 0) !== (int) $bet->ticket_id
            || (int) ($original->metadata['draw_id'] ?? 0) !== (int) $bet->draw_id
            || ($original->metadata['number'] ?? null) !== $item->number) {
            $this->proof->refuse();
        }
        $purchaseId = DB::table('bet_purchase_ownerships')->insertGetId([
            'bet_id' => $bet->id, 'wallet_id' => $wallet->id, 'number_limit_id' => $limit->id,
            'original_transaction_id' => $original->id, 'currency' => $wallet->currency->value,
            'stake_amount' => $stake, 'potential_payout' => $payout, 'terms_hash' => $hash, 'original_hash' => $originalHash,
            'state' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(BetCommissionCancellation::class)->capture($bet, (int) $purchaseId);
        $this->floor->assertCovered($limit);
    }

    /** Historical original-funding fact ONLY; never cancellation, payout or current-balance authority. */
    public function funded(Bet $bet, Wallet $wallet): object
    {
        return $this->funding($bet, $wallet, false);
    }

    private function funding(Bet $bet, Wallet $wallet, bool $refundable): object
    {
        $claim = DB::table('bet_purchase_ownerships')->where('bet_id', $bet->id)->lockForUpdate()->first();
        if (! $claim || (int) $claim->wallet_id !== (int) $wallet->id || $claim->currency !== $wallet->currency->value) {
            $this->proof->refuse();
        }
        [$hash, $stake, $payout] = $this->terms($bet, $refundable);
        [, $originalHash] = $this->proof->transaction((int) $claim->original_transaction_id, $bet, $wallet);
        if (! hash_equals($claim->terms_hash, $hash) || ! hash_equals($claim->original_hash, $originalHash)
            || $stake !== $claim->stake_amount || $payout !== $claim->potential_payout) {
            $this->proof->refuse();
        }

        return $claim;
    }

    public function locked(Bet $bet, Wallet $wallet): object
    {
        $claim = $this->funding($bet, $wallet, true);
        // All matching histories count, not just the newest non-deleted refund.
        $references = FinancialTransaction::withTrashed()->whereIn('reference_type', [Bet::class, 'bet'])
            ->where('reference_id', $bet->id)->orderBy('id')->lockForUpdate()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $expected = [(int) $claim->original_transaction_id];
        if ($claim->refund_transaction_id !== null) {
            $expected[] = (int) $claim->refund_transaction_id;
        }
        sort($expected);
        if ($references !== $expected) {
            $this->proof->refuse();
        }

        return $claim;
    }
}
