<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\Enums\DrawStatus;
use App\Enums\PayoutStatus;
use App\Enums\QueueName;
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

    public function test_concurrent_duplicate_checkpoint_credits_a_winner_only_once(): void
    {
        $f = $this->publishedDrawWithBets(['111', '222'], 'race-chunk');
        $run = app(ChunkedSettlementOrchestrator::class)->start((int) $f['draw']->id, 2);
        $finance = FinancialTransaction::query()->count();
        $entries = LedgerEntry::query()->count();
        $commands = array_fill(0, 2, ['action' => 'chunk', 'run_id' => (int) $run->id, 'cursor' => 0]);
        $out = $this->serverRace($commands, 'draws', (int) $f['draw']->id, $this->settings());
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
            'draws', (int) $f['draw']->id, $this->settings('0.00'));
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
            'draws', (int) $f['draw']->id, $this->settings());
        $this->assertSame(['worked', 'worked'], array_column($out, 'outcome'), json_encode($out));
        $this->assertSame(2, $run->fresh()->bets_settled);
        $this->assertSame(1, $run->fresh()->chunks_processed);
        $this->assertSame(1, Payout::query()->count());
        $this->assertSame('9470.00', (string) $f['wallet']->fresh()->balance);
        for ($i = 0; $i < 12 && DB::table('jobs')->count() > 0; $i++) {
            app('queue.worker')->runNextJob('database', QueueName::workerQueueString(), new WorkerOptions(sleep: 0, maxTries: 2, timeout: 180));
        }
        $this->assertSame(0, DB::table('jobs')->count());
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
