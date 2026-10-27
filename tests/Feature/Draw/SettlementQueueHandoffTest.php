<?php

declare(strict_types=1);

namespace Tests\Feature\Draw;

use App\Enums\QueueName;
use App\Jobs\Draw\SettleDrawChunkJob;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\SettlementRun;
use App\Services\Draw\ChunkedSettlementOrchestrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Draw\Concerns\BuildsConfirmedSettlementDraws;
use Tests\Feature\Payment\PaymentTestCase;

/** Operator handoff must never silently mean inline money or a discarded message. */
final class SettlementQueueHandoffTest extends PaymentTestCase
{
    use BuildsConfirmedSettlementDraws;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureSafety();
    }

    public static function refusedDrivers(): array
    {
        return ['sync' => ['sync'], 'discard' => ['null'], 'background' => ['background'],
            'deferred' => ['deferred'], 'failover' => ['failover'], 'unreviewed' => ['custom-unreviewed']];
    }

    private function refused(array $options): void
    {
        $thrown = false;
        try {
            $exit = Artisan::call('lottery:settle-draw-chunked', $options);
        } catch (\Throwable) {
            $thrown = true;
            $exit = null;
        }
        $this->assertFalse($thrown, 'Operator refusal must not escape as an uncaught queue exception.');
        $this->assertSame(1, $exit);
    }

    #[DataProvider('refusedDrivers')]
    public function test_queue_flag_refuses_non_durable_driver_before_opening_run(string $driver): void
    {
        config(['queue.default' => 'handoff-test', 'queue.connections.handoff-test.driver' => $driver]);
        $f = $this->publishedDrawWithBets(['111', '222'], 'handoff-driver');
        $finance = FinancialTransaction::query()->count();
        $entries = LedgerEntry::query()->count();
        $this->refused(['--draw' => (string) $f['draw']->id, '--queue' => true]);
        $this->assertSame(0, SettlementRun::query()->count());
        $this->assertSame(0, Payout::query()->count());
        $this->assertSame('470.00', (string) $f['wallet']->fresh()->balance);
        $this->assertSame($finance, FinancialTransaction::query()->count());
        $this->assertSame($entries, LedgerEntry::query()->count());
    }

    public function test_paused_run_without_resume_is_not_reported_as_dispatched(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        $f = $this->publishedDrawWithBets(['111', '222'], 'handoff-paused');
        $run = app(ChunkedSettlementOrchestrator::class)->start((int) $f['draw']->id, 1);
        $run->update(['status' => SettlementRun::STATUS_ABORTED, 'failure_reason' => 'operator review']);
        $this->refused(['--draw' => (string) $f['draw']->id, '--queue' => true]);
        Queue::assertNothingPushed();
        $this->assertSame(SettlementRun::STATUS_ABORTED, $run->fresh()->status);
        $this->assertSame('operator review', $run->fresh()->failure_reason);
        $this->assertSame('470.00', (string) $f['wallet']->fresh()->balance);
    }

    public function test_resume_refuses_sync_without_reopening_aborted_run(): void
    {
        config(['queue.default' => 'sync']);
        $f = $this->publishedDrawWithBets(['111', '222'], 'handoff-resume-sync');
        $run = app(ChunkedSettlementOrchestrator::class)->start((int) $f['draw']->id, 1);
        $run->update(['status' => SettlementRun::STATUS_ABORTED, 'failure_reason' => 'operator review']);
        $this->refused(['--draw' => (string) $f['draw']->id, '--queue' => true, '--resume' => true]);
        $this->assertSame(SettlementRun::STATUS_ABORTED, $run->fresh()->status);
        $this->assertSame('operator review', $run->fresh()->failure_reason);
        $this->assertSame(0, $run->fresh()->bets_settled);
        $this->assertSame(0, Payout::query()->count());
        $this->assertSame('470.00', (string) $f['wallet']->fresh()->balance);
    }

    public function test_status_with_queue_remains_read_only_even_when_default_is_sync(): void
    {
        config(['queue.default' => 'sync']);
        $f = $this->publishedDrawWithBets(['111'], 'handoff-status');
        $this->assertSame(0, Artisan::call('lottery:settle-draw-chunked', [
            '--draw' => (string) $f['draw']->id, '--status' => true, '--queue' => true,
        ]));
        $this->assertSame(0, SettlementRun::query()->count());
        $this->assertSame(0, Payout::query()->count());
        $this->assertSame('480.00', (string) $f['wallet']->fresh()->balance);
    }

    public function test_actual_database_handoff_preserves_cursor_and_defers_all_money(): void
    {
        config(['queue.default' => 'database']);
        $f = $this->publishedDrawWithBets(['111', '222'], 'handoff-durable');
        $this->assertSame(0, Artisan::call('lottery:settle-draw-chunked', [
            '--draw' => (string) $f['draw']->id, '--queue' => true, '--chunk' => '1',
        ]));
        $run = SettlementRun::query()->sole();
        $this->assertTrue($run->isOpen());
        $this->assertSame(0, $run->bets_settled);
        $row = DB::table('jobs')->sole();
        $this->assertSame(QueueName::FinancialCritical->value, $row->queue);
        $payload = json_decode($row->payload, true, 32, JSON_THROW_ON_ERROR);
        $job = unserialize($payload['data']['command'], ['allowed_classes' => [SettleDrawChunkJob::class]]);
        $this->assertInstanceOf(SettleDrawChunkJob::class, $job);
        $this->assertSame((int) $f['draw']->id, $job->drawId);
        $this->assertSame((int) $run->id, $job->runId);
        $this->assertSame(0, $job->expectedCursor);
        $this->assertSame(0, Payout::query()->count());
        $this->assertSame('470.00', (string) $f['wallet']->fresh()->balance);
    }

    public function test_dispatch_rejection_returns_failure_and_preserves_open_run(): void
    {
        config(['queue.default' => 'database']);
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('synthetic-private-broker-detail'));
        $f = $this->publishedDrawWithBets(['111', '222'], 'handoff-rejected');
        $this->refused(['--draw' => (string) $f['draw']->id, '--queue' => true, '--chunk' => '1']);
        $run = SettlementRun::query()->sole();
        $this->assertTrue($run->isOpen());
        $this->assertSame(0, $run->bets_settled);
        $this->assertSame(0, Payout::query()->count());
        $this->assertSame('470.00', (string) $f['wallet']->fresh()->balance);
        $this->assertStringContainsString('not confirmed', Artisan::output());
        $this->assertStringNotContainsString('synthetic-private-broker-detail', Artisan::output());
        $this->assertStringNotContainsString('Dispatched', Artisan::output());
    }

    public function test_explicit_resume_uses_recorded_cursor_without_recrediting_winner(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        $f = $this->publishedDrawWithBets(['111', '222'], 'handoff-cursor');
        $orchestrator = app(ChunkedSettlementOrchestrator::class);
        $run = $orchestrator->runChunk($orchestrator->start((int) $f['draw']->id, 1));
        $cursor = (int) $run->cursor_bet_id;
        $this->assertGreaterThan(0, $cursor);
        $this->assertSame(1, $run->bets_settled);
        $run->update(['status' => SettlementRun::STATUS_ABORTED, 'failure_reason' => 'reviewed fixture interruption']);
        $finance = FinancialTransaction::query()->count();
        $entries = LedgerEntry::query()->count();
        $this->assertSame(0, Artisan::call('lottery:settle-draw-chunked', [
            '--draw' => (string) $f['draw']->id, '--queue' => true, '--resume' => true,
        ]));
        Queue::assertPushed(SettleDrawChunkJob::class, fn (SettleDrawChunkJob $job): bool => $job->runId === (int) $run->id && $job->expectedCursor === $cursor);
        Queue::assertPushed(SettleDrawChunkJob::class, 1);
        $this->assertTrue($run->fresh()->isOpen());
        $this->assertSame($cursor, $run->fresh()->cursor_bet_id);
        $this->assertNull($run->fresh()->failure_reason);
        $this->assertSame(1, Payout::query()->count());
        $this->assertSame('9470.00', (string) $f['wallet']->fresh()->balance);
        $this->assertSame($finance, FinancialTransaction::query()->count());
        $this->assertSame($entries, LedgerEntry::query()->count());
    }
}
