<?php

declare(strict_types=1);

namespace App\Jobs\Draw;

use App\Enums\QueueName;
use App\Exceptions\DrawLifecycleException;
use App\Exceptions\FinancialException;
use App\Exceptions\SettlementSimulationException;
use App\Models\SettlementRun;
use App\Services\Draw\ChunkedSettlementOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Process one durable SettlementRun cursor range, then queue the next one.
 *
 * The service commits money and cursor/totals in one transaction. Transient
 * failures leave the run cursor unchanged while the queue performs its bounded
 * retries. Only terminal failure after retries, or a non-retryable domain
 * refusal, moves the run to its audited resumable-aborted state.
 */
final class SettleDrawChunkJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [5, 30, 120];

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $drawId,
        public readonly int $runId,
        public readonly ?int $expectedCursorBetId = null,
    ) {
        $this->onQueue((string) config('lottery.settlement.queue', QueueName::FinancialCritical->value));
        $this->afterCommit();
    }

    /**
     * Prevent overlapping deliveries where the configured lock backend supports
     * it. The database draw/run row locks remain the cross-host correctness guard.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("settlement-run:{$this->runId}"))
                ->expireAfter(300),
        ];
    }

    public function handle(ChunkedSettlementOrchestrator $orchestrator): void
    {
        $run = SettlementRun::query()
            ->whereKey($this->runId)
            ->where('draw_id', $this->drawId)
            ->first();

        if (! $run instanceof SettlementRun
            || $run->isComplete()
            || $run->status !== SettlementRun::STATUS_OPEN) {
            // An aborted run is resumable only through the reasoned, authorized
            // operator recovery path. A queued duplicate must never reopen it.
            return;
        }

        if ($this->expectedCursorBetId !== null
            && (int) $run->cursor_bet_id !== $this->expectedCursorBetId) {
            // Each delivery owns one expected cursor. Duplicate or delayed jobs
            // from an earlier checkpoint are harmless no-ops and cannot fan out
            // another chain of next-chunk jobs.
            return;
        }

        try {
            $run = $orchestrator->runChunk($run);

            if ($orchestrator->hasMoreWork($run)) {
                self::dispatch($this->drawId, $this->runId, (int) $run->cursor_bet_id)
                    ->onQueue((string) config('lottery.settlement.queue', 'settlement'));
            } else {
                $run = $orchestrator->finalize($run);
            }

            Log::info('settlement.chunk_job_progress', [
                'draw_id' => $this->drawId,
                'run_id' => $this->runId,
                'status' => $run->status,
                'cursor_bet_id' => (int) $run->cursor_bet_id,
                'bets_settled' => (int) $run->bets_settled,
                'bets_total' => (int) $run->bets_total,
                'chunks_processed' => (int) $run->chunks_processed,
            ]);
        } catch (Throwable $exception) {
            $errorCode = method_exists($exception, 'errorCode')
                ? (string) $exception->errorCode()
                : ($exception instanceof QueryException
                    ? (string) ($exception->errorInfo[0] ?? 'database_error')
                    : 'unclassified');

            Log::error('settlement.chunk_job_failed', [
                'draw_id' => $this->drawId,
                'run_id' => $this->runId,
                'error_class' => $exception::class,
                'error_code' => $errorCode,
                'attempt' => $this->attempts(),
                'retryable' => ! $this->isTerminalFailure($exception),
            ]);

            if ($this->isTerminalFailure($exception)) {
                $this->fail($exception);

                return;
            }

            throw $exception;
        }
    }

    /**
     * Laravel invokes this after the queue exhausts retries or fail() is called.
     */
    public function failed(?Throwable $exception): void
    {
        $run = SettlementRun::query()
            ->whereKey($this->runId)
            ->where('draw_id', $this->drawId)
            ->first();

        $failure = $exception ?? new RuntimeException('The settlement queue job failed without an exception.');

        if ($run instanceof SettlementRun && ! $run->isComplete()) {
            app(ChunkedSettlementOrchestrator::class)->abort($run, $failure);
        }

        $errorCode = method_exists($failure, 'errorCode')
            ? (string) $failure->errorCode()
            : ($failure instanceof QueryException
                ? (string) ($failure->errorInfo[0] ?? 'database_error')
                : 'unclassified');

        Log::critical('settlement.chunk_job_terminal_failure', [
            'draw_id' => $this->drawId,
            'run_id' => $this->runId,
            'error_class' => $failure::class,
            'error_code' => $errorCode,
            'attempt' => $this->attempts(),
        ]);
    }

    private function isTerminalFailure(Throwable $exception): bool
    {
        return $exception instanceof SettlementSimulationException
            || $exception instanceof DrawLifecycleException
            || $exception instanceof FinancialException;
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return [
            'settlement',
            'draw:'.$this->drawId,
            'run:'.$this->runId,
        ];
    }
}
