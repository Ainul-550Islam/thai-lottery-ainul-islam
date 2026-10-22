<?php

declare(strict_types=1);

namespace App\Jobs\Draw;

use App\Models\Draw;
use App\Services\Draw\ChunkedSettlementOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Final step of a batched chunked settlement.
 *
 * Dispatched only from the batch `then` callback in
 * ChunkedSettlementOrchestrator::settle() — that is, only after every chunk
 * job in the batch has reported success. Its job is singular: close the draw.
 *
 * It refuses, loudly, when work remains past the run cursor: a draw must
 * never be transitioned to Settled while a slip is unpaid, because Settled
 * is terminal in this project and nothing reversal-shaped exists. A failure
 * here is therefore a page, not a retry loop — the queued job fails, the
 * batch is marked failed, and the draw stays exactly where an operator can
 * resume it.
 */
final class FinalizeDrawSettlementJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $drawId,
        public readonly string $batchId,
    ) {}

    public function handle(ChunkedSettlementOrchestrator $orchestrator): void
    {
        $draw = Draw::query()->find($this->drawId);

        if (! $draw instanceof Draw) {
            Log::error('settlement.finalize_draw_missing', [
                'draw_id' => $this->drawId,
                'batch_id' => $this->batchId,
            ]);

            throw new \RuntimeException(sprintf('Draw %d does not exist; cannot finalize its settlement.', $this->drawId));
        }

        $run = $orchestrator->runFor($this->drawId);

        if ($run !== null) {
            // The run is the authority on progress: finalize refuses while
            // bets remain past its cursor.
            $orchestrator->finalize($run);
        } else {
            // Batch-plan path (no settlement_runs row): the batch reported
            // every chunk successful, so the orchestrator's remaining-bets
            // check against the frozen participant set is the guard.
            $orchestrator->finalizeDraw($draw, $this->batchId);
        }

        Log::info('settlement.finalized', [
            'draw_id' => $this->drawId,
            'batch_id' => $this->batchId,
        ]);
    }
}
