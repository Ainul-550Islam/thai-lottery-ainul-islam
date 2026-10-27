<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Enums\DrawStatus;
use App\Enums\PayoutStatus;
use App\Enums\QueueName;
use App\Jobs\Draw\FinalizeDrawSettlementJob;
use App\Jobs\Draw\ProcessPrizeSettlementJob;
use App\Jobs\Draw\SettleDrawChunkJob;
use App\Models\AuditLog;
use App\Models\Draw;
use App\Models\Payout;
use App\Models\SettlementRun;
use App\Services\Draw\ChunkedSettlementOrchestrator;
use App\Services\Draw\DrawLifecycleService;
use App\Services\Draw\RealPrizeSettlementService;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Draw\Concerns\BuildsConfirmedSettlementDraws;
use Tests\Feature\Payment\PaymentTestCase;

/** Actual serialized SQLite database queue; not shared-server/provider certification. */
final class SettlementQueuePipelineTest extends PaymentTestCase
{
    use BuildsConfirmedSettlementDraws;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
        $this->configureSafety('LIVE', '0.00');
    }

    private function workerOnce(): void
    {
        app('queue.worker')->runNextJob('database', QueueName::workerQueueString(), new WorkerOptions(
            sleep: 0, maxTries: 2, timeout: 180,
        ));
        self::assertSame(0, DB::table('failed_jobs')->count());
    }

    private function drain(): void
    {
        for ($i = 0; $i < 12 && DB::table('jobs')->count() > 0; $i++) {
            $this->workerOnce();
        }
        self::assertSame(0, DB::table('jobs')->count(), 'Every continuation must be consumed by the standard priority list.');
    }

    private function queuedContext(): Job
    {
        $job = $this->createMock(Job::class);
        $job->method('attempts')->willReturn(1);
        $job->expects($this->never())->method('release');
        $job->expects($this->never())->method('fail');

        return $job;
    }

    public function test_standard_worker_consumes_serialized_entry_chunk_and_finalizer(): void
    {
        $f = $this->publishedDrawWithBets(['111', '222'], 'worker');
        $o = app(ChunkedSettlementOrchestrator::class);
        $run = $o->start((int) $f['draw']->id, 1);
        $queue = Queue::connection('database');
        $queue->push(new ProcessPrizeSettlementJob((int) $f['draw']->id));
        $this->workerOnce(); // Compatibility envelope hands off, moves no money.
        self::assertSame(0, $run->fresh()->bets_settled);
        $this->workerOnce(); // First checkpoint creates the pending winner obligation.
        self::assertSame(1, $run->fresh()->bets_settled);
        self::assertSame(DrawStatus::ResultPublished, $f['draw']->fresh()->status);
        self::assertSame(PayoutStatus::Pending, Payout::query()->where('draw_id', $f['draw']->id)->sole()->status);
        self::assertSame('470.00', (string) $f['wallet']->fresh()->balance);
        $this->drain();
        self::assertSame(3, $run->fresh()->bets_settled);
        self::assertTrue($run->fresh()->isComplete());
        self::assertSame(DrawStatus::Completed, $f['draw']->fresh()->status);
        self::assertSame('9000.00', (string) $run->fresh()->total_prize);
        self::assertSame('470.00', (string) $f['wallet']->fresh()->balance);
        self::assertSame(1, Payout::query()->where('draw_id', $f['draw']->id)->count());
    }

    public function test_new_entry_creates_durable_proof_before_money_and_finishes_via_finalizer(): void
    {
        $f = $this->publishedDrawWithBets([], 'entry');
        Queue::connection('database')->push(new ProcessPrizeSettlementJob((int) $f['draw']->id));
        $this->workerOnce();
        $run = SettlementRun::query()->where('draw_id', $f['draw']->id)->sole();
        self::assertSame(1, $run->bets_settled);
        self::assertNotEmpty($run->result_fingerprint);
        self::assertNotEmpty($run->participants_fingerprint);
        self::assertFalse($run->isComplete());
        self::assertSame(DrawStatus::ResultPublished, $f['draw']->fresh()->status);
        self::assertSame('490.00', (string) $f['wallet']->fresh()->balance);
        $this->drain();
        self::assertTrue($run->fresh()->isComplete());
    }

    public function test_legacy_open_and_aborted_runs_do_not_consume_wait_retries_or_auto_resume(): void
    {
        $f = $this->publishedDrawWithBets([], 'paused');
        $run = app(ChunkedSettlementOrchestrator::class)->start((int) $f['draw']->id, 1);
        $run->status = SettlementRun::STATUS_ABORTED;
        $run->save();
        $job = new ProcessPrizeSettlementJob((int) $f['draw']->id);
        $job->setJob($this->queuedContext());
        self::assertNull($job->handle(app(RealPrizeSettlementService::class), app(DrawLifecycleService::class)));
        self::assertSame(SettlementRun::STATUS_ABORTED, $run->fresh()->status);
        self::assertSame(0, DB::table('jobs')->count());
        self::assertSame(0, Payout::query()->where('draw_id', $f['draw']->id)->count());
    }

    public function test_lost_continuation_after_committed_credit_is_repaired_without_double_credit(): void
    {
        $this->configureSafety('LIVE', '1000000.00'); // Synthetic below-threshold policy only.
        $f = $this->publishedDrawWithBets([], 'lost-handoff');
        $dispatcher = Bus::getFacadeRoot();
        $failure = new RuntimeException('Synthetic dispatch outage');
        Bus::partialMock()->shouldReceive('dispatch')->once()->andThrow($failure);
        $job = new ProcessPrizeSettlementJob((int) $f['draw']->id);
        $job->setJob($this->queuedContext());
        try {
            $job->handle(app(RealPrizeSettlementService::class), app(DrawLifecycleService::class));
            self::fail('The failed continuation dispatch was hidden.');
        } catch (RuntimeException $error) {
            self::assertSame($failure, $error);
        } finally {
            Bus::swap($dispatcher);
        }
        $run = SettlementRun::query()->where('draw_id', $f['draw']->id)->sole();
        self::assertSame(1, $run->bets_settled);
        self::assertFalse($run->isComplete());
        self::assertSame('9490.00', (string) $f['wallet']->fresh()->balance);
        self::assertSame(1, Payout::query()->where('draw_id', $f['draw']->id)->count());
        $counts = [DB::table('financial_transactions')->count(), DB::table('ledger_entries')->count()];
        $retry = new ProcessPrizeSettlementJob((int) $f['draw']->id);
        $retry->setJob($this->queuedContext());
        $retry->handle(app(RealPrizeSettlementService::class), app(DrawLifecycleService::class));
        $this->drain();
        self::assertTrue($run->fresh()->isComplete());
        self::assertSame($counts, [DB::table('financial_transactions')->count(), DB::table('ledger_entries')->count()]);
        self::assertSame('9490.00', (string) $f['wallet']->fresh()->balance);
    }

    public function test_stale_checkpoint_deliveries_never_consume_an_extra_chunk(): void
    {
        $f = $this->publishedDrawWithBets(['111'], 'stale');
        $o = app(ChunkedSettlementOrchestrator::class);
        $run = $o->start((int) $f['draw']->id, 1);
        Queue::fake();
        $chunk = new SettleDrawChunkJob((int) $f['draw']->id, (int) $run->id, 0);
        $chunk->handle($o);
        $cursor = $run->fresh()->cursor_bet_id;
        $chunk->handle($o);
        self::assertSame($cursor, $run->fresh()->cursor_bet_id);
        self::assertSame(1, $run->fresh()->bets_settled);
        self::assertSame(1, Payout::query()->where('draw_id', $f['draw']->id)->count());
    }

    public function test_cursorless_legacy_chunk_envelope_cannot_advance_money(): void
    {
        $f = $this->publishedDrawWithBets([], 'no-cursor');
        $o = app(ChunkedSettlementOrchestrator::class);
        $run = $o->start((int) $f['draw']->id, 1);
        try {
            (new SettleDrawChunkJob((int) $f['draw']->id, (int) $run->id))->handle($o);
            self::fail('A cursorless envelope advanced settlement.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('expected cursor', $error->getMessage());
        }
        self::assertSame(0, $run->fresh()->bets_settled);
        self::assertSame(0, Payout::query()->where('draw_id', $f['draw']->id)->count());
    }

    public static function tampering(): array
    {
        return [['totals'], ['participant outcome'], ['draw status'], ['forged completed stamp']];
    }

    #[DataProvider('tampering')]
    public function test_completed_flags_cannot_hide_changed_proof_on_replay(string $fault): void
    {
        $f = $this->publishedDrawWithBets([], 'replay-'.str_replace(' ', '-', $fault));
        $o = app(ChunkedSettlementOrchestrator::class);
        $run = $fault === 'forged completed stamp'
            ? $o->start((int) $f['draw']->id, 1) : $o->run((int) $f['draw']->id, 1);
        if ($fault === 'forged completed stamp') {
            SettlementRun::query()->whereKey($run->id)->update(['status' => SettlementRun::STATUS_COMPLETED]);
        } elseif ($fault === 'totals') {
            SettlementRun::query()->whereKey($run->id)->update(['total_prize' => '0.00']);
        } elseif ($fault === 'participant outcome') {
            DB::table('bet_items')->where('bet_id', DB::table('bets')->where('draw_id', $f['draw']->id)->value('id'))->update(['actual_payout' => '0.00']);
        } else {
            Draw::query()->whereKey($f['draw']->id)->update(['status' => DrawStatus::ResultPublished->value]);
        }
        $counts = [DB::table('financial_transactions')->count(), DB::table('ledger_entries')->count()];
        try {
            (new FinalizeDrawSettlementJob((int) $f['draw']->id, (int) $run->id))->handle($o);
            self::fail('A completed marker bypassed proof revalidation.');
        } catch (RuntimeException $error) {
            self::assertNotEmpty($error->getMessage());
        }
        self::assertSame($counts, [DB::table('financial_transactions')->count(), DB::table('ledger_entries')->count()]);
    }

    public function test_rollback_never_publishes_an_after_commit_settlement_envelope(): void
    {
        $f = $this->publishedDrawWithBets([], 'rollback');
        DB::beginTransaction();
        try {
            ProcessPrizeSettlementJob::dispatch((int) $f['draw']->id);
            self::assertSame(0, DB::table('jobs')->count());
        } finally {
            DB::rollBack();
        }
        self::assertSame(0, DB::table('jobs')->count());
        self::assertSame(0, SettlementRun::query()->where('draw_id', $f['draw']->id)->count());
        self::assertSame('490.00', (string) $f['wallet']->fresh()->balance);
    }

    public function test_committed_after_commit_envelope_is_processed_by_the_real_worker(): void
    {
        $f = $this->publishedDrawWithBets([], 'commit');
        DB::beginTransaction();
        ProcessPrizeSettlementJob::dispatch((int) $f['draw']->id);
        self::assertSame(0, DB::table('jobs')->count());
        DB::commit();
        self::assertSame(1, DB::table('jobs')->count());
        $this->drain();
        self::assertTrue(SettlementRun::query()->where('draw_id', $f['draw']->id)->sole()->isComplete());
        self::assertSame('490.00', (string) $f['wallet']->fresh()->balance);
    }

    public function test_failure_audit_does_not_copy_exception_credentials(): void
    {
        $f = $this->publishedDrawWithBets([], 'audit');
        $marker = 'SYNTHETIC_PASSWORD_MUST_NOT_BE_COPIED';
        (new ProcessPrizeSettlementJob((int) $f['draw']->id))->failed(new RuntimeException($marker));
        $audit = AuditLog::query()->where('description', 'Prize settlement queue delivery failed; persisted run requires inspection')->sole();
        self::assertStringNotContainsString($marker, json_encode($audit->toArray(), JSON_THROW_ON_ERROR));
        self::assertSame(RuntimeException::class, $audit->metadata['exception_class']);
    }

    public function test_worker_defaults_cover_all_settlement_envelopes_and_timeout_visibility(): void
    {
        $entry = new ProcessPrizeSettlementJob(1);
        $chunk = new SettleDrawChunkJob(1, 1, 0);
        $finalizer = new FinalizeDrawSettlementJob(1, 1);
        foreach ([$entry, $chunk, $finalizer] as $job) {
            self::assertSame(QueueName::FinancialCritical->value, $job->queue);
            self::assertContains($job->queue, QueueName::priorityOrder());
            self::assertTrue($job->afterCommit);
            self::assertTrue($job->failOnTimeout);
            self::assertGreaterThan($job->timeout, config('queue.connections.database.retry_after'));
            self::assertGreaterThan($job->timeout, config('queue.connections.redis.retry_after'));
        }
    }
}
