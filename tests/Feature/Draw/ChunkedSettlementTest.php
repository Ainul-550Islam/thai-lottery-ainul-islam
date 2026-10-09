<?php

declare(strict_types=1);

namespace Tests\Feature\Draw;

use App\DTOs\BetPurchaseData;
use App\Enums\BetType;
use App\Enums\Currency;
use App\Enums\DrawStatus;
use App\Enums\DrawType;
use App\Enums\LimitStatus;
use App\Enums\PayoutStatus;
use App\Exceptions\SettlementSimulationException;
use App\Jobs\Draw\ProcessPrizeSettlementJob;
use App\Models\Draw;
use App\Models\NumberLimit;
use App\Models\Payout;
use App\Models\SettlementRun;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Betting\BetPurchaseService;
use App\Services\Draw\ChunkedSettlementOrchestrator;
use App\Services\Draw\DrawLifecycleService;
use App\Services\Draw\DrawResultPublicationService;
use App\Services\Draw\RealPrizeSettlementService;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Feature\Payment\PaymentTestCase;

/**
 * Chunked real-money settlement.
 *
 * ============================================================================
 * WHAT IS DIFFERENT HERE, AND WHY IT NEEDS ITS OWN TESTS
 * ============================================================================
 * RealPrizeSettlementService::settle() settles a draw in ONE transaction, so
 * either the whole draw settles or none of it does. A chunked run deliberately
 * gives that up: it settles the draw in slices, and between slice 1 and slice n
 * the draw has PAID SOME MONEY AND IS NOT SETTLED. That intermediate state is
 * new, and every test below is about it.
 *
 * The properties that must hold, in the order they would hurt if they did not:
 *
 *   1. A draw must never be transitioned to Settled while a slip is unpaid.
 *      Settled is terminal in this project and nothing reversal-shaped exists,
 *      so this is the difference between "finish the run" and "strand winners".
 *   2. Re-running a chunk must not pay a bet twice. This is what makes a
 *      genuinely retryable chunk possible; without it the cursor could never be
 *      advanced after a commit, and the whole design collapses.
 *   3. Two runs must not open over one draw. The unique index on draw_id is the
 *      control, and it is asserted here rather than assumed.
 *   4. Money totals must accumulate as exact decimal strings across chunks. A
 *      total that drifts by a satang is a total nobody can reconcile.
 */
final class ChunkedSettlementTest extends PaymentTestCase
{
    private function configureSafety(string $mode = 'LIVE', string $autoApproveBelow = '1000000.00'): void
    {
        config([
            'finance.prize_payout.safety_mode' => $mode,
            'finance.prize_payout.auto_approve_below' => $autoApproveBelow,
            'finance.prize_payout.require_approval_at_or_above' => $autoApproveBelow,
        ]);
    }

    /**
     * A published draw with one winning 3d_direct bet on '123' plus the given
     * number of losing bets, so a chunk boundary can fall between winners and
     * losers rather than always on a winner.
     *
     * @param  list<string>  $losingNumbers
     * @return array{draw: Draw, wallet: Wallet, user: User}
     */
    private function publishedDrawWithBets(array $losingNumbers, string $suffix): array
    {
        $player = $this->createPlayer('500.00', Currency::THB);

        $draw = Draw::factory()->create(['type' => DrawType::ThreeD]);
        $draw->status = DrawStatus::Open;
        $draw->betting_open_at = now()->subHour();
        $draw->betting_close_at = now()->addHour();
        $draw->opened_at = now()->subHour();
        $draw->save();

        $numbers = array_merge(['123'], $losingNumbers);

        foreach ($numbers as $index => $number) {
            $limit = new NumberLimit;
            $limit->draw_id = $draw->getKey();
            $limit->bet_type = BetType::ThreeD;
            $limit->number = $number;
            $limit->max_amount = '100000.00';
            $limit->current_amount = '0.00';
            $limit->status = LimitStatus::Active;
            $limit->save();

            app(BetPurchaseService::class)->purchase(new BetPurchaseData(
                userId: (int) $player['user']->getKey(),
                drawId: (int) $draw->getKey(),
                marketKey: '3d_direct',
                rawNumber: $number,
                rawStake: '10.00',
                idempotencyKey: sprintf('chunked-%s-%d', $suffix, $index),
            ));
        }

        $lifecycle = app(DrawLifecycleService::class);
        $draw = $lifecycle->close($draw);
        $draw = $lifecycle->markResultPending($draw);

        app(DrawResultPublicationService::class)->publish((int) $draw->getKey(), [
            'first_prize' => '456123',
            'bottom_two' => '45',
        ]);

        return [
            'draw' => $draw->refresh(),
            'wallet' => $player['wallet'],
            'user' => $player['user'],
        ];
    }

    private function orchestrator(): ChunkedSettlementOrchestrator
    {
        return app(ChunkedSettlementOrchestrator::class);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. The safety property: never settle a draw with unpaid slips
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function finalize_refuses_while_bets_remain_past_the_cursor(): void
    {
        $this->configureSafety('DRY_RUN');

        $fixture = $this->publishedDrawWithBets(['111', '222', '333'], 'finalize-refuses');
        $drawId = (int) $fixture['draw']->getKey();

        $orchestrator = $this->orchestrator();
        $run = $orchestrator->start($drawId, 2, null);

        $this->assertSame(4, (int) $run->bets_total);
        $this->assertSame(0, (int) $run->cursor_bet_id);

        // Nothing has been processed, so four bets remain.
        $this->assertSame(4, $orchestrator->remainingBets($run));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot finalize');

        $orchestrator->finalize($run);
    }

    #[Test]
    public function the_draw_stays_published_until_the_run_is_finalized(): void
    {
        $this->configureSafety('DRY_RUN');

        $fixture = $this->publishedDrawWithBets(['111', '222', '333'], 'stays-published');
        $draw = $fixture['draw'];
        $drawId = (int) $draw->getKey();

        $orchestrator = $this->orchestrator();
        $run = $orchestrator->start($drawId, 2, null);

        // One chunk of two of four bets.
        $run = $orchestrator->runChunk($run);

        $this->assertSame(2, (int) $run->bets_settled);
        $this->assertGreaterThan(0, $orchestrator->remainingBets($run));

        // HALF SETTLED, AND STILL NOT SETTLED. This is the new state chunking
        // introduces, and it must never be confused with "settled".
        $this->assertSame(
            DrawStatus::ResultPublished,
            $draw->refresh()->status,
            'a draw with unpaid bets must not be moved to Settled',
        );

        $this->assertFalse($run->isComplete());
        $this->assertSame(SettlementRun::STATUS_OPEN, $run->status);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. Exactly once, even when a chunk is re-run
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function re_running_a_chunk_does_not_pay_a_bet_twice(): void
    {
        $this->configureSafety('LIVE');

        $fixture = $this->publishedDrawWithBets(['111'], 'retry-chunk');
        $draw = $fixture['draw'];
        $drawId = (int) $draw->getKey();

        $orchestrator = $this->orchestrator();
        $run = $orchestrator->start($drawId, 2, null);

        // Keep the pre-chunk object: this is exactly what a worker that crashed
        // and was retried would hold, because the cursor it read is the cursor it
        // would retry with.
        $stale = $run;

        $run = $orchestrator->runChunk($run);

        $payoutsAfterFirst = Payout::query()->where('draw_id', $drawId)->count();
        $balanceAfterFirst = (string) $fixture['wallet']->refresh()->balance;

        $this->assertSame(1, $payoutsAfterFirst, 'the winning bet must have produced exactly one payout');

        $payoutAmount = (string) Payout::query()->where('draw_id', $drawId)->value('amount');

        // Stated as an INVARIANT rather than a magic number: the wallet moved by
        // exactly the obligation the payouts table records. 500.00 to start,
        // 20.00 staked across two bets, plus whatever the winner was owed.
        $this->assertSame(
            bcadd('480.00', $payoutAmount, 2),
            $balanceAfterFirst,
            'the winner must be credited exactly the payout amount, and nothing else',
        );

        // ── RETRY THE SAME CHUNK WITH THE STALE CURSOR.
        $reRun = $orchestrator->runChunk($stale);

        $this->assertSame(
            1,
            Payout::query()->where('draw_id', $drawId)->count(),
            'a re-run chunk must not create a second payout: the (draw, bet) reference is UNIQUE',
        );

        // THE MONEY TOTAL MUST NOT DOUBLE-COUNT THE RE-RUN. The retry re-processes
        // the same range, so a total that simply accumulates would report twice
        // what was actually paid — and the run row is the number an operator
        // reconciles against the payouts table. Counting each bet exactly once is
        // the difference between "idempotent" and "idempotent but lying".
        $this->assertSame(
            bcadd('0.00', $payoutAmount, 2),
            (string) $reRun->total_prize,
            'the run total must count each payout once, however many times the chunk is executed',
        );

        $this->assertSame(
            $balanceAfterFirst,
            (string) $fixture['wallet']->refresh()->balance,
            'a re-run chunk must not credit the winner twice',
        );
    }

    #[Test]
    public function a_completed_run_cannot_settle_the_draw_a_second_time(): void
    {
        $this->configureSafety('LIVE');

        $fixture = $this->publishedDrawWithBets(['111'], 'second-run');
        $draw = $fixture['draw'];
        $drawId = (int) $draw->getKey();

        $orchestrator = $this->orchestrator();

        $run = $orchestrator->run($drawId, 2, null);

        $this->assertTrue($run->isComplete());
        $this->assertSame(SettlementRun::STATUS_COMPLETED, $run->status);

        $payouts = Payout::query()->where('draw_id', $drawId)->count();
        $balance = (string) $fixture['wallet']->refresh()->balance;

        // Asking again returns the SAME run rather than opening a new one.
        $again = $orchestrator->start($drawId, 2, null);

        $this->assertSame((int) $run->getKey(), (int) $again->getKey());
        $this->assertTrue($again->isComplete());

        $orchestrator->finalize($again);

        $this->assertSame($payouts, Payout::query()->where('draw_id', $drawId)->count());
        $this->assertSame($balance, (string) $fixture['wallet']->refresh()->balance);
    }

    /**
     * THE OVERLAP GUARD, AND WHY IT IS NOT A MONEY GUARD.
     *
     * A chunked run does not move the draw to Settled until its last chunk, so
     * during the run the draw is still ResultPublished — and
     * RealPrizeSettlementService::settle() previously knew nothing about
     * settlement_runs and would happily start a second, monolithic settlement
     * alongside the chunked one.
     *
     * That overlap is money-SAFE: both paths collide on the same deterministic
     * per-(draw, bet) payout reference and the same per-bet wallet idempotency
     * key, so no bet is paid twice. It is not REPORT-safe, which is not a lesser
     * problem: each run reports its own total, both look authoritative, and the
     * prize total is the number an operator uses to decide whether to escalate.
     *
     * So settle() refuses. This test pins the refusal, because the failure it
     * prevents is a wrong number rather than a lost one — and a wrong number
     * attracts no alarm at all.
     */
    #[Test]
    public function the_monolithic_path_refuses_while_a_chunked_run_is_in_flight(): void
    {
        $this->configureSafety('DRY_RUN');

        $fixture = $this->publishedDrawWithBets(['111', '222', '333'], 'overlap');
        $drawId = (int) $fixture['draw']->getKey();

        $orchestrator = $this->orchestrator();
        $run = $orchestrator->start($drawId, 2, null);

        // One chunk in: money has been prepared, the draw is NOT settled, and a
        // resume is still going to process the remaining bets.
        $run = $orchestrator->runChunk($run);
        $this->assertSame(2, (int) $run->bets_settled);
        $this->assertGreaterThan(0, $orchestrator->remainingBets($run));

        try {
            app(RealPrizeSettlementService::class)->settle($drawId);

            $this->fail('settle() must refuse while a chunked run is in flight');
        } catch (SettlementSimulationException $e) {
            $this->assertSame(
                SettlementSimulationException::CODE_ALREADY_RUNNING,
                $e->errorCode(),
            );

            $this->assertStringContainsString('chunked settlement run is in flight', $e->getMessage());
        }

        // An ABORTED run counts as in flight too: it is resumable, so its
        // remaining chunks are still going to pay. Treating it as finished would
        // re-open exactly the overlap this guard exists to close.
        $run->status = SettlementRun::STATUS_ABORTED;
        $run->failure_reason = 'interrupted';
        $run->save();

        $this->expectException(SettlementSimulationException::class);

        app(RealPrizeSettlementService::class)->settle($drawId);
    }

    #[Test]
    public function only_one_run_can_exist_for_a_draw(): void
    {
        $this->configureSafety('DRY_RUN');

        $fixture = $this->publishedDrawWithBets(['111'], 'unique-run');
        $drawId = (int) $fixture['draw']->getKey();

        $orchestrator = $this->orchestrator();

        $first = $orchestrator->start($drawId, 2, null);
        $second = $orchestrator->start($drawId, 999, null);

        $this->assertSame((int) $first->getKey(), (int) $second->getKey());
        $this->assertSame(
            1,
            SettlementRun::query()->where('draw_id', $drawId)->count(),
            'draw_id is UNIQUE on settlement_runs; a second run must be impossible, not merely unlikely',
        );

        // The chunk size of the run that actually opened is the one that governs
        // — a later caller cannot reshape work already in flight.
        $this->assertSame(2, (int) $second->chunk_size);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. The money adds up, chunk after chunk
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function totals_accumulate_exactly_across_chunks(): void
    {
        $this->configureSafety('LIVE');

        $fixture = $this->publishedDrawWithBets(['111', '222', '333'], 'totals');
        $drawId = (int) $fixture['draw']->getKey();

        $orchestrator = $this->orchestrator();
        $run = $orchestrator->start($drawId, 1, null);

        // FOUR chunks of ONE bet each — the most fragmented shape possible, so
        // every accumulation step is exercised on its own.
        $cursors = [];
        $stakes = [];

        for ($i = 0; $i < 4; $i++) {
            $run = $orchestrator->runChunk($run);

            $cursors[] = (int) $run->cursor_bet_id;
            $stakes[] = (string) $run->total_stake;
        }

        $this->assertSame([1, 2, 3, 4], $cursors, 'the cursor must advance by exactly one bet per chunk');

        $this->assertSame(
            ['10.00', '20.00', '30.00', '40.00'],
            $stakes,
            'stake must accumulate as exact decimal strings, 10.00 at a time',
        );

        $this->assertSame('40.00', (string) $run->total_stake);
        $this->assertSame(4, (int) $run->bets_settled);
        $this->assertSame(4, (int) $run->chunks_processed);
        $this->assertSame('THB', (string) $run->currency);

        // FOUR, not one. Four bets, each a 3d_direct slip with exactly one
        // selection, so four selections were evaluated; the single winner further
        // down is a different number and I had written 1 here by copying it.
        // selections_evaluated is a separate column from bets_settled for a
        // reason the orchestrator documents: a bet carrying no selections is
        // walked past and counted as settled, so the two can legitimately differ —
        // which is why they are not derived from one another.
        $this->assertSame(4, (int) $run->selections_evaluated);

        // One winner on a 10.00 stake at the 3d_direct multiplier of 900.
        $this->assertSame('9000.00', (string) $run->total_prize);
        $this->assertSame(1, (int) $run->payouts_created);
        $this->assertSame(1, (int) $run->winning_selections);
    }

    #[Test]
    public function an_interrupted_run_resumes_from_its_cursor_and_finishes(): void
    {
        $this->configureSafety('DRY_RUN');

        $fixture = $this->publishedDrawWithBets(['111', '222', '333'], 'resume');
        $draw = $fixture['draw'];
        $drawId = (int) $draw->getKey();

        $orchestrator = $this->orchestrator();
        $run = $orchestrator->start($drawId, 2, null);

        // Two bets done, then "the process dies".
        $run = $orchestrator->runChunk($run);
        $cursorAfterInterruption = (int) $run->cursor_bet_id;
        $chunksAfterInterruption = (int) $run->chunks_processed;

        $this->assertSame(2, $cursorAfterInterruption);
        $this->assertGreaterThan(0, $orchestrator->remainingBets($run));

        // A fresh object read from the database, as a new process would see it.
        $reloaded = SettlementRun::query()->where('draw_id', $drawId)->firstOrFail();

        $this->assertSame($cursorAfterInterruption, (int) $reloaded->cursor_bet_id);
        $this->assertSame($chunksAfterInterruption, (int) $reloaded->chunks_processed);

        $resumed = $orchestrator->resume($drawId);

        $this->assertNotNull($resumed);
        $this->assertTrue($resumed->isComplete(), 'resume() must drive the run to completion');
        $this->assertSame(4, (int) $resumed->bets_settled);
        $this->assertSame(DrawStatus::Completed, $draw->refresh()->status);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. The run is the same money logic as the monolithic path
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function the_chunked_path_pays_only_once_and_only_under_live(): void
    {
        $this->configureSafety('DRY_RUN');

        $fixture = $this->publishedDrawWithBets(['111'], 'dry-run');
        $draw = $fixture['draw'];
        $drawId = (int) $draw->getKey();

        $orchestrator = $this->orchestrator();
        $run = $orchestrator->run($drawId, 2, null);

        $this->assertTrue($run->isComplete());
        $this->assertSame('DRY_RUN', (string) $run->safety_mode, 'the mode is recorded on the run row at open');

        // The obligation exists…
        $payout = Payout::query()->where('draw_id', $drawId)->firstOrFail();
        $this->assertSame(PayoutStatus::Pending, $payout->status);

        // …and no wallet was credited: 500.00 − 20.00 staked across two bets.
        $this->assertSame('480.00', (string) $fixture['wallet']->refresh()->balance);

        $this->assertSame(1, (int) $run->payouts_created);
        $this->assertSame('9000.00', (string) $run->total_prize, 'the OBLIGATION is still totalled even though no wallet moved');
    }

    #[Test]
    public function a_run_opened_under_dry_run_keeps_dry_run_even_if_config_changes_mid_run(): void
    {
        $this->configureSafety('DRY_RUN');

        $fixture = $this->publishedDrawWithBets(['111', '222'], 'mode-pinned');
        $drawId = (int) $fixture['draw']->getKey();

        $orchestrator = $this->orchestrator();
        $run = $orchestrator->start($drawId, 1, null);

        $this->assertSame('DRY_RUN', (string) $run->safety_mode);

        // Somebody flips the estate to LIVE while the run is in flight.
        $this->configureSafety('LIVE');

        $run = $orchestrator->run($drawId, 1, null);

        $this->assertTrue($run->isComplete());
        $this->assertSame(
            'DRY_RUN',
            (string) $run->safety_mode,
            'changing config mid-run must not change how later chunks behave',
        );

        $this->assertSame(
            '470.00',
            (string) $fixture['wallet']->refresh()->balance,
            '500.00 − 30.00 staked across three bets and no prize: a run that started in DRY_RUN does not start paying half-way',
        );
    }

    #[Test]
    public function a_run_cannot_be_opened_for_a_draw_that_is_not_published(): void
    {
        $this->configureSafety('DRY_RUN');

        $draw = Draw::factory()->create(['type' => DrawType::ThreeD]);
        $draw->status = DrawStatus::Open;
        $draw->betting_open_at = now()->subHour();
        $draw->betting_close_at = now()->addHour();
        $draw->opened_at = now()->subHour();
        $draw->save();

        $this->expectException(\Throwable::class);

        // Opening a run against an unsettleable draw must be refused BEFORE the
        // run row exists, so a refusal leaves no trace that could later be read
        // as "a run was started".
        try {
            $this->orchestrator()->start((int) $draw->getKey(), 2, null);
        } finally {
            $this->assertSame(
                0,
                SettlementRun::query()->where('draw_id', (int) $draw->getKey())->count(),
                'a refused start must not leave a run row behind',
            );
        }
    }

    #[Test]
    public function the_orchestrator_clamps_the_configured_chunk_size(): void
    {
        config(['lottery.settlement.chunk_size' => 1]);

        $this->assertSame(
            50,
            $this->orchestrator()->defaultChunkSize(),
            'a chunk of 1 is one transaction per bet; the floor is 50',
        );

        config(['lottery.settlement.chunk_size' => 1000000]);

        $this->assertSame(
            5000,
            $this->orchestrator()->defaultChunkSize(),
            'a chunk of a million is the monolithic transaction again, wearing a cursor',
        );

        config(['lottery.settlement.chunk_size' => 750]);

        $this->assertSame(750, $this->orchestrator()->defaultChunkSize());
    }

    #[Test]
    public function the_safety_mode_is_read_at_open_and_not_per_chunk(): void
    {
        $this->configureSafety('LIVE');

        $fixture = $this->publishedDrawWithBets(['111'], 'safety-recorded');
        $drawId = (int) $fixture['draw']->getKey();

        $run = $this->orchestrator()->start($drawId, 2, null);

        $this->assertSame(RealPrizeSettlementService::SAFETY_LIVE, (string) $run->safety_mode);
        $this->assertSame(RealPrizeSettlementService::MODE, (string) $run->mode);
    }

    #[Test]
    public function percent_complete_reports_progress_and_is_null_without_a_denominator(): void
    {
        $this->configureSafety('DRY_RUN');

        $fixture = $this->publishedDrawWithBets(['111', '222', '333'], 'percent');
        $drawId = (int) $fixture['draw']->getKey();

        $orchestrator = $this->orchestrator();
        $run = $orchestrator->start($drawId, 2, null);

        $this->assertSame(0.0, $run->percentComplete());

        $run = $orchestrator->runChunk($run);

        $this->assertSame(50.0, $run->percentComplete());

        // A run whose draw had no bets at open reports null, not a fabricated 0%:
        // reporting "0%" for "unknown" is a number an operator would act on.
        $empty = new SettlementRun;
        $empty->bets_total = 0;
        $empty->bets_settled = 0;

        $this->assertNull($empty->percentComplete());
    }

    /**
     * The queued monolithic settlement must STAND ASIDE for the chunked run,
     * not fail because of it.
     *
     * ============================================================================
     * THE BUG THIS TEST EXISTS TO CATCH
     * ============================================================================
     * RealPrizeSettlementService::settle() refuses, correctly, while a chunked run
     * is in flight. Before this test's fix, ProcessPrizeSettlementJob treated that
     * refusal like any other SettlementSimulationException and called
     * `$this->fail($e)` — a PERMANENT failure, plus a Critical-severity AuditLog
     * entry.
     *
     * The outcome would have been exactly backwards. The draw is being settled by
     * the more careful path; the legacy job, which did nothing wrong and was
     * simply superseded for this draw, would be recorded as a financial failure
     * that an operator has to investigate. Two incorrect records, and a genuine
     * chunked-run failure would be buried among the noise.
     *
     * So the assertion is not merely "it does not throw": it is that the draw is
     * UNTOUCHED, no second settlement happened, and the run's own progress is
     * exactly where the last chunk left it.
     */
    #[Test]
    public function the_monolithic_job_stands_aside_while_a_chunked_run_owns_the_draw(): void
    {
        $this->configureSafety('DRY_RUN');

        $fixture = $this->publishedDrawWithBets(['111', '222', '333'], 'job-defer');
        $draw = $fixture['draw'];
        $drawId = (int) $draw->getKey();

        $orchestrator = $this->orchestrator();
        $run = $orchestrator->start($drawId, 2, null);
        $run = $orchestrator->runChunk($run);

        $paidBefore = Payout::query()->where('draw_id', $drawId)->count();
        $settledBefore = (int) $run->bets_settled;

        // handle() is invoked directly, as the queue worker would, except that
        // `$this->job` is null — which is also how the existing queue tests drive
        // this job. The job must therefore no-op quietly rather than reach for the
        // release machinery.
        $job = new ProcessPrizeSettlementJob($drawId);
        $result = $job->handle(app(RealPrizeSettlementService::class), app(DrawLifecycleService::class));

        $this->assertNull($result, 'the job must report that it did nothing');

        // NOTHING MOVED. Not the draw, not the money, not the run.
        $this->assertSame(
            DrawStatus::ResultPublished,
            $draw->refresh()->status,
            'the job must not settle a draw the chunked run owns',
        );

        $this->assertSame($paidBefore, Payout::query()->where('draw_id', $drawId)->count());
        $this->assertSame($settledBefore, (int) $run->refresh()->bets_settled);
        $this->assertSame(SettlementRun::STATUS_OPEN, (string) $run->status);

        // And the run can still be finished by its owner, which is the whole point
        // of standing aside rather than failing.
        $run = $orchestrator->runChunk($run);

        while ($orchestrator->hasMoreWork($run)) {
            $run = $orchestrator->runChunk($run);
        }

        $run = $orchestrator->finalize($run);

        $this->assertTrue($run->isComplete());
    }

    /**
     * An ABORTED run also makes the queued job stand aside.
     *
     * The same rule the service applies, asserted at the job because the job is
     * where getting it wrong turns into a permanent failure. An aborted run is
     * RESUMABLE — its cursor and totals are exactly what the last committed chunk
     * left behind, and `--resume` will pay the remaining bets. A monolithic run
     * started alongside it is the overlap the guard exists to close.
     */
    #[Test]
    public function the_monolithic_job_stands_aside_for_an_aborted_resumable_run(): void
    {
        $this->configureSafety('DRY_RUN');

        $fixture = $this->publishedDrawWithBets(['111', '222', '333'], 'job-defer-aborted');
        $draw = $fixture['draw'];
        $drawId = (int) $draw->getKey();

        $orchestrator = $this->orchestrator();
        $run = $orchestrator->start($drawId, 2, null);
        $run = $orchestrator->runChunk($run);

        $run->status = SettlementRun::STATUS_ABORTED;
        $run->failure_reason = 'operator interrupted the run';
        $run->save();

        $job = new ProcessPrizeSettlementJob($drawId);
        $result = $job->handle(app(RealPrizeSettlementService::class), app(DrawLifecycleService::class));

        $this->assertNull($result);
        $this->assertSame(DrawStatus::ResultPublished, $draw->refresh()->status);
        $this->assertSame(SettlementRun::STATUS_ABORTED, (string) $run->refresh()->status);
    }
}
