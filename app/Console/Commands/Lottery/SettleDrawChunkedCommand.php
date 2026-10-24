<?php

declare(strict_types=1);

namespace App\Console\Commands\Lottery;

use App\Jobs\Draw\SettleDrawChunkJob;
use App\Models\Draw;
use App\Models\SettlementRun;
use App\Services\Draw\ChunkedSettlementOrchestrator;
use App\Services\Draw\RealPrizeSettlementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Settle a published draw in CHUNKS instead of in one transaction.
 */
final class SettleDrawChunkedCommand extends Command
{
    private const DURABLE_QUEUE_DRIVERS = ['database', 'redis', 'sqs', 'beanstalkd'];

    /**
     * @var string
     */
    protected $signature = 'lottery:settle-draw-chunked
        {--draw= : The draw to settle, by id or draw number (required unless --all-runs)}
        {--chunk= : Bets per chunk for a NEW run (default: lottery.settlement.chunk_size)}
        {--queue : Open the run and dispatch one job per chunk instead of running here}
        {--resume : Resume an interrupted run from its cursor}
        {--status : Report the run state and write nothing}
        {--all-runs : With --status, list every run that is not complete}
        {--dry-run : Refuse to start a run unless the payout safety mode is not LIVE}';

    /**
     * @var string
     */
    protected $description = 'Settle a published draw in resumable chunks of bets, using the real-money settlement service';

    public function handle(ChunkedSettlementOrchestrator $orchestrator): int
    {
        if (DB::transactionLevel() > 0) {
            $this->error('Refusing to run inside a database transaction: each chunk must own its own transaction.');

            return self::FAILURE;
        }

        if ((bool) $this->option('all-runs')) {
            return $this->reportAllRuns();
        }

        $selector = $this->option('draw');

        if (! is_string($selector) || trim($selector) === '') {
            $this->error('--draw= is required. Name one draw by id or draw number.');

            return self::FAILURE;
        }

        $draw = $this->resolveDraw(trim($selector));

        if (! $draw instanceof Draw) {
            $this->error(sprintf('No draw matches [%s].', $selector));

            return self::FAILURE;
        }

        $drawId = (int) $draw->getKey();

        if ((bool) $this->option('status')) {
            return $this->reportStatus($orchestrator, $drawId);
        }

        if ((bool) $this->option('dry-run')) {
            if ($this->settlementSafetyMode() === RealPrizeSettlementService::SAFETY_LIVE) {
                $this->error(sprintf(
                    'Refusing --dry-run: finance.prize_payout.safety_mode is %s. Set it to %s or %s first if you intended to rehearse.',
                    RealPrizeSettlementService::SAFETY_LIVE,
                    RealPrizeSettlementService::SAFETY_DRY_RUN,
                    RealPrizeSettlementService::SAFETY_DISABLED,
                ));

                return self::FAILURE;
            }

            $existingForDryCheck = $orchestrator->runFor($drawId);
            if ($existingForDryCheck instanceof SettlementRun && (string) $existingForDryCheck->safety_mode === RealPrizeSettlementService::SAFETY_LIVE) {
                $this->error('Refusing --dry-run: the existing settlement run for this draw was pinned under LIVE safety mode.');

                return self::FAILURE;
            }
        }

        if ((bool) $this->option('queue') && ! $this->hasDurableQueueDriver()) {
            $this->error('Refusing --queue: default queue connection does not use a durable driver.');

            return self::FAILURE;
        }

        $chunk = $this->option('chunk');
        $chunkSize = is_numeric($chunk) ? (int) $chunk : null;

        if ($chunkSize !== null && ($chunkSize < 1 || $chunkSize > 5000)) {
            $this->error('--chunk must be between 1 and 5000. A chunk outside that range is either a per-bet transaction or the monolithic run again.');

            return self::FAILURE;
        }

        try {
            if ((bool) $this->option('resume')) {
                return $this->resume($orchestrator, $drawId, $chunkSize);
            }

            $existing = $orchestrator->runFor($drawId);
            if ($existing instanceof SettlementRun && $existing->status === SettlementRun::STATUS_ABORTED) {
                $this->error(sprintf(
                    'Settlement run %d for draw %d is paused/aborted; pass --resume after reviewing the failure.',
                    (int) $existing->getKey(),
                    $drawId,
                ));

                return self::FAILURE;
            }

            $run = $orchestrator->start($drawId, $chunkSize, $this->actorId());

            $this->line($orchestrator->describe($run));
        } catch (Throwable $e) {
            $this->error(sprintf('%s: %s', $e::class, $e->getMessage()));

            return self::FAILURE;
        }

        if ($run->isComplete()) {
            $this->info('A completed settlement run already exists for this draw. Nothing was done.');

            return self::SUCCESS;
        }

        if ((bool) $this->option('queue')) {
            return $this->dispatchChunkJob($drawId, $run);
        }

        return $this->runToCompletion($orchestrator, $drawId, $chunkSize);
    }

    private function hasDurableQueueDriver(): bool
    {
        $defaultConnection = (string) config('queue.default', '');
        if ($defaultConnection === '') {
            return false;
        }

        $driver = (string) config("queue.connections.{$defaultConnection}.driver", '');

        return in_array($driver, self::DURABLE_QUEUE_DRIVERS, true);
    }

    private function dispatchChunkJob(int $drawId, SettlementRun $run): int
    {
        try {
            Bus::dispatch(new SettleDrawChunkJob($drawId, (int) $run->getKey(), (int) $run->cursor_bet_id));
        } catch (Throwable) {
            $this->error('Queue dispatch was not confirmed by the queue broker; the settlement run remains open for retry.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Dispatched the first chunk job for draw %d (run %d). Each job re-dispatches the next; watch settlement_runs row %d for progress.',
            $drawId,
            (int) $run->getKey(),
            (int) $run->getKey(),
        ));

        return self::SUCCESS;
    }

    private function runToCompletion(ChunkedSettlementOrchestrator $orchestrator, int $drawId, ?int $chunkSize): int
    {
        try {
            $run = $orchestrator->run($drawId, $chunkSize, $this->actorId());
        } catch (Throwable $e) {
            $this->error(sprintf('%s: %s', $e::class, $e->getMessage()));

            $existing = $orchestrator->runFor($drawId);

            if ($existing instanceof SettlementRun) {
                $this->warn('The run is resumable from its cursor; re-run with --resume once the cause is addressed.');
                $this->line($orchestrator->describe($existing));
            }

            return self::FAILURE;
        }

        $this->line($orchestrator->describe($run));

        if (! $run->isComplete()) {
            $this->warn('The run did not complete. Re-run with --resume; progress is recorded.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Settled draw %d: %d bet(s), %d winning selection(s), %d payout(s), prize total %s %s.',
            (int) $run->draw_id,
            (int) $run->bets_settled,
            (int) $run->winning_selections,
            (int) $run->payouts_created,
            (string) $run->total_prize,
            (string) ($run->currency ?? ''),
        ));

        if ((string) $run->safety_mode !== RealPrizeSettlementService::SAFETY_LIVE) {
            $this->warn(sprintf(
                'Safety mode was %s: payout obligations were recorded and NO wallet was credited.',
                (string) ($run->safety_mode ?? 'unknown'),
            ));
        }

        return self::SUCCESS;
    }

    private function resume(ChunkedSettlementOrchestrator $orchestrator, int $drawId, ?int $chunkSize): int
    {
        $run = $orchestrator->runFor($drawId);

        if (! $run instanceof SettlementRun) {
            $this->error(sprintf('There is no settlement run for draw %d to resume. Start one without --resume.', $drawId));

            return self::FAILURE;
        }

        $this->line($orchestrator->describe($run));

        if ($run->isComplete()) {
            $this->info('That run is already complete. Nothing was done.');

            return self::SUCCESS;
        }

        if ($chunkSize !== null && $chunkSize !== (int) $run->chunk_size) {
            $this->warn(sprintf(
                'Ignoring --chunk=%d: run %d was opened with chunk_size=%d and keeps it.',
                $chunkSize,
                (int) $run->getKey(),
                (int) $run->chunk_size,
            ));
        }

        if ((bool) $this->option('queue')) {
            $run = $orchestrator->reopen($run);

            return $this->dispatchChunkJob($drawId, $run);
        }

        return $this->runToCompletion($orchestrator, $drawId, null);
    }

    private function reportStatus(ChunkedSettlementOrchestrator $orchestrator, int $drawId): int
    {
        $run = $orchestrator->runFor($drawId);

        if (! $run instanceof SettlementRun) {
            $this->line(sprintf('No settlement run has been opened for draw %d.', $drawId));
            $this->line(sprintf(
                'Settlement would be refused unless the draw is published: run php artisan lottery:settle-draw-chunked --draw=%d to try.',
                $drawId,
            ));

            return self::SUCCESS;
        }

        $this->line($orchestrator->describe($run));
        $this->line(sprintf(
            'remaining_bets=%d  started_at=%s  last_chunk_at=%s  completed_at=%s',
            $orchestrator->remainingBets($run),
            (string) $run->started_at->toIso8601String(),
            $run->last_chunk_at?->toIso8601String() ?? 'never',
            $run->completed_at?->toIso8601String() ?? 'not yet',
        ));

        if (is_string($run->failure_reason) && $run->failure_reason !== '') {
            $this->warn('failure_reason: '.$run->failure_reason);
        }

        return self::SUCCESS;
    }

    private function reportAllRuns(): int
    {
        $runs = SettlementRun::query()
            ->where('status', '!=', SettlementRun::STATUS_COMPLETED)
            ->orderBy('started_at')
            ->limit(200)
            ->get();

        if ($runs->isEmpty()) {
            $this->info('No settlement run is in flight.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($runs as $run) {
            $rows[] = [
                (int) $run->draw_id,
                (int) $run->getKey(),
                (string) $run->status,
                (string) ($run->safety_mode ?? 'unknown'),
                (int) $run->chunks_processed,
                sprintf('%d/%d', (int) $run->bets_settled, (int) $run->bets_total),
                (string) $run->total_prize,
                (string) ($run->currency ?? ''),
                $run->last_chunk_at?->diffForHumans() ?? 'never',
                (string) ($run->failure_reason ?? ''),
            ];
        }

        $this->table(
            ['draw', 'run', 'status', 'safety', 'chunks', 'bets', 'prize', 'cur', 'last chunk', 'failure'],
            $rows,
        );

        return self::SUCCESS;
    }

    private function resolveDraw(string $selector): ?Draw
    {
        if (ctype_digit($selector)) {
            $byId = Draw::query()->find((int) $selector);

            if ($byId instanceof Draw) {
                return $byId;
            }
        }

        return Draw::query()->where('draw_number', $selector)->first();
    }

    private function settlementSafetyMode(): string
    {
        return app(RealPrizeSettlementService::class)->safetyMode();
    }

    private function actorId(): ?int
    {
        $id = config('lottery.settlement.actor_user_id', env('LOTTERY_SETTLEMENT_ACTOR_ID'));

        return is_numeric($id) ? (int) $id : null;
    }
}
