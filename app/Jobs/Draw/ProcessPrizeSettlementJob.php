<?php

declare(strict_types=1);

namespace App\Jobs\Draw;

use App\DTOs\SettlementResult;
use App\Enums\AuditAction;
use App\Enums\DrawStatus;
use App\Enums\QueueName;
use App\Enums\RiskLevel;
use App\Exceptions\DrawLifecycleException;
use App\Exceptions\FinancialException;
use App\Exceptions\SettlementSimulationException;
use App\Jobs\Draw\Concerns\HasSettlementRecoveryWindow;
use App\Models\AuditLog;
use App\Models\Draw;
use App\Models\SettlementRun;
use App\Services\Draw\ChunkedSettlementOrchestrator;
use App\Services\Draw\DrawLifecycleService;
use App\Services\Draw\RealPrizeSettlementService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Production Asynchronous Prize Settlement Job.
 *
 * GUARANTEES:
 * 1. Double-Entry Prize Settlement: Atomic prize calculation, payout creation, player wallet credit.
 * 2. Concurrency Safety: ShouldBeUnique keyed per draw prevents concurrent settlement workers.
 * 3. Idempotency: Replaying an already settled draw returns cached/stored results without duplicate payouts.
 * 4. After-Commit Dispatch: Never processes until preceding database commits have succeeded.
 * 5. Error Discrimination: Business validation failures fail permanently; transient DB errors retry.
 * 6. Deference to the chunked path: while a ChunkedSettlementOrchestrator run owns
 *    the draw, this job re-queues itself instead of failing. A refusal to overlap
 *    is a scheduling fact, not a settlement error.
 */
class ProcessPrizeSettlementJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, HasSettlementRecoveryWindow, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maximum retry attempts before failing permanently.
     */
    public int $tries = 2;

    public int $maxExceptions = 2;

    /**
     * Exponential backoff delays in seconds (15s, 60s).
     *
     * @var list<int>
     */
    public array $backoff = [15, 60];

    /**
     * Execution timeout in seconds (supports high volume draws).
     */
    public int $timeout = 180;

    public bool $failOnTimeout = true;

    /**
     * Unique lock duration in seconds.
     */
    public int $uniqueFor = 300;

    /**
     * How long to stand aside while a chunked settlement run finishes this draw.
     *
     * THIS JOB IS NO LONGER THE ONLY WAY A DRAW GETS SETTLED, and it must not
     * behave as though it were. ChunkedSettlementOrchestrator settles a large
     * draw in slices and does not move the draw to Settled until the last chunk,
     * so for the whole duration of a chunked run the draw still looks settleable
     * to this job — and RealPrizeSettlementService::settle() refuses, correctly,
     * while a chunked run is in flight.
     *
     * Without handling, that refusal surfaced here as a SettlementSimulationException,
     * which this job fails PERMANENTLY on. The result would be exactly backwards:
     * the correct, more careful settlement path would run, and the legacy job
     * would be marked as a permanent failure and raise a Critical audit entry for
     * doing nothing wrong.
     *
     * So the overlap is a WAIT, not a failure. The job re-queues itself after this
     * many seconds and checks again.
     */
    private const CHUNKED_RUN_DEFER_SECONDS = 30;

    public function __construct(
        public readonly int $drawId,
    ) {
        $this->onQueue(QueueName::FinancialCritical->value);
        $this->afterCommit();
    }

    /**
     * Unique identifier for concurrency lock.
     */
    public function uniqueId(): string
    {
        return 'prize_settlement_draw_'.$this->drawId;
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

    /**
     * Execute the prize settlement.
     */
    public function handle(
        RealPrizeSettlementService $settlementService,
        DrawLifecycleService $lifecycleService,
    ): ?SettlementResult {
        $draw = Draw::query()->find($this->drawId);

        if (! $draw instanceof Draw) {
            Log::error('ProcessPrizeSettlementJob: Draw not found', [
                'draw_id' => $this->drawId,
                'attempt' => $this->attempts(),
            ]);

            $this->fail(new \RuntimeException(sprintf('Draw #%d not found.', $this->drawId)));

            return null;
        }

        $orchestrator = app(ChunkedSettlementOrchestrator::class);
        $existingRun = $orchestrator->runFor($this->drawId);

        if ($existingRun instanceof SettlementRun) {
            if ($existingRun->status === SettlementRun::STATUS_ABORTED) {
                return null;
            }

            if ($existingRun->isComplete() || $draw->status === DrawStatus::Completed || $lifecycleService->currentState($draw)->isSettled()) {
                $orchestrator->finalize($existingRun);

                return null;
            }

            if ($this->job !== null) {
                if ($orchestrator->hasMoreWork($existingRun)) {
                    SettleDrawChunkJob::dispatch($this->drawId, (int) $existingRun->getKey(), (int) $existingRun->cursor_bet_id);
                } else {
                    FinalizeDrawSettlementJob::dispatch($this->drawId, (int) $existingRun->getKey());
                }
            }

            return null;
        }

        if ($draw->status === DrawStatus::Completed || $lifecycleService->currentState($draw)->isSettled()) {
            return null;
        }

        try {
            if ($this->job === null) {
                $orchestrator->run($this->drawId);

                return null;
            }

            $run = $orchestrator->start($this->drawId);

            if ($run->status === SettlementRun::STATUS_ABORTED) {
                return null;
            }

            if ($run->isComplete()) {
                $orchestrator->finalize($run);

                return null;
            }

            (new SettleDrawChunkJob($this->drawId, (int) $run->getKey(), (int) $run->cursor_bet_id))
                ->handle($orchestrator);

            return null;
        } catch (SettlementSimulationException $e) {
            Log::error('ProcessPrizeSettlementJob: Permanent SettlementSimulationException encountered', [
                'draw_id' => $this->drawId,
                'draw_number' => $draw->draw_number,
                'error_code' => $e->errorCode(),
            ]);

            $this->fail($e);

            return null;
        } catch (DrawLifecycleException $e) {
            Log::error('ProcessPrizeSettlementJob: Permanent DrawLifecycleException encountered', [
                'draw_id' => $this->drawId,
                'draw_number' => $draw->draw_number,
            ]);

            $this->fail($e);

            return null;
        } catch (FinancialException $e) {
            Log::error('ProcessPrizeSettlementJob: Permanent FinancialException encountered', [
                'draw_id' => $this->drawId,
                'draw_number' => $draw->draw_number,
                'error_code' => $e->errorCode(),
            ]);

            $this->fail($e);

            return null;
        } catch (Throwable $e) {
            Log::warning('ProcessPrizeSettlementJob: Transient error during settlement, queue will retry', [
                'draw_id' => $this->drawId,
                'draw_number' => $draw->draw_number,
                'attempt' => $this->attempts(),
                'exception_class' => $e::class,
            ]);

            throw $e;
        }
    }

    /**
     * Handle permanent job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::critical('ProcessPrizeSettlementJob: Job failed permanently after maximum attempts', [
            'draw_id' => $this->drawId,
            'attempts' => $this->attempts(),
            'exception_class' => $exception::class,
        ]);

        $draw = Draw::query()->find($this->drawId);

        if ($draw instanceof Draw) {
            $log = new AuditLog;
            $log->fill([
                'user_id' => null,
                'action' => AuditAction::Payout,
                'risk_level' => RiskLevel::Critical,
                'auditable_type' => Draw::class,
                'auditable_id' => $draw->id,
                'description' => 'Prize settlement queue delivery failed; persisted run requires inspection',
                'metadata' => [
                    'draw_id' => $draw->id,
                    'draw_number' => $draw->draw_number,
                    'attempts' => $this->attempts(),
                    'exception_class' => $exception::class,
                ],
            ]);
            $log->save();
        }
    }
}
