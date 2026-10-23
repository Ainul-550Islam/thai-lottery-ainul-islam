<?php

declare(strict_types=1);

namespace App\Jobs\Draw;

use App\Enums\QueueName;
use App\Jobs\Draw\Concerns\HasSettlementRecoveryWindow;
use App\Models\Draw;
use App\Models\SettlementRun;
use App\Services\Draw\ChunkedSettlementOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Final step of a chunked settlement run.
 */
final class FinalizeDrawSettlementJob implements ShouldQueue
{
    use Dispatchable;
    use HasSettlementRecoveryWindow;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $maxExceptions = 3;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public readonly string $batchId;

    public function __construct(
        public readonly int $drawId,
        public readonly int|string $runId = '',
    ) {
        $this->batchId = (string) $runId;
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
        $draw = Draw::query()->find($this->drawId);

        if (! $draw instanceof Draw) {
            Log::error('settlement.finalize_draw_missing', [
                'draw_id' => $this->drawId,
                'run_id' => $this->runId,
            ]);

            throw new RuntimeException(sprintf('Draw %d does not exist; cannot finalize its settlement.', $this->drawId));
        }

        $run = is_int($this->runId) || (is_string($this->runId) && ctype_digit($this->runId))
            ? SettlementRun::query()->find((int) $this->runId)
            : $orchestrator->runFor($this->drawId);

        if (! $run instanceof SettlementRun || (int) $run->draw_id !== $this->drawId) {
            throw new RuntimeException(sprintf(
                'Settlement run %s does not belong to draw %d.',
                (string) $this->runId,
                $this->drawId,
            ));
        }

        $orchestrator->finalize($run);

        Log::info('settlement.finalized', [
            'draw_id' => $this->drawId,
            'run_id' => (int) $run->getKey(),
        ]);
    }
}
