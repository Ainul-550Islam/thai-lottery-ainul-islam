<?php

declare(strict_types=1);

namespace App\Services\Draw;

use App\DTOs\DrawResultData;
use App\Enums\AuditAction;
use App\Enums\BetStatus;
use App\Enums\Currency;
use App\Enums\DrawStatus;
use App\Enums\PayoutStatus;
use App\Enums\RiskLevel;
use App\Jobs\Draw\FinalizeDrawSettlementJob;
use App\Jobs\Draw\SettleDrawChunkJob;
use App\Models\AuditLog;
use App\Models\Bet;
use App\Models\BetItem;
use App\Models\Draw;
use App\Models\Payout;
use App\Models\SettlementRun;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Chunked, resumable settlement orchestration for large draws.
 */
final class ChunkedSettlementOrchestrator
{
    public const MIN_CHUNK_SIZE = 50;

    public const MAX_CHUNK_SIZE = 5000;

    public function __construct(
        private readonly DrawLifecycleService $lifecycle,
        private readonly RealPrizeSettlementService $settlement,
        private readonly SettlementRunGuard $guard,
        private readonly SelectionSettlementResolver $selections,
    ) {}

    public function defaultChunkSize(): int
    {
        $configured = (int) config('lottery.settlement.chunk_size', 500);

        return max(self::MIN_CHUNK_SIZE, min(self::MAX_CHUNK_SIZE, $configured));
    }

    public function chunkThreshold(): int
    {
        return max(100, (int) config('lottery.settlement.chunk_threshold', 2000));
    }

    public function chunkSize(): int
    {
        return max(10, (int) config('lottery.settlement.chunk_size', 500));
    }

    public function runFor(int $drawId): ?SettlementRun
    {
        return SettlementRun::query()->where('draw_id', $drawId)->first();
    }

    public function start(int $drawId, ?int $chunkSize = null, ?int $actorId = null): SettlementRun
    {
        if (DB::transactionLevel() > 0) {
            throw new RuntimeException('Cannot start a chunked settlement run inside an existing transaction.');
        }

        $existing = $this->runFor($drawId);
        if ($existing instanceof SettlementRun) {
            return $existing;
        }

        $size = $chunkSize ?? $this->defaultChunkSize();
        if ($size < 1 || $size > self::MAX_CHUNK_SIZE) {
            throw new RuntimeException('Chunk size must be between 1 and 5000.');
        }

        $draw = Draw::query()->find($drawId);
        if (! $draw instanceof Draw) {
            throw new RuntimeException(sprintf('Draw %d does not exist.', $drawId));
        }

        $result = $this->settlement->assertChunkable($draw);

        return DB::transaction(function () use ($drawId, $size, $actorId, $result): SettlementRun {
            Draw::query()->whereKey($drawId)->lockForUpdate()->firstOrFail();

            $existing = SettlementRun::query()->where('draw_id', $drawId)->lockForUpdate()->first();
            if ($existing instanceof SettlementRun) {
                return $existing;
            }

            $facts = $this->guard->participants($drawId);

            if (Bet::query()->where('draw_id', $drawId)
                ->whereNotIn('status', [BetStatus::Active->value, BetStatus::Won->value, BetStatus::Lost->value])
                ->exists()) {
                throw new RuntimeException('Settlement contains an ineligible participant; refusing settlement.');
            }

            $approvalThreshold = $this->guard->approvalThreshold();
            $resultFingerprint = $this->guard->resultFingerprint($result);

            $run = new SettlementRun;
            $run->forceFill([
                'draw_id' => $drawId,
                'status' => SettlementRun::STATUS_OPEN,
                'chunk_size' => $size,
                'cursor_bet_id' => 0,
                'bets_total' => $facts['count'],
                'bets_settled' => 0,
                'selections_evaluated' => 0,
                'selections_written' => 0,
                'winning_selections' => 0,
                'payouts_created' => 0,
                'total_stake' => '0.00',
                'total_prize' => '0.00',
                'currency' => null,
                'mode' => RealPrizeSettlementService::MODE,
                'safety_mode' => $this->settlement->safetyMode(),
                'result_fingerprint' => $resultFingerprint,
                'participants_fingerprint' => $facts['fingerprint'],
                'max_bet_id' => $facts['max_id'],
                'approval_threshold' => $approvalThreshold,
                'chunks_processed' => 0,
                'started_at' => now(),
                'started_by' => $actorId,
            ]);
            $run->save();

            return $run;
        });
    }

    public function reopen(SettlementRun $run): SettlementRun
    {
        if (DB::transactionLevel() > 0) {
            throw new RuntimeException('Cannot reopen a settlement run inside an existing transaction.');
        }

        return DB::transaction(function () use ($run): SettlementRun {
            $draw = Draw::query()->whereKey((int) $run->draw_id)->lockForUpdate()->firstOrFail();
            $lockedRun = SettlementRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedRun->isComplete()) {
                return $lockedRun;
            }

            $result = $this->settlement->publishedResultFor($draw);
            $this->guard->assertPinned($draw, $lockedRun, $result);

            $lockedRun->status = SettlementRun::STATUS_OPEN;
            $lockedRun->failure_reason = null;
            $lockedRun->save();

            return $lockedRun;
        });
    }

    public function runChunk(SettlementRun $run, ?int $expectedCursor = null): SettlementRun
    {
        if (DB::transactionLevel() > 0) {
            throw new RuntimeException('Each settlement chunk must own its own database transaction.');
        }

        $requestedCursor = $expectedCursor ?? (int) $run->cursor_bet_id;

        return DB::transaction(function () use ($run, $expectedCursor, $requestedCursor): SettlementRun {
            $draw = Draw::query()->whereKey((int) $run->draw_id)->lockForUpdate()->firstOrFail();
            $lockedRun = SettlementRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedRun->isComplete() || $lockedRun->status !== SettlementRun::STATUS_OPEN) {
                return $lockedRun;
            }

            if ($expectedCursor !== null && (int) $lockedRun->cursor_bet_id !== $expectedCursor) {
                return $lockedRun;
            }

            $result = $this->settlement->publishedResultFor($draw);
            $this->guard->assertPinned($draw, $lockedRun, $result);

            $afterBetId = min($requestedCursor, (int) $lockedRun->cursor_bet_id);
            $limit = max(1, (int) $lockedRun->chunk_size);
            $effectiveSafety = $this->effectiveSafetyMode($lockedRun);
            $currencySoFar = is_string($lockedRun->currency) && $lockedRun->currency !== ''
                ? Currency::tryFrom($lockedRun->currency)
                : null;

            $stats = $this->settlement->settleBetRange(
                draw: $draw,
                result: $result,
                afterBetId: $afterBetId,
                limit: $limit,
                safetyMode: $effectiveSafety,
                currencySoFar: $currencySoFar,
                pinnedApprovalThreshold: $lockedRun->approval_threshold !== null ? (string) $lockedRun->approval_threshold : null,
                allowCallerTransaction: true,
            );

            $previousCursor = (int) $lockedRun->cursor_bet_id;
            $newCursor = max($previousCursor, (int) $stats['last_bet_id']);
            $advanced = $newCursor > $previousCursor || ($previousCursor === 0 && (int) $lockedRun->bets_total === 0);

            $reconciled = $this->reconcileProgress((int) $draw->getKey(), $newCursor);

            $lockedRun->cursor_bet_id = $newCursor;
            $lockedRun->bets_settled = $reconciled['bets_settled'];
            $lockedRun->selections_evaluated = $reconciled['selections_evaluated'];
            $lockedRun->selections_written = $reconciled['selections_written'];
            $lockedRun->winning_selections = $reconciled['winning_selections'];
            $lockedRun->payouts_created = $reconciled['payouts_created'];
            $lockedRun->total_stake = $reconciled['total_stake'];
            $lockedRun->total_prize = $reconciled['total_prize'];
            if ($stats['currency'] instanceof Currency) {
                $lockedRun->currency = $stats['currency']->value;
            } elseif ($reconciled['currency'] !== null) {
                $lockedRun->currency = $reconciled['currency'];
            }
            if ($advanced) {
                $lockedRun->chunks_processed = (int) $lockedRun->chunks_processed + 1;
            }
            $lockedRun->last_chunk_at = now();
            $lockedRun->save();

            return $lockedRun;
        });
    }

    public function run(int $drawId, ?int $chunkSize = null, ?int $actorId = null): SettlementRun
    {
        $run = $this->start($drawId, $chunkSize, $actorId);

        if ($run->isComplete()) {
            return $run;
        }

        if ($run->status === SettlementRun::STATUS_ABORTED) {
            $run = $this->reopen($run);
        }

        while ($this->hasMoreWork($run)) {
            $run = $this->runChunk($run);
        }

        return $this->finalize($run);
    }

    public function resume(int $drawId): ?SettlementRun
    {
        $run = $this->runFor($drawId);
        if (! $run instanceof SettlementRun) {
            return null;
        }

        if ($run->isComplete()) {
            return $run;
        }

        $run = $this->reopen($run);

        while ($this->hasMoreWork($run)) {
            $run = $this->runChunk($run);
        }

        return $this->finalize($run);
    }

    public function finalize(SettlementRun $run): SettlementRun
    {
        if (DB::transactionLevel() > 0) {
            throw new RuntimeException('Cannot finalize a settlement run inside an existing transaction.');
        }

        return DB::transaction(function () use ($run): SettlementRun {
            $lockedRun = SettlementRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedRun->status === SettlementRun::STATUS_ABORTED) {
                throw new RuntimeException('cannot finalize an aborted settlement run');
            }

            $draw = Draw::query()->findOrFail((int) $lockedRun->draw_id);
            $this->assertCompletionProof($draw, $lockedRun);

            if ($lockedRun->isComplete()) {
                if ($draw->status !== DrawStatus::Completed) {
                    throw new RuntimeException(sprintf(
                        'cannot finalize settlement: completed run %d has draw %d in non-settled status %s.',
                        (int) $lockedRun->getKey(),
                        (int) $draw->getKey(),
                        $draw->status->value,
                    ));
                }

                return $lockedRun;
            }

            $lockedRun->status = SettlementRun::STATUS_COMPLETED;
            $lockedRun->completed_at = now();
            $lockedRun->save();

            $this->settlement->finalizeChunkedSettlement($draw, $lockedRun->totals(), true);

            $log = new AuditLog;
            $log->fill([
                'user_id' => $lockedRun->started_by,
                'action' => AuditAction::Payout,
                'risk_level' => RiskLevel::High,
                'auditable_type' => Draw::class,
                'auditable_id' => (int) $draw->getKey(),
                'description' => sprintf(
                    'Finalized chunked settlement run #%d for draw %s (%d bets, %s %s total prize).',
                    (int) $lockedRun->getKey(),
                    (string) $draw->draw_number,
                    (int) $lockedRun->bets_settled,
                    (string) $lockedRun->total_prize,
                    (string) ($lockedRun->currency ?? 'THB'),
                ),
                'metadata' => [
                    'draw_id' => (int) $draw->getKey(),
                    'run_id' => (int) $lockedRun->getKey(),
                    'bets_settled' => (int) $lockedRun->bets_settled,
                    'payouts_created' => (int) $lockedRun->payouts_created,
                    'total_prize' => (string) $lockedRun->total_prize,
                    'safety_mode' => (string) $lockedRun->safety_mode,
                ],
            ]);
            $log->save();

            return $lockedRun;
        });
    }

    public function assertCompletionProof(Draw $draw, SettlementRun $run): void
    {
        $drawId = (int) $draw->getKey();
        $result = $this->settlement->publishedResultFor($draw);
        $this->guard->assertPinned($draw, $run, $result);

        if ($this->remainingBets($run) > 0) {
            throw new RuntimeException(sprintf(
                'cannot finalize settlement run %d for draw %d while %d bet(s) remain past cursor %d',
                (int) $run->getKey(),
                $drawId,
                $this->remainingBets($run),
                (int) $run->cursor_bet_id,
            ));
        }

        $bets = Bet::query()
            ->with(['ticket', 'user'])
            ->where('draw_id', $drawId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();

        if ($bets->count() !== (int) $run->bets_total || (int) $run->bets_settled !== (int) $run->bets_total) {
            throw new RuntimeException('cannot finalize settlement: settled bet count does not match total participants.');
        }

        $totalStake = '0.00';
        $totalPrize = '0.00';
        $selectionsEvaluated = 0;
        $winningSelections = 0;
        $expectedPayouts = 0;

        foreach ($bets as $bet) {
            if (! in_array($bet->status, [BetStatus::Won, BetStatus::Lost], true)) {
                throw new RuntimeException(sprintf('cannot finalize settlement: bet %d has unsettled status %s.', (int) $bet->getKey(), $bet->status->value));
            }

            $items = BetItem::query()
                ->where('bet_id', (int) $bet->getKey())
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->get();

            if ($items->isEmpty()) {
                throw new RuntimeException(sprintf('cannot finalize settlement: bet %d has no selection items.', (int) $bet->getKey()));
            }

            $betPrize = '0.00';
            $betWon = false;

            foreach ($items as $item) {
                $record = $this->selections->resolve($item, $bet, $result);
                if ((bool) $item->is_winner !== $record->isWinner()
                    || bccomp((string) $item->actual_payout, $record->simulatedPrize, 2) !== 0) {
                    throw new RuntimeException(sprintf('cannot finalize settlement: stored outcome drift on bet_item %d.', (int) $item->getKey()));
                }

                $selectionsEvaluated++;
                $totalStake = bcadd($totalStake, $record->stake, 2);
                $totalPrize = bcadd($totalPrize, $record->simulatedPrize, 2);
                $betPrize = bcadd($betPrize, $record->simulatedPrize, 2);
                if ($record->isWinner()) {
                    $betWon = true;
                    $winningSelections++;
                }
            }

            if ($betWon && bccomp($betPrize, '0.00', 2) > 0) {
                $expectedPayouts++;
                $payout = Payout::query()
                    ->where('draw_id', $drawId)
                    ->where('bet_id', (int) $bet->getKey())
                    ->whereNull('deleted_at')
                    ->first();

                if (! $payout instanceof Payout || bccomp((string) $payout->amount, $betPrize, 2) !== 0) {
                    throw new RuntimeException(sprintf('cannot finalize settlement: missing or mismatched payout obligation for bet %d.', (int) $bet->getKey()));
                }
            }
        }

        $actualPayoutCount = Payout::query()->where('draw_id', $drawId)->whereNull('deleted_at')->count();

        if ($actualPayoutCount !== $expectedPayouts
            || (int) $run->payouts_created !== $expectedPayouts
            || (int) $run->selections_evaluated !== $selectionsEvaluated
            || (int) $run->winning_selections !== $winningSelections
            || bccomp((string) $run->total_stake, $totalStake, 2) !== 0
            || bccomp((string) $run->total_prize, $totalPrize, 2) !== 0) {
            throw new RuntimeException('cannot finalize settlement: run totals or payout obligations do not match verified outcomes.');
        }
    }

    public function remainingBets(SettlementRun $run): int
    {
        return (int) Bet::query()
            ->where('draw_id', (int) $run->draw_id)
            ->whereNull('deleted_at')
            ->where('id', '>', (int) $run->cursor_bet_id)
            ->count();
    }

    public function hasMoreWork(SettlementRun $run): bool
    {
        return ! $run->isComplete() && $this->remainingBets($run) > 0;
    }

    public function describe(SettlementRun $run): string
    {
        $pct = $run->percentComplete();

        return sprintf(
            'run=%d draw=%d status=%s safety=%s cursor=%d settled=%d/%d (%s) chunks=%d prize=%s %s',
            (int) $run->getKey(),
            (int) $run->draw_id,
            (string) $run->status,
            (string) ($run->safety_mode ?? 'unknown'),
            (int) $run->cursor_bet_id,
            (int) $run->bets_settled,
            (int) $run->bets_total,
            $pct === null ? 'n/a' : sprintf('%.2f%%', $pct),
            (int) $run->chunks_processed,
            (string) $run->total_prize,
            (string) ($run->currency ?? ''),
        );
    }

    public function finalizeDraw(Draw $draw, string $batchId = ''): Draw
    {
        $run = $this->runFor((int) $draw->getKey());
        if ($run instanceof SettlementRun) {
            $this->finalize($run);

            return $draw->refresh();
        }

        return $this->settlement->finalizeChunkedSettlement($draw);
    }

    /**
     * @return array{
     *     mode: string,
     *     draw_id: int,
     *     participants: int,
     *     chunks: int,
     *     batch_id: string|null
     * }
     */
    public function settle(int $drawId): array
    {
        $participants = $this->countParticipants($drawId);
        $threshold = $this->chunkThreshold();
        $size = $this->chunkSize();

        if ($participants <= $threshold) {
            return [
                'mode' => 'inline',
                'draw_id' => $drawId,
                'participants' => $participants,
                'chunks' => 0,
                'batch_id' => null,
            ];
        }

        $run = $this->start($drawId, $size);
        $batchId = (string) Str::uuid();
        SettleDrawChunkJob::dispatch($drawId, (int) $run->getKey(), (int) $run->cursor_bet_id);

        return [
            'mode' => 'chunked',
            'draw_id' => $drawId,
            'participants' => $participants,
            'chunks' => (int) ceil(max(1, $participants) / $size),
            'batch_id' => $batchId,
        ];
    }

    public function countParticipants(int $drawId): int
    {
        return Bet::query()
            ->where('draw_id', $drawId)
            ->whereNull('deleted_at')
            ->count();
    }

    /**
     * @return list<string>
     */
    public static function settleableStatuses(): array
    {
        return [
            BetStatus::Active->value,
            BetStatus::Won->value,
            BetStatus::Lost->value,
        ];
    }

    private function effectiveSafetyMode(SettlementRun $run): string
    {
        $pinned = strtoupper((string) ($run->safety_mode ?? RealPrizeSettlementService::SAFETY_DISABLED));
        if ($pinned !== RealPrizeSettlementService::SAFETY_LIVE) {
            return $pinned;
        }

        $current = $this->settlement->safetyMode();

        return $current === RealPrizeSettlementService::SAFETY_LIVE
            ? RealPrizeSettlementService::SAFETY_LIVE
            : $current;
    }

    /**
     * @return array{
     *     bets_settled: int,
     *     selections_evaluated: int,
     *     selections_written: int,
     *     winning_selections: int,
     *     payouts_created: int,
     *     total_stake: string,
     *     total_prize: string,
     *     currency: string|null
     * }
     */
    private function reconcileProgress(int $drawId, int $upToBetId): array
    {
        $bets = Bet::query()
            ->where('draw_id', $drawId)
            ->whereNull('deleted_at')
            ->where('id', '<=', $upToBetId)
            ->whereIn('status', [BetStatus::Won->value, BetStatus::Lost->value])
            ->orderBy('id')
            ->get(['id', 'currency']);

        $betIds = $bets->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $firstBet = $bets->first();
        $currency = $firstBet instanceof Bet && $firstBet->currency instanceof Currency
            ? $firstBet->currency->value
            : null;

        if ($betIds === []) {
            return [
                'bets_settled' => 0,
                'selections_evaluated' => 0,
                'selections_written' => 0,
                'winning_selections' => 0,
                'payouts_created' => 0,
                'total_stake' => '0.00',
                'total_prize' => '0.00',
                'currency' => $currency,
            ];
        }

        $items = BetItem::query()
            ->whereIn('bet_id', $betIds)
            ->whereNull('deleted_at')
            ->get(['amount', 'actual_payout', 'is_winner']);

        $totalStake = '0.00';
        $totalPrize = '0.00';
        $winningSelections = 0;

        foreach ($items as $item) {
            $totalStake = bcadd($totalStake, (string) $item->amount, 2);
            $totalPrize = bcadd($totalPrize, (string) ($item->actual_payout ?? '0.00'), 2);
            if ((bool) $item->is_winner) {
                $winningSelections++;
            }
        }

        $payoutsCreated = Payout::query()
            ->where('draw_id', $drawId)
            ->whereIn('bet_id', $betIds)
            ->whereNull('deleted_at')
            ->count();

        return [
            'bets_settled' => count($betIds),
            'selections_evaluated' => $items->count(),
            'selections_written' => $items->count(),
            'winning_selections' => $winningSelections,
            'payouts_created' => $payoutsCreated,
            'total_stake' => $totalStake,
            'total_prize' => $totalPrize,
            'currency' => $currency,
        ];
    }
}
