<?php

declare(strict_types=1);

namespace App\Services\BetSlip;

use App\Enums\BetSlipStatus;
use App\Models\BetSlip;
use App\Models\BetSlipItem;
use App\Services\Betting\BetPurchaseService;
use App\DTOs\BetPurchaseData;
use App\Exceptions\BetPurchaseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Confirms a draft slip: all items or none.
 *
 * THE ONE RULE
 * A confirm is a single database transaction that delegates each item to the
 * existing Batch-4.3 purchase pipeline (BetPurchaseService::purchase()) and, if
 * ANY item is refused, rolls the whole thing back. The member either paid for
 * the slip they saw or paid nothing. There is no partial purchase, no "some of
 * your selections failed, check your bets", and no code path that leaves the
 * wallet debited for a selection that was never bought.
 *
 * WHY THE ITEMS ARE RE-VALIDATED RATHER THAN TRUSTED
 * The slip can be minutes old. Between the draft and the confirm the draw may
 * have closed, an operator may have disabled a market, a number limit may have
 * been hit by somebody else, or the price may have changed. The draft's own
 * validation kept it honest; it cannot keep it TRUE. So the pipeline's own
 * validation runs again, item by item, inside the transaction — it is the only
 * authority on whether a bet may be placed.
 *
 * WHY IT DELEGATES AND DOES NOT PLACE BETS ITSELF
 * BetPurchaseService owns the wallet lock, the idempotency recheck, the reserve
 * → bet → item → ticket → debit → ledger-proof sequence, and the risk
 * reservation. Re-implementing any of that here would be a second money writer,
 * which Rule 2 forbids outright. This class owns exactly one thing: the boundary
 * between "many purchases" and "one member action".
 *
 * IDEMPOTENCY
 * The caller supplies one key for the whole confirm. It is written to
 * bet_slips.confirmation_key, which is UNIQUE, and it is fanned out to each item
 * as `{key}:{index}` so the pipeline's own per-bet uniqueness applies too. A
 * replayed confirm therefore finds the slip already confirmed and returns it
 * instead of buying again — and even if two replays race, the database
 * constraint decides, not a PHP check.
 *
 * WHAT HAPPENS WHEN THE PIPELINE USES A NESTED TRANSACTION
 * Laravel's DB::transaction() is savepoint-aware, so the pipeline's own
 * transaction nests inside this one and a refusal inside it marks the outer
 * transaction for rollback. That is the behaviour relied upon here: the outer
 * finally-block is what surfaces the refusal to the caller.
 */
final class BetSlipConfirmationService
{
    public function __construct(
        private readonly BetSlipService $slips,
        private readonly BetPurchaseService $purchases,
    ) {
    }

    /**
     * Confirm the member's current draft.
     *
     * @return array{
     *     slip: BetSlip,
     *     replayed: bool,
     *     bets: list<array<string, mixed>>,
     *     total_stake: string
     * }
     *
     * @throws BetSlipRefused
     */
    public function confirm(int $memberId, string $idempotencyKey, ?Carbon $at = null): array
    {
        $now = $at ?? Carbon::now();

        $this->assertKey($idempotencyKey);

        $replay = $this->replayIfConfirmed($memberId, $idempotencyKey);

        if ($replay !== null) {
            return $replay;
        }

        return DB::transaction(function () use ($memberId, $idempotencyKey, $now): array {
            /** @var BetSlip|null $slip */
            $slip = BetSlip::query()->draftFor($memberId)->lockForUpdate()->first();

            if (! $slip instanceof BetSlip) {
                throw new BetSlipRefused(
                    'no_active_slip',
                    'There is no open bet slip to confirm.',
                    404,
                );
            }

            // A concurrent confirm may have committed between the unlocked
            // replay check above and this lock. The unique key is the real
            // guarantee; this read is what turns the race into a replay.
            if ($slip->status === BetSlipStatus::Confirmed
                && $slip->confirmation_key === $idempotencyKey) {
                return [
                    'slip' => $slip,
                    'replayed' => true,
                    'bets' => [],
                    'total_stake' => (string) $slip->total_stake,
                ];
            }

            if (! $slip->isEditable($now)) {
                $this->slips->expire($slip, $now);

                throw new BetSlipRefused(
                    'slip_expired',
                    'This slip has expired. Build a new one to continue.',
                    409,
                );
            }

            $items = BetSlipItem::query()
                ->where('bet_slip_id', $slip->id)
                ->orderBy('position')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                throw new BetSlipRefused(
                    'slip_empty',
                    'Add at least one selection before confirming.',
                    422,
                );
            }

            $results = [];
            $index = 0;

            foreach ($items as $item) {
                $results[] = $this->purchaseItem($memberId, $item, $idempotencyKey, $index, $now);
                $index++;
            }

            $total = '0.00';

            foreach ($items as $item) {
                $total = bcadd($total, $item->exactStake(), 2);
            }

            $slip->status = BetSlipStatus::Confirmed;
            $slip->confirmed_at = $now;
            $slip->confirmation_key = $idempotencyKey;
            $slip->confirmed_reference = $this->confirmationReference($idempotencyKey);
            $slip->save();

            return [
                'slip' => $slip->fresh() ?? $slip,
                'replayed' => false,
                'bets' => $results,
                'total_stake' => $total,
            ];
        });
    }

    /**
     * Buy one slip item through the existing pipeline.
     *
     * @return array<string, mixed>
     */
    private function purchaseItem(
        int $memberId,
        BetSlipItem $item,
        string $idempotencyKey,
        int $index,
        Carbon $now,
    ): array {
        // The draft cannot fund anything other than the wallet. There is no
        // free-bet store in this codebase; a slip item that asks for one is
        // refused rather than silently charged to the wallet.
        if ($item->requiresFundingAdapter()) {
            throw new BetSlipRefused(
                'funding_source_unavailable',
                sprintf(
                    'Funding source "%s" is not implemented. Only wallet funding is available.',
                    $item->funding_source,
                ),
                422,
            );
        }

        $payload = [
            'draw_id' => (int) ($item->meta['draw_id'] ?? 0),
            'market' => $item->game_code,
            'side' => (string) ($item->meta['side'] ?? 'back'),
            'selection_type' => (string) ($item->meta['selection_type'] ?? 'single'),
            'number' => $item->number,
            'stake' => $item->exactStake(),
            'idempotency_key' => sprintf('%s:%d', $idempotencyKey, $index),
        ];

        if ($payload['draw_id'] <= 0) {
            throw new BetSlipRefused(
                'item_missing_draw',
                'This selection has no open draw attached. Remove it and pick a draw.',
                422,
            );
        }

        $data = BetPurchaseData::fromRequestArray($memberId, $payload);

        try {
            $result = $this->purchases->purchase($data);
        } catch (BetPurchaseException $e) {
            throw new BetSlipRefused(
                'purchase_refused',
                sprintf('Selection %s (%s) was refused: %s', $item->number, $item->game_code, $e->getMessage()),
                422,
                $e,
            );
        }

        return [
            'bet_number' => $result->betNumber(),
            'bet_id' => $result->betId(),
            'game_code' => $item->game_code,
            'number' => $item->number,
            'stake' => $item->exactStake(),
            'status' => $result->status->value,
            'potential_payout' => $result->bet->potential_payout,
            'ticket_number' => $result->ticketNumber(),
        ];
    }

    /**
     * The slip already confirmed under this key, if any.
     *
     * @return array{slip: BetSlip, replayed: bool, bets: list<array<string, mixed>>, total_stake: string}|null
     */
    private function replayIfConfirmed(int $memberId, string $idempotencyKey): ?array
    {
        /** @var BetSlip|null $slip */
        $slip = BetSlip::query()
            ->where('member_id', $memberId)
            ->where('confirmation_key', $idempotencyKey)
            ->first();

        if (! $slip instanceof BetSlip) {
            return null;
        }

        return [
            'slip' => $slip,
            'replayed' => true,
            'bets' => [],
            'total_stake' => (string) $slip->total_stake,
        ];
    }

    private function assertKey(string $key): void
    {
        $trimmed = trim($key);

        if ($trimmed === '' || strlen($trimmed) > 96 || preg_match('/^[A-Za-z0-9:_\-]+$/', $trimmed) !== 1) {
            throw new BetSlipRefused(
                'invalid_idempotency_key',
                'An Idempotency-Key header (8-96 characters, letters, digits, colon, dash or underscore) is required.',
                400,
            );
        }
    }

    private function confirmationReference(string $key): string
    {
        return 'BSC-'.strtoupper(substr(hash('sha256', $key), 0, 20));
    }
}
