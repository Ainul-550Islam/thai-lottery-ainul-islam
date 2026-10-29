<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\Enums\DrawStatus;
use App\Enums\PayoutStatus;
use App\Enums\QueueName;
use App\Exceptions\SettlementSimulationException;
use App\Jobs\Draw\SettleDrawChunkJob;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\SettlementRun;
use App\Services\Draw\ChunkedSettlementOrchestrator;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Draw\Concerns\BuildsConfirmedSettlementDraws;
use Tests\Feature\Payment\PaymentTestCase;
use Tests\Support\RunsServerFinancialRaces;

final class SettlementServerRaceTest extends PaymentTestCase
{
    use BuildsConfirmedSettlementDraws;
    use RunsServerFinancialRaces;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireServerRace();
        $this->configureSafety('LIVE');
        config(['queue.default' => 'database']);
    }

    public function test_concurrent_run_start_returns_one_durable_identity(): void
    {
        $f = $this->publishedDrawWithBets(['111'], 'race-start');
        $commands = [['action' => 'start', 'draw_id' => (int) $f['draw']->id],
            ['action' => 'start', 'draw_id' => (int) $f['draw']->id]];
        $out = $this->serverRace($commands, 'draws', (int) $f['draw']->id, $this->settings());
        $this->assertSame(['started', 'started'], array_column($out, 'outcome'), json_encode($out));
        $this->assertSame($out[0]['run_id'], $out[1]['run_id']);
        $this->assertSame(1, SettlementRun::query()->count());
        $this->assertSame(0, Payout::query()->count());
        $this->assertSame('480.00', (string) $f['wallet']->fresh()->balance);
    }

    public function test_concurrent_chunked_start_and_inline_settlement_cannot_overlap(): void
    {
        $f = $this->publishedDrawWithBets([], 'race-start-inline');
        $finance = FinancialTransaction::query()->count();
        $entries = LedgerEntry::query()->count();
        $drawId = (int) $f['draw']->id;
        $out = $this->serverRace([['action' => 'start', 'draw_id' => $drawId],
            ['action' => 'inline_settlement', 'draw_id' => $drawId]], 'draws', $drawId, $this->settings());

        if ($out[0]['outcome'] === 'started') {
            $this->assertSame('refused', $out[1]['outcome'], json_encode($out));
            $this->assertSame(SettlementSimulationException::CODE_ALREADY_RUNNING, $out[1]['code']);
            $this->assertSame(1, SettlementRun::query()->count());
            $this->assertSame(0, Payout::query()->count());
            $this->assertSame(DrawStatus::ResultPublished, $f['draw']->fresh()->status);
            $this->assertTrue(app(ChunkedSettlementOrchestrator::class)->resume($drawId)->isComplete());
        } else {
            $this->assertSame('refused', $out[0]['outcome'], json_encode($out));
            $this->assertSame(SettlementSimulationException::CODE_SELECTION_UNREADABLE, $out[0]['code']);
            $this->assertSame('inline_settled', $out[1]['outcome'], json_encode($out));
            $this->assertSame(0, SettlementRun::query()->count());
        }

        $this->assertSame(DrawStatus::Completed, $f['draw']->fresh()->status);
        $this->assertSame(1, Payout::query()->count());
        $this->assertSame('9490.00', (string) $f['wallet']->fresh()->balance);
        $this->assertSame($finance + 1, FinancialTransaction::query()->count());
        $this->assertSame($entries + 2, LedgerEntry::query()->count());
    }

    public function test_concurrent_reopen_and_finalizer_cannot_restore_a_completed_run_to_open(): void
    {
        $f = $this->publishedDrawWithBets(['111'], 'race-reopen-finalize');
        $orchestrator = app(ChunkedSettlementOrchestrator::class);
        $run = $orchestrator->runChunk($orchestrator->start((int) $f['draw']->id, 2), 0);
        $balance = (string) $f['wallet']->fresh()->balance;
        $finance = FinancialTransaction::query()->count();
        $entries = LedgerEntry::query()->count();
        $out = $this->serverRace([['action' => 'settlement_reopen', 'run_id' => (int) $run->id],
            ['action' => 'settlement_finalize', 'run_id' => (int) $run->id]],
            'settlement_runs', (int) $run->id, $this->settings());

        $this->assertSame('reopened_or_read', $out[0]['outcome'], json_encode($out));
        $this->assertContains($out[0]['status'], [SettlementRun::STATUS_OPEN, SettlementRun::STATUS_COMPLETED]);
        $this->assertSame('finalized', $out[1]['outcome'], json_encode($out));
        $this->assertSame(SettlementRun::STATUS_COMPLETED, $out[1]['status']);
        $this->assertTrue($run->fresh()->isComplete());
        $this->assertSame(DrawStatus::Completed, $f['draw']->fresh()->status);
        $this->assertSame(1, Payout::query()->count());
        $this->assertSame($balance, (string) $f['wallet']->fresh()->balance);
        $this->assertSame($finance, FinancialTransaction::query()->count());
        $this->assertSame($entries, LedgerEntry::query()->count());
    }

    public function test_concurrent_duplicate_checkpoint_credits_a_winner_only_once(): void
    {
        $f = $this->publishedDrawWithBets(['111', '222'], 'race-chunk');
        $run = app(ChunkedSettlementOrchestrator::class)->start((int) $f['draw']->id, 2);
        $finance = FinancialTransaction::query()->count();
        $entries = LedgerEntry::query()->count();
        $commands = array_fill(0, 2, ['action' => 'chunk', 'run_id' => (int) $run->id, 'cursor' => 0]);
        $out = $this->serverRace($commands, 'settlement_runs', (int) $run->id, $this->settings());
        $this->assertSame(['chunked', 'chunked'], array_column($out, 'outcome'), json_encode($out));
        $this->assertSame([2, 2], array_column($out, 'bets_settled'));
        $this->assertSame(1, $run->fresh()->chunks_processed);
        $this->assertSame(2, $run->fresh()->bets_settled);
        $this->assertSame(1, Payout::query()->count());
        $this->assertSame(PayoutStatus::Completed, Payout::query()->firstOrFail()->status);
        $this->assertSame('9470.00', (string) $f['wallet']->fresh()->balance);
        $this->assertSame($finance + 1, FinancialTransaction::query()->count());
        $this->assertSame($entries + 2, LedgerEntry::query()->count());
        $this->assertSame(DrawStatus::ResultPublished, $f['draw']->fresh()->status);
    }

    public function test_concurrent_checkpoint_keeps_manual_approval_obligations_uncredited(): void
    {
        $this->configureSafety('LIVE', '0.00');
        $f = $this->publishedDrawWithBets(['111'], 'race-approval');
        $run = app(ChunkedSettlementOrchestrator::class)->start((int) $f['draw']->id, 2);
        $finance = FinancialTransaction::query()->count();
        $entries = LedgerEntry::query()->count();
        $out = $this->serverRace(array_fill(0, 2, ['action' => 'chunk', 'run_id' => (int) $run->id, 'cursor' => 0]),
            'settlement_runs', (int) $run->id, $this->settings('0.00'));
        $this->assertSame(['chunked', 'chunked'], array_column($out, 'outcome'), json_encode($out));
        $this->assertSame(1, Payout::query()->count());
        $this->assertSame(PayoutStatus::Pending, Payout::query()->firstOrFail()->status);
        $this->assertSame('480.00', (string) $f['wallet']->fresh()->balance);
        $this->assertSame($finance, FinancialTransaction::query()->count());
        $this->assertSame($entries, LedgerEntry::query()->count());
        $this->assertSame(1, $run->fresh()->chunks_processed);
    }

    public function test_concurrent_serialized_workers_consume_duplicate_envelopes_without_duplicate_payout(): void
    {
        $f = $this->publishedDrawWithBets(['111', '222'], 'race-worker');
        $o = app(ChunkedSettlementOrchestrator::class);
        $run = $o->start((int) $f['draw']->id, 2);
        $queue = Queue::connection('database');
        $queue->push(new SettleDrawChunkJob((int) $f['draw']->id, (int) $run->id, 0));
        $queue->push(new SettleDrawChunkJob((int) $f['draw']->id, (int) $run->id, 0));
        $finance = FinancialTransaction::query()->count();
        $out = $this->serverRace([['action' => 'worker'], ['action' => 'worker']],
            'settlement_runs', (int) $run->id, $this->settings());
        $this->assertSame(['worked', 'worked'], array_column($out, 'outcome'), json_encode($out));
        $this->assertSame(2, $run->fresh()->bets_settled);
        $this->assertSame(1, $run->fresh()->chunks_processed);
        $this->assertSame(1, Payout::query()->count());
        $this->assertSame('9470.00', (string) $f['wallet']->fresh()->balance);
        // Fixture purchase, publication and settlement also enqueue notification
        // jobs; drain all queues with a hard safety bound, then still require none.
        for ($i = 0; $i < 200 && DB::table('jobs')->count() > 0; $i++) {
            app('queue.worker')->runNextJob('database', QueueName::workerQueueString(), new WorkerOptions(sleep: 0, maxTries: 2, timeout: 180));
        }
        $remainingJobs = DB::table('jobs')->get()->map(fn ($job) => [
            'queue' => $job->queue, 'attempts' => $job->attempts,
            'available_at' => $job->available_at, 'reserved_at' => $job->reserved_at,
            'name' => json_decode($job->payload, true)['displayName'] ?? 'unknown',
        ])->all();
        $this->assertSame(0, count($remainingJobs), json_encode($remainingJobs));
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertTrue($run->fresh()->isComplete());
        $this->assertSame(DrawStatus::Completed, $f['draw']->fresh()->status);
        $this->assertSame(1, Payout::query()->count());
        $this->assertSame($finance + 1, FinancialTransaction::query()->count());
        $this->assertSame('9470.00', (string) $f['wallet']->fresh()->balance);
    }

    private function settings(string $threshold = '1000000.00'): array
    {
        return ['finance.prize_payout.safety_mode' => 'LIVE',
            'finance.prize_payout.auto_approve_below' => $threshold,
            'finance.prize_payout.require_approval_at_or_above' => $threshold];
    }
}
