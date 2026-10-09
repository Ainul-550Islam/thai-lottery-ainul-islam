<?php

declare(strict_types=1);

namespace App\Console\Commands\Lottery;

use App\Jobs\Draw\SettleDrawChunkJob;
use App\Models\Draw;
use App\Models\SettlementRun;
use App\Services\Draw\ChunkedSettlementOrchestrator;
use App\Services\Draw\RealPrizeSettlementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Settle a published draw in CHUNKS instead of in one transaction.
 *
 * ============================================================================
 * WHAT THIS IS FOR
 * ============================================================================
 * RealPrizeSettlementService::settle() settles a whole draw in a single database
 * transaction: every bet locked, one items query per bet, every selection DTO
 * retained, no resumption if it fails at slip 99,000. That is serviceable for a
 * small draw and is not serviceable for the 100,000+ slip draw this platform is
 * specified to handle.
 *
 * This command drives the same money logic in slices of `chunk_size` bets, with
 * the cursor and the running totals recorded on a settlement_runs row. The money
 * is moved by the SAME service — same payWinningBet(), same deterministic
 * per-(draw, bet) payout reference, same per-bet wallet idempotency key — so the
 * chunked path is not a second settlement implementation and cannot drift from
 * the first.
 *
 * ============================================================================
 * IT IS NOT GATED ON lottery.automation.auto_settle, AND THAT IS DELIBERATE
 * ============================================================================
 * SettleDrawsCommand is gated on the automation switch because it runs
 * unattended on a schedule. This command does not: it requires an explicit
 * --draw, because an operator naming one draw is the reason the delay exists.
 * Gating it on the same flag would also mean that an operator debugging a stuck
 * draw could not resume it without enabling unattended settlement for every
 * other draw in the estate.
 *
 * What it IS gated on is finance.prize_payout.safety_mode, unchanged and
 * enforced inside payWinningBet(): anything other than LIVE prepares payout
 * obligations without crediting a wallet. `--dry-run` here does not weaken that
 * — it refuses to start a run at all unless the safety mode is already
 * non-LIVE, so a dry run can never be the thing that discovers LIVE was on.
 *
 * ============================================================================
 * USAGE
 * ============================================================================
 *   php artisan lottery:settle-draw-chunked --draw=42
 *       Run to completion in this process, chunk after chunk.
 *
 *   php artisan lottery:settle-draw-chunked --draw=42 --queue
 *       Open the run and hand the chunks to the queue (financial-critical).
 *       One job per chunk; each job re-dispatches the next. Use this for a
 *       large draw: it keeps a single worker from being tied up for the run.
 *
 *   php artisan lottery:settle-draw-chunked --draw=42 --chunk=2000
 *       Chunk size for a NEW run. A run already in flight keeps the chunk size
 *       it was opened with, so this cannot change the shape of work under it.
 *
 *   php artisan lottery:settle-draw-chunked --draw=42 --status
 *       Report where the run is and write nothing.
 *
 *   php artisan lottery:settle-draw-chunked --draw=42 --resume
 *       Resume an interrupted (aborted) run from its cursor.
 *
 * Exit codes: 0 success or nothing-to-do, 1 a refusal or a failure that needs
 * an operator. A run that is half settled and needs attention reports 1, because
 * a half-settled draw is not a success.
 */
final class SettleDrawChunkedCommand extends Command
{
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
            // The whole point of chunking is that each chunk owns its own
            // transaction. Started inside somebody else's transaction, every
            // chunk would join it and the run would become the monolithic
            // transaction again, wearing a cursor.
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

        if ((bool) $this->option('status')) {
            return $this->reportStatus($orchestrator, (int) $draw->getKey());
        }

        if ((bool) $this->option('dry-run') && $this->settlementSafetyMode() === RealPrizeSettlementService::SAFETY_LIVE) {
            // A dry run must not be the thing that discovers LIVE was on. In
            // LIVE this command would open payout obligations and credit
            // wallets; "--dry-run should have been safe" is not a position to be
            // in after the money moved.
            $this->error(sprintf(
                'Refusing --dry-run: finance.prize_payout.safety_mode is %s. Set it to %s or %s first if you intended to rehearse.',
                RealPrizeSettlementService::SAFETY_LIVE,
                RealPrizeSettlementService::SAFETY_DRY_RUN,
                RealPrizeSettlementService::SAFETY_DISABLED,
            ));

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
                return $this->resume($orchestrator, (int) $draw->getKey(), $chunkSize);
            }

            $run = $orchestrator->start((int) $draw->getKey(), $chunkSize, $this->actorId());

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
            SettleDrawChunkJob::dispatch((int) $draw->getKey(), (int) $run->getKey());

            $this->info(sprintf(
                'Dispatched the first chunk job for draw %d (run %d). Each job re-dispatches the next; watch settlement_runs row %d for progress.',
                (int) $draw->getKey(),
                (int) $run->getKey(),
                (int) $run->getKey(),
            ));

            return self::SUCCESS;
        }

        return $this->runToCompletion($orchestrator, (int) $draw->getKey(), $chunkSize);
    }

    /**
     * Drive the run here, chunk after chunk, reporting as it goes.
     */
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

        // Say the safety mode out loud. An operator reading the log needs to know
        // whether those payouts actually credited wallets, and in DRY_RUN they
        // did not — the obligations exist and the money did not move.
        if ((string) $run->safety_mode !== RealPrizeSettlementService::SAFETY_LIVE) {
            $this->warn(sprintf(
                'Safety mode was %s: payout obligations were recorded and NO wallet was credited.',
                (string) ($run->safety_mode ?? 'unknown'),
            ));
        }

        return self::SUCCESS;
    }

    /**
     * Resume an interrupted run. Never opens one: a resume that silently started
     * a fresh run would hide the reason the previous one stopped.
     */
    private function resume(ChunkedSettlementOrchestrator $orchestrator, int $drawId, ?int $chunkSize): int
    {
        // runFor() is the pure lookup; resume() on the orchestrator drives the
        // run to completion, which is what the non-queue branch below wants but
        // the --queue branch does not. Look up first, then decide.
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
            // Not an error, but worth saying: the run keeps the chunk size it
            // was opened with, so --chunk has no effect on an existing run.
            $this->warn(sprintf(
                'Ignoring --chunk=%d: run %d was opened with chunk_size=%d and keeps it.',
                $chunkSize,
                (int) $run->getKey(),
                (int) $run->chunk_size,
            ));
        }

        if ((bool) $this->option('queue')) {
            SettleDrawChunkJob::dispatch($drawId, (int) $run->getKey());

            $this->info(sprintf('Dispatched chunk job for run %d.', (int) $run->getKey()));

            return self::SUCCESS;
        }

        return $this->runToCompletion($orchestrator, $drawId, null);
    }

    /**
     * Report where a draw's run is. Writes nothing.
     */
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

    /**
     * Every run that is not complete — what an operator needs after being paged.
     */
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

    /**
     * Resolve --draw by id or draw number.
     *
     * A numeric selector matches an ID first and then a draw number, because
     * both are plausible and an operator typing `--draw=7` means one of them.
     * ID wins only because it is the unambiguous reading; if no draw has that id
     * the draw-number reading is tried rather than reported as not found.
     */
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

    /**
     * The operator running the command, if the environment identifies one.
     *
     * started_by is nullable and this is best-effort: not being able to name the
     * operator is not a reason to refuse to settle a draw.
     */
    private function actorId(): ?int
    {
        $id = config('lottery.settlement.actor_user_id', env('LOTTERY_SETTLEMENT_ACTOR_ID'));

        return is_numeric($id) ? (int) $id : null;
    }
}
