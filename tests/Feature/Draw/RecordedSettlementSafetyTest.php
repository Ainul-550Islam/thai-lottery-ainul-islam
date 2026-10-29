<?php

declare(strict_types=1);

namespace Tests\Feature\Draw;

use App\Enums\DrawStatus;
use App\Enums\PayoutStatus;
use App\Jobs\Draw\FinalizeDrawSettlementJob;
use App\Jobs\Draw\SettleDrawChunkJob;
use App\Models\AuditLog;
use App\Models\Bet;
use App\Models\BetItem;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\SettlementRun;
use App\Services\Draw\ChunkedSettlementOrchestrator;
use App\Services\Draw\RealPrizeSettlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Feature\Draw\Concerns\BuildsConfirmedSettlementDraws;
use Tests\Feature\Payment\PaymentTestCase;

final class RecordedSettlementSafetyTest extends PaymentTestCase
{
    use BuildsConfirmedSettlementDraws;

    private function service(): ChunkedSettlementOrchestrator
    {
        return app(ChunkedSettlementOrchestrator::class);
    }

    private function refused(callable $operation): void
    {
        try {
            $operation();
        } catch (\Exception $error) {
            $this->assertNotSame('', $error->getMessage());

            return;
        }
        $this->fail('Unsafe settlement operation was not refused.');
    }

    public function test_checkpoint_save_failure_rolls_back_wallet_payout_and_outcomes(): void
    {
        $this->configureSafety('LIVE');
        $f = $this->publishedDrawWithBets(['111'], 'checkpoint-rollback');
        $run = $this->service()->start($f['draw']->id, 2);
        $transactions = FinancialTransaction::query()->count();
        $ledger = LedgerEntry::query()->count();
        $balance = (string) $f['wallet']->fresh()->balance;
        $events = SettlementRun::getEventDispatcher();
        SettlementRun::setEventDispatcher(clone $events);
        SettlementRun::saving(function (SettlementRun $saving): void {
            if ($saving->cursor_bet_id > 0) {
                throw new RuntimeException('injected checkpoint failure');
            }
        });
        try {
            $this->refused(fn () => $this->service()->runChunk($run));
        } finally {
            SettlementRun::setEventDispatcher($events);
        }
        $this->assertSame(0, $run->fresh()->cursor_bet_id);
        $this->assertSame(0, Payout::query()->count());
        $this->assertSame($transactions, FinancialTransaction::query()->count());
        $this->assertSame($ledger, LedgerEntry::query()->count());
        $this->assertSame($balance, (string) $f['wallet']->fresh()->balance);
        $this->assertSame(0, BetItem::query()->where('is_winner', true)->count());
        $this->assertSame('0.00', (string) $run->fresh()->total_prize);
        $this->assertSame(1, $this->service()->runChunk($run)->payouts_created);
    }

    public function test_final_audit_failure_rolls_back_draw_and_run_completion(): void
    {
        $this->configureSafety('DRY_RUN');
        $f = $this->publishedDrawWithBets(['111'], 'final-audit');
        $run = $this->service()->runChunk($this->service()->start($f['draw']->id, 2));
        $events = AuditLog::getEventDispatcher();
        AuditLog::setEventDispatcher(clone $events);
        AuditLog::creating(fn () => throw new RuntimeException('injected final audit failure'));
        try {
            $this->refused(fn () => $this->service()->finalize($run));
        } finally {
            AuditLog::setEventDispatcher($events);
        }
        $this->assertSame(DrawStatus::ResultPublished, $f['draw']->fresh()->status);
        $this->assertTrue($run->fresh()->isOpen());
        $this->assertNull($run->fresh()->completed_at);
        $this->assertSame(1, Payout::query()->count());
        $this->assertTrue($this->service()->finalize($run)->isComplete());
    }

    public function test_result_drift_is_refused_before_money_or_checkpoint(): void
    {
        $this->configureSafety('LIVE');
        $f = $this->publishedDrawWithBets(['111'], 'result-drift');
        $run = $this->service()->start($f['draw']->id, 2);
        DB::table('draw_results')->where('draw_id', $f['draw']->id)->update(['first_prize' => '999123']);
        $this->refused(fn () => $this->service()->runChunk($run));
        $this->assertSame(0, $run->fresh()->cursor_bet_id);
        $this->assertSame(0, Payout::query()->count());
        $this->assertSame('480.00', (string) $f['wallet']->fresh()->balance);
    }

    public function test_removed_participant_is_not_silently_skipped(): void
    {
        $this->configureSafety('LIVE');
        $f = $this->publishedDrawWithBets(['111'], 'removed-bet');
        $run = $this->service()->start($f['draw']->id, 2);
        Bet::query()->where('draw_id', $f['draw']->id)->orderByDesc('id')->firstOrFail()->delete();
        $this->refused(fn () => $this->service()->runChunk($run));
        $this->assertSame(0, $run->fresh()->cursor_bet_id);
        $this->assertSame(0, Payout::query()->count());
    }

    public function test_changed_selection_inputs_are_refused(): void
    {
        $this->configureSafety('LIVE');
        $f = $this->publishedDrawWithBets(['111'], 'selection-drift');
        $run = $this->service()->start($f['draw']->id, 2);
        DB::table('bet_items')->where('id', BetItem::query()->min('id'))->update(['payout_multiplier' => 999]);
        $this->refused(fn () => $this->service()->runChunk($run));
        $this->assertSame(0, Payout::query()->count());
        $this->assertSame(0, $run->fresh()->chunks_processed);
    }

    public function test_forged_counter_and_cursor_are_not_completion_proof(): void
    {
        $this->configureSafety('DRY_RUN');
        $f = $this->publishedDrawWithBets(['111'], 'forged-progress');
        $run = $this->service()->start($f['draw']->id, 2);
        $run->cursor_bet_id = $run->max_bet_id;
        $run->bets_settled = $run->bets_total;
        $run->save();
        $this->refused(fn () => $this->service()->finalize($run));
        $this->assertSame(DrawStatus::ResultPublished, $f['draw']->fresh()->status);
        $this->assertTrue($run->fresh()->isOpen());
    }

    public function test_stored_outcome_drift_blocks_finalization(): void
    {
        $this->configureSafety('DRY_RUN');
        $f = $this->publishedDrawWithBets(['111'], 'outcome-drift');
        $run = $this->service()->runChunk($this->service()->start($f['draw']->id, 2));
        DB::table('bet_items')->where('is_winner', true)->update(['actual_payout' => '1.00']);
        $this->refused(fn () => $this->service()->finalize($run));
        $this->assertSame(DrawStatus::ResultPublished, $f['draw']->fresh()->status);
        $this->assertFalse($run->fresh()->isComplete());
    }

    public function test_paused_run_is_not_automatically_resumed_by_a_worker(): void
    {
        Queue::fake();
        $this->configureSafety('LIVE');
        $f = $this->publishedDrawWithBets(['111'], 'paused');
        $run = $this->service()->start($f['draw']->id, 1);
        $run->status = SettlementRun::STATUS_ABORTED;
        $run->save();
        (new SettleDrawChunkJob($f['draw']->id, $run->id, 0))->handle($this->service());
        $this->assertSame(0, $run->fresh()->cursor_bet_id);
        $this->assertSame(0, Payout::query()->count());
        Queue::assertNotPushed(SettleDrawChunkJob::class);
        $this->refused(fn () => (new FinalizeDrawSettlementJob($f['draw']->id, $run->id))->handle($this->service()));
        $this->assertTrue($this->service()->resume($f['draw']->id)->isComplete());
    }

    public function test_worker_and_finalizer_refuse_foreign_run_ownership(): void
    {
        Queue::fake();
        $this->configureSafety('DRY_RUN');
        $f = $this->publishedDrawWithBets(['111'], 'owner-a');
        $g = $this->publishedDrawWithBets(['222'], 'owner-b');
        $run = $this->service()->start($f['draw']->id, 1);
        $other = $this->service()->start($g['draw']->id, 1);
        $this->refused(fn () => (new SettleDrawChunkJob($f['draw']->id, $other->id, 0))->handle($this->service()));
        $this->refused(fn () => (new FinalizeDrawSettlementJob($f['draw']->id, $other->id))->handle($this->service()));
        $this->assertSame(0, $run->fresh()->cursor_bet_id);
        $this->assertSame(0, $other->fresh()->cursor_bet_id);
        $this->assertSame(0, Payout::query()->count());
    }

    public function test_duplicate_worker_delivery_does_not_advance_or_pay_twice(): void
    {
        Queue::fake();
        $this->configureSafety('LIVE');
        $f = $this->publishedDrawWithBets(['111', '222'], 'duplicate-job');
        $run = $this->service()->start($f['draw']->id, 1);
        $job = new SettleDrawChunkJob($f['draw']->id, $run->id, 0);
        $job->handle($this->service());
        $balance = (string) $f['wallet']->fresh()->balance;
        $job->handle($this->service());
        $this->assertSame(1, $run->fresh()->cursor_bet_id);
        $this->assertSame(1, $run->fresh()->chunks_processed);
        $this->assertSame(1, Payout::query()->count());
        $this->assertSame($balance, (string) $f['wallet']->fresh()->balance);
        Queue::assertPushed(SettleDrawChunkJob::class, fn ($next) => $next->expectedCursor === 1);
        $this->refused(fn () => (new FinalizeDrawSettlementJob($f['draw']->id, $run->id))->handle($this->service()));
    }

    public function test_queue_continuation_and_finalizer_are_idempotent(): void
    {
        Queue::fake();
        $this->configureSafety('LIVE');
        $f = $this->publishedDrawWithBets(['111', '222', '333'], 'queue-chain');
        $run = $this->service()->start($f['draw']->id, 2);
        (new SettleDrawChunkJob($f['draw']->id, $run->id, 0))->handle($this->service());
        Queue::assertPushed(SettleDrawChunkJob::class, fn ($next) => $next->expectedCursor === 2);
        (new SettleDrawChunkJob($f['draw']->id, $run->id, 2))->handle($this->service());
        Queue::assertPushed(FinalizeDrawSettlementJob::class, fn ($final) => $final->runId === $run->id);
        $final = new FinalizeDrawSettlementJob($f['draw']->id, $run->id);
        $final->handle($this->service());
        $transactions = FinancialTransaction::query()->count();
        $balance = (string) $f['wallet']->fresh()->balance;
        $final->handle($this->service());
        $this->assertTrue($run->fresh()->isComplete());
        $this->assertSame(DrawStatus::Completed, $f['draw']->fresh()->status);
        $this->assertSame($transactions, FinancialTransaction::query()->count());
        $this->assertSame($balance, (string) $f['wallet']->fresh()->balance);
        $this->assertSame(1, Payout::query()->count());
    }

    public function test_legacy_unpinned_run_is_not_guessed_or_backfilled(): void
    {
        $this->configureSafety('DRY_RUN');
        $f = $this->publishedDrawWithBets(['111'], 'unpinned');
        $run = $this->service()->start($f['draw']->id, 2);
        $run->result_fingerprint = null;
        $run->participants_fingerprint = null;
        $run->save();
        $this->refused(fn () => $this->service()->runChunk($run));
        $this->refused(fn () => $this->service()->reopen($run));
        $this->assertNull($run->fresh()->result_fingerprint);
        $this->assertSame(0, Payout::query()->count());
    }

    public function test_opening_a_run_refuses_nested_transaction_without_creating_a_claim(): void
    {
        $this->configureSafety('LIVE');
        $f = $this->publishedDrawWithBets(['111'], 'nested-start');
        $transactions = FinancialTransaction::query()->count();
        $balance = (string) $f['wallet']->fresh()->balance;
        DB::transaction(function () use ($f): void {
            $this->refused(fn () => $this->service()->start((int) $f['draw']->id, 2));
            $this->assertSame(1, DB::transactionLevel());
            $this->assertSame(0, SettlementRun::query()->where('draw_id', $f['draw']->id)->count());
        });
        $this->assertSame($transactions, FinancialTransaction::query()->count());
        $this->assertSame($balance, (string) $f['wallet']->fresh()->balance);
        $this->assertSame(DrawStatus::ResultPublished, $f['draw']->fresh()->status);
    }

    public function test_nested_transaction_is_refused_without_mutation(): void
    {
        $this->configureSafety('LIVE');
        $f = $this->publishedDrawWithBets(['111'], 'nested');
        $run = $this->service()->start($f['draw']->id, 2);
        DB::transaction(function () use ($run): void {
            $this->refused(fn () => $this->service()->runChunk($run));
        });
        $this->assertSame(0, $run->fresh()->cursor_bet_id);
        $this->assertSame(0, Payout::query()->count());
    }

    public function test_live_can_be_suppressed_by_a_mid_run_kill_switch(): void
    {
        $this->configureSafety('LIVE');
        $f = $this->publishedDrawWithBets(['111'], 'kill-switch');
        $run = $this->service()->start($f['draw']->id, 2);
        $this->configureSafety('DISABLED');
        $run = $this->service()->run($f['draw']->id);
        $this->assertTrue($run->isComplete());
        $this->assertSame('LIVE', $run->safety_mode);
        $this->assertSame(PayoutStatus::Pending, Payout::query()->firstOrFail()->status);
        $this->assertSame('480.00', (string) $f['wallet']->fresh()->balance);
    }

    public function test_increasing_auto_approval_threshold_cannot_relax_the_pinned_limit(): void
    {
        $this->configureSafety('LIVE', '100.00');
        $f = $this->publishedDrawWithBets(['111'], 'approval-pin');
        $run = $this->service()->start($f['draw']->id, 2);
        $this->configureSafety('LIVE', '1000000.00');
        $run = $this->service()->run($f['draw']->id);
        $this->assertSame('100.00', (string) $run->approval_threshold);
        $this->assertSame(PayoutStatus::Pending, Payout::query()->firstOrFail()->status);
        $this->assertSame('480.00', (string) $f['wallet']->fresh()->balance);
    }

    public function test_mixed_currency_chunk_rolls_back_all_earlier_work_in_that_chunk(): void
    {
        $this->configureSafety('LIVE');
        $f = $this->publishedDrawWithBets(['111'], 'mixed-currency');
        DB::table('bets')->where('id', Bet::query()->max('id'))->update(['currency' => 'USD']);
        $run = $this->service()->start($f['draw']->id, 2);
        $this->refused(fn () => $this->service()->runChunk($run));
        $this->assertSame(0, Payout::query()->count());
        $this->assertSame('480.00', (string) $f['wallet']->fresh()->balance);
        $this->assertSame(0, $run->fresh()->cursor_bet_id);
    }

    public function test_empty_participant_cannot_be_counted_as_settled(): void
    {
        $this->configureSafety('LIVE');
        $f = $this->publishedDrawWithBets(['111'], 'empty-bet');
        BetItem::query()->where('bet_id', Bet::query()->max('id'))->delete();
        $run = $this->service()->start($f['draw']->id, 2);
        $this->refused(fn () => $this->service()->runChunk($run));
        $this->assertSame(0, Payout::query()->count());
        $this->assertSame(0, $run->fresh()->bets_settled);
        $this->assertSame('480.00', (string) $f['wallet']->fresh()->balance);
    }

    public function test_missing_payout_obligation_blocks_completion(): void
    {
        $this->configureSafety('DRY_RUN');
        $f = $this->publishedDrawWithBets(['111'], 'missing-obligation');
        $run = $this->service()->runChunk($this->service()->start($f['draw']->id, 2));
        Payout::query()->firstOrFail()->delete();
        $this->refused(fn () => $this->service()->finalize($run));
        $this->assertFalse($run->fresh()->isComplete());
        $this->assertSame(DrawStatus::ResultPublished, $f['draw']->fresh()->status);
    }

    public function test_a_late_added_participant_is_refused_before_credit(): void
    {
        $this->configureSafety('LIVE');
        $f = $this->publishedDrawWithBets(['111'], 'late-participant');
        $run = $this->service()->start($f['draw']->id, 2);
        Bet::factory()->create(['draw_id' => $f['draw']->id]);
        $this->refused(fn () => $this->service()->runChunk($run));
        $this->assertSame(0, Payout::query()->count());
        $this->assertSame(0, $run->fresh()->cursor_bet_id);
        $this->assertSame('480.00', (string) $f['wallet']->fresh()->balance);
    }

    public function test_dry_run_cli_refuses_a_previously_live_pinned_queue_run(): void
    {
        Queue::fake();
        $this->configureSafety('LIVE');
        $f = $this->publishedDrawWithBets(['111'], 'dry-cli');
        $run = $this->service()->start($f['draw']->id, 2);
        $this->configureSafety('DRY_RUN');
        $this->artisan('lottery:settle-draw-chunked', [
            '--draw' => (string) $f['draw']->id, '--queue' => true, '--dry-run' => true,
        ])->assertExitCode(1);
        $this->artisan('lottery:settle-draw-chunked', [
            '--draw' => (string) $f['draw']->id, '--queue' => true, '--dry-run' => true, '--resume' => true,
        ])->assertExitCode(1);
        Queue::assertNotPushed(SettleDrawChunkJob::class);
        $this->assertSame(0, $run->fresh()->cursor_bet_id);
        $this->assertSame(0, Payout::query()->count());
    }

    public function test_stricter_current_approval_policy_is_also_honored(): void
    {
        $this->configureSafety('LIVE', '1000000.00');
        $f = $this->publishedDrawWithBets(['111'], 'stricter-approval');
        $run = $this->service()->start($f['draw']->id, 2);
        $this->configureSafety('LIVE', '100.00');
        $this->service()->run($f['draw']->id);
        $this->assertSame('1000000.00', (string) $run->approval_threshold);
        $this->assertSame(PayoutStatus::Pending, Payout::query()->firstOrFail()->status);
        $this->assertSame('480.00', (string) $f['wallet']->fresh()->balance);
    }

    public function test_chunked_and_inline_settlement_produce_the_same_obligation_and_credit(): void
    {
        $this->configureSafety('LIVE');
        $inline = $this->publishedDrawWithBets(['111', '222', '333'], 'inline-parity');
        $chunked = $this->publishedDrawWithBets(['111', '222', '333'], 'chunk-parity');
        $engine = app(RealPrizeSettlementService::class);
        $engine->settle($inline['draw']->id);
        $run = $this->service()->run($chunked['draw']->id, 2);
        $this->assertTrue($run->isComplete());
        $this->assertSame((string) $inline['wallet']->fresh()->balance, (string) $chunked['wallet']->fresh()->balance);
        $this->assertSame('9000.00', (string) $run->total_prize);
        $this->assertSame(1, Payout::query()->where('draw_id', $inline['draw']->id)->count());
        $this->assertSame(1, Payout::query()->where('draw_id', $chunked['draw']->id)->count());
        $this->assertSame(DrawStatus::Completed, $inline['draw']->fresh()->status);
    }

    public function test_legacy_finalizer_entry_point_cannot_bypass_persisted_completion_proof(): void
    {
        $this->configureSafety('DRY_RUN');
        $f = $this->publishedDrawWithBets(['111'], 'legacy-finalizer');
        $run = $this->service()->start($f['draw']->id, 2);
        $this->refused(fn () => app(RealPrizeSettlementService::class)
            ->finalizeChunkedSettlement($f['draw'], ['bets_settled' => 2, 'total_prize' => '9000.00']));
        $this->assertSame(DrawStatus::ResultPublished, $f['draw']->fresh()->status);
        $this->assertTrue($run->fresh()->isOpen());
    }
}
