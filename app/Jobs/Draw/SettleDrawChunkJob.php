<?php

declare(strict_types=1);

namespace App\Jobs\Draw;

use App\Enums\QueueName;
use App\Jobs\Draw\Concerns\HasSettlementRecoveryWindow;
use App\Models\SettlementRun;
use App\Services\Draw\ChunkedSettlementOrchestrator;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * Settlement of ONE bounded range of bet ids, in its own transaction.
 */
final class SettleDrawChunkJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use HasSettlementRecoveryWindow;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $maxExceptions = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [5, 30, 120];

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $drawId,
        public readonly int $runId,
        public readonly ?int $expectedCursor = null,
    ) {
        $this->onQueue(QueueName::FinancialCritical->value);
        $this->afterCommit();
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('settlement-draw:'.$this->drawId))
                ->shared()
                ->expireAfter(300)
                ->releaseAfter(10),
        ];
    }

    public function handle(ChunkedSettlementOrchestrator $orchestrator): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        if ($this->expectedCursor === null) {
            throw new RuntimeException('Settlement chunk delivery requires an expected cursor.');
        }

        $run = SettlementRun::query()->find($this->runId);

        if (! $run instanceof SettlementRun || (int) $run->draw_id !== $this->drawId) {
            throw new RuntimeException(sprintf(
                'Settlement run %d does not belong to draw %d.',
                $this->runId,
                $this->drawId,
            ));
        }

        if ($run->isComplete()) {
            $orchestrator->finalize($run);

            return;
        }

        if ($run->status !== SettlementRun::STATUS_OPEN) {
            return;
        }

        $run = $orchestrator->runChunk($run, $this->expectedCursor);

        if ($orchestrator->hasMoreWork($run)) {
            self::dispatch($this->drawId, (int) $run->getKey(), (int) $run->cursor_bet_id);

            return;
        }

        FinalizeDrawSettlementJob::dispatch($this->drawId, (int) $run->getKey());
    }
}
