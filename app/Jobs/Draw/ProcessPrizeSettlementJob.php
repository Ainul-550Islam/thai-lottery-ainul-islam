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
use App\Models\AuditLog;
use App\Models\Draw;
use App\Models\SettlementRun;
use App\Services\Draw\DrawLifecycleService;
use App\Services\Draw\RealPrizeSettlementService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maximum retry attempts before failing permanently.
     */
    public int $tries = 2;

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

        // Check if already settled
        if ($draw->status === DrawStatus::Completed || $lifecycleService->currentState($draw)->isSettled()) {
            Log::info('ProcessPrizeSettlementJob: Draw is already settled, skipping redundant execution', [
                'draw_id' => $this->drawId,
                'draw_number' => $draw->draw_number,
                'status' => $draw->status->value,
            ]);

            return null;
        }

        // ── IS A CHUNKED RUN SETTLING THIS DRAW RIGHT NOW?
        //
        // Asked BEFORE settle() rather than inferred from its refusal, because
        // the deferral is the point: this job has nothing to do and must come back
        // later, not record a failure. The check is one indexed read on
        // settlement_runs.
        $chunkedRun = $settlementService->inFlightChunkedRun($this->drawId);

        if ($chunkedRun instanceof SettlementRun) {
            Log::info('ProcessPrizeSettlementJob: a chunked settlement run owns this draw; standing aside', [
                'draw_id' => $this->drawId,
                'draw_number' => $draw->draw_number,
                'settlement_run_id' => (int) $chunkedRun->getKey(),
                'settlement_run_status' => (string) $chunkedRun->status,
                'bets_settled' => (int) $chunkedRun->bets_settled,
                'bets_total' => (int) $chunkedRun->bets_total,
                'defer_seconds' => self::CHUNKED_RUN_DEFER_SECONDS,
            ]);

            // `$this->job` is null when handle() is invoked directly (tests, and
            // any future synchronous caller), and there is no queue to release to
            // in that case. The guard is explicit rather than relying on the
            // trait's null behaviour, because a caller that invokes handle() by
            // hand must get a quiet no-op and not an exception from the release
            // machinery.
            if ($this->job !== null) {
                $this->release(self::CHUNKED_RUN_DEFER_SECONDS);
            }

            return null;
        }

        try {
            return $settlementService->settle($this->drawId);
        } catch (SettlementSimulationException $e) {
            // ── THE RACE THE PRE-FLIGHT ABOVE CANNOT CLOSE.
            //
            // A chunked run can open in the milliseconds between the check and
            // the call, or an operator can start one from the console while this
            // job is already dispatched. In that window settle() refuses with
            // CODE_ALREADY_RUNNING — and that specific refusal means "wait",
            // not "this settlement is invalid".
            //
            // Every OTHER SettlementSimulationException still fails permanently:
            // an unreadable result, a tampered result, a mixed-currency draw and a
            // client-supplied payout are all facts that a retry cannot change, and
            // burying them behind a retry loop is how a tamper signal gets lost.
            if ($e->errorCode() === SettlementSimulationException::CODE_ALREADY_RUNNING) {
                Log::info('ProcessPrizeSettlementJob: settle() refused because a run is already in flight; standing aside', [
                    'draw_id' => $this->drawId,
                    'draw_number' => $draw->draw_number,
                    'message' => $e->getMessage(),
                    'defer_seconds' => self::CHUNKED_RUN_DEFER_SECONDS,
                ]);

                if ($this->job !== null) {
                    $this->release(self::CHUNKED_RUN_DEFER_SECONDS);
                }

                return null;
            }

            Log::error('ProcessPrizeSettlementJob: Permanent SettlementSimulationException encountered', [
                'draw_id' => $this->drawId,
                'draw_number' => $draw->draw_number,
                'error_code' => $e->errorCode(),
                'message' => $e->getMessage(),
            ]);

            $this->fail($e);

            return null;
        } catch (DrawLifecycleException $e) {
            Log::error('ProcessPrizeSettlementJob: Permanent DrawLifecycleException encountered', [
                'draw_id' => $this->drawId,
                'draw_number' => $draw->draw_number,
                'message' => $e->getMessage(),
            ]);

            $this->fail($e);

            return null;
        } catch (FinancialException $e) {
            Log::error('ProcessPrizeSettlementJob: Permanent FinancialException encountered', [
                'draw_id' => $this->drawId,
                'draw_number' => $draw->draw_number,
                'error_code' => $e->errorCode(),
                'message' => $e->getMessage(),
            ]);

            $this->fail($e);

            return null;
        } catch (Throwable $e) {
            Log::warning('ProcessPrizeSettlementJob: Transient error during settlement, queue will retry', [
                'draw_id' => $this->drawId,
                'draw_number' => $draw->draw_number,
                'attempt' => $this->attempts(),
                'exception' => $e->getMessage(),
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
            'error' => $exception->getMessage(),
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
                'description' => sprintf(
                    'ProcessPrizeSettlementJob failed permanently for Draw %s after %d attempts: %s',
                    $draw->draw_number,
                    $this->attempts(),
                    $exception->getMessage(),
                ),
                'metadata' => [
                    'draw_id' => $draw->id,
                    'draw_number' => $draw->draw_number,
                    'attempts' => $this->attempts(),
                    'error' => $exception->getMessage(),
                ],
            ]);
            $log->save();
        }
    }
}
