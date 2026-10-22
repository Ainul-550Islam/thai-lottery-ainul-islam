<?php

declare(strict_types=1);

namespace App\Jobs\Draw;

use App\Models\Bet;
use App\Services\Draw\ChunkedSettlementOrchestrator;
use App\Services\Draw\RealPrizeSettlementService;
use App\Services\Draw\SelectionSettlementResolver;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Settlement of ONE bounded range of bet ids, in its own transaction.
 *
 * TRANSACTION BOUNDARY IS THE WHOLE POINT
 * Each instance opens its own transaction and touches only its own id range.
 * A failure rolls back this chunk and nothing else; the other chunks keep their
 * committed work. Compare the inline path, where a single constraint violation
 * at bet 90,000 rolls back 89,999 already-computed payouts.
 *
 * IDEMPOTENT BY CONSTRUCTION
 * Payout credits go through WalletService with the per-bet idempotency key
 * `payout:{drawId}:{betId}`, so replaying a chunk (a retry, a manual re-run, a
 * double dispatch) cannot pay twice. `tries` is bounded and the job is NOT
 * released indefinitely, because an infinite retry on a money job is its own
 * hazard: a permanently failing chunk must surface as a failed batch.
 */
final class SettleDrawChunkJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * Backoff in seconds. A lock wait or deadlock is transient; retrying after
     * 5s/30s/120s lets the competing transaction finish rather than immediately
     * re-colliding.
     *
     * @var list<int>
     */
    public array $backoff = [5, 30, 120];

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $drawId,
        public readonly string $batchId,
        public readonly int $chunkIndex,
        public readonly int $fromBetId,
        public readonly int $toBetId,
        public readonly string $resultFingerprint,
    ) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        // Two chunks must never contend for the same bet rows. The id ranges are
        // already disjoint, so this guards against a duplicated dispatch of the
        // SAME chunk rather than against its neighbours.
        return [
            (new WithoutOverlapping("settle-draw:{$this->drawId}:chunk:{$this->chunkIndex}"))
                ->expireAfter(300),
        ];
    }

    public function handle(
        RealPrizeSettlementService $settlement,
        SelectionSettlementResolver $resolver,
    ): void {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $settled = 0;
        $skipped = 0;

        Bet::query()
            ->where('draw_id', $this->drawId)
            ->whereNull('deleted_at')
            ->whereBetween('id', [$this->fromBetId, $this->toBetId])
            ->whereIn('status', ChunkedSettlementOrchestrator::settleableStatuses())
            ->orderBy('id')
            // chunkById inside the range keeps memory flat even when the chunk
            // size is misconfigured upward: the page size, not the range size,
            // bounds resident models.
            ->chunkById(200, function ($bets) use ($settlement, $resolver, &$settled, &$skipped): void {
                foreach ($bets as $bet) {
                    // Re-assert the fingerprint per row. The result row is
                    // immutable once published, but if that ever changed, a
                    // settlement computed against a different number than the
                    // one frozen into the batch plan would be a silent
                    // mispayout. Cheap to check; catastrophic to omit.
                    if (! $this->fingerprintStillMatches()) {
                        throw new \RuntimeException(sprintf(
                            'Draw %d result fingerprint changed mid-settlement (expected %s); aborting chunk %d.',
                            $this->drawId,
                            $this->resultFingerprint,
                            $this->chunkIndex,
                        ));
                    }

                    $settled += $resolver->settleOneBet($settlement, $bet, $this->drawId) ? 1 : 0;
                }
            });

        Log::info('settlement.chunk_complete', [
            'draw_id' => $this->drawId,
            'batch_id' => $this->batchId,
            'chunk_index' => $this->chunkIndex,
            'from_bet_id' => $this->fromBetId,
            'to_bet_id' => $this->toBetId,
            'settled' => $settled,
            'skipped' => $skipped,
        ]);
    }

    private function fingerprintStillMatches(): bool
    {
        $fingerprint = \App\Models\DrawResult::query()
            ->where('draw_id', $this->drawId)
            ->value('result_fingerprint');

        return is_string($fingerprint)
            && hash_equals($this->resultFingerprint, $fingerprint);
    }

    /**
     * @return array<string, mixed>
     */
    public function tags(): array
    {
        return [
            'settlement',
            'draw:'.$this->drawId,
            'batch:'.$this->batchId,
            'chunk:'.$this->chunkIndex,
        ];
    }
}
