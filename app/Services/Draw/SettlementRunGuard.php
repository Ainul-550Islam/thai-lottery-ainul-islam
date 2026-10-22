<?php

declare(strict_types=1);

namespace App\Services\Draw;

use App\DTOs\DrawResultData;
use App\Enums\BetStatus;
use App\Models\Bet;
use App\Models\Draw;
use App\Models\SettlementRun;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Immutable input proof for a persisted run; no wallet writes or lifecycle changes. */
final class SettlementRunGuard
{
    public function resultFingerprint(DrawResultData $result): string
    {
        return hash('sha256', json_encode([
            $result->firstPrize(), $result->bottomTwo(), $result->optionalPrizes(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Bounded-memory digest of every participant and selection input, not outcome.
     * Full scans deliberately trade throughput for drift detection. No claim of
     * constant-time validation or 100,000-slip performance is made.
     *
     * @return array{count: int, max_id: int, fingerprint: string}
     */
    public function participants(int $drawId): array
    {
        $hash = hash_init('sha256');
        $count = 0;
        $max = 0;
        foreach (DB::table('bets')->where('draw_id', $drawId)->whereNull('deleted_at')
            ->select(['id', 'draw_id', 'user_id', 'ticket_id', 'type', 'currency', 'stake_amount', 'potential_payout', 'total_numbers', 'metadata'])
            ->orderBy('id')->lazyById(200) as $bet) {
            hash_update($hash, 'bet:'.json_encode($bet, JSON_THROW_ON_ERROR)."\n");
            $count++;
            $max = (int) $bet->id;
        }
        foreach (DB::table('bet_items')->join('bets', 'bets.id', '=', 'bet_items.bet_id')
            ->where('bets.draw_id', $drawId)->whereNull('bets.deleted_at')->whereNull('bet_items.deleted_at')
            ->select(['bet_items.id', 'bet_items.bet_id', 'bet_items.number', 'bet_items.position', 'bet_items.amount', 'bet_items.payout_multiplier', 'bet_items.potential_payout', 'bet_items.metadata'])
            ->orderBy('bet_items.id')->lazyById(200, 'bet_items.id', 'id') as $item) {
            hash_update($hash, 'item:'.json_encode($item, JSON_THROW_ON_ERROR)."\n");
        }

        return ['count' => $count, 'max_id' => $max, 'fingerprint' => hash_final($hash)];
    }

    public function assertPinned(Draw $draw, SettlementRun $run, DrawResultData $result): void
    {
        $facts = $this->participants((int) $draw->id);
        if ($run->mode !== RealPrizeSettlementService::MODE
            || ! in_array($run->safety_mode, ['DISABLED', 'DRY_RUN', 'LIVE'], true)
            || ! is_string($run->result_fingerprint)
            || ! is_string($run->participants_fingerprint)
            || ! hash_equals($run->result_fingerprint, $this->resultFingerprint($result))
            || ! hash_equals($run->participants_fingerprint, $facts['fingerprint'])
            || $facts['count'] !== (int) $run->bets_total
            || $facts['max_id'] !== (int) $run->max_bet_id
            || $run->cursor_bet_id < 0 || $run->cursor_bet_id > $run->max_bet_id
            || $run->chunk_size < 1 || $run->chunk_size > 5000) {
            throw new RuntimeException('Settlement run input proof changed or is missing; refusing settlement.');
        }
        if (Bet::query()->where('draw_id', $draw->id)
            ->whereNotIn('status', [BetStatus::Active->value, BetStatus::Won->value, BetStatus::Lost->value])->exists()) {
            throw new RuntimeException('Settlement contains an ineligible participant; refusing settlement.');
        }
        $this->approvalThreshold((string) $run->approval_threshold);
    }

    public function approvalThreshold(?string $value = null): string
    {
        $value ??= config('finance.prize_payout.auto_approve_below', '1000000.00');
        if (! is_string($value) || ! preg_match('/\A\d{1,16}(?:\.\d{1,2})?\z/D', $value)) {
            throw new RuntimeException('Settlement approval threshold is invalid; refusing settlement.');
        }

        return bcadd($value, '0', 2);
    }
}
