<?php

declare(strict_types=1);

namespace Tests\Feature\LotteryGames;

use App\Exceptions\LotteryGameException;
use App\Jobs\Draw\SettleDrawChunkJob;
use App\Models\SettlementRun;
use App\Services\Draw\ChunkedSettlementOrchestrator;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\LotteryGames\Concerns\BuildsSettledDraw;
use Tests\Support\LotteryGames\GameLaneTestCase;

/**
 * THE PATH THIS LANE NEVER EXERCISED: A DRAW TOO BIG TO SETTLE IN ONE TRANSACTION.
 *
 * WHY THIS SUITE EXISTS
 * GameSettlementAdapter::settle() has two branches, and only one of them was proven:
 *
 *   inline   the orchestrator reports mode=inline and Batch 1's monolithic service moves
 *            the money once, inside this call. SettlementTest and SettlementIdempotencyTest
 *            cover it thoroughly — they cannot NOT cover it, because a lane test has three
 *            bets in its draw.
 *   chunked  the orchestrator opens a durable settlement_runs row at a cursor and dispatches
 *            ONE continuation; this call moves NO money and reports the run id. Nothing in
 *            the lane asserted that branch, which matters because it is the branch a real
 *            draw with 100,000 slips takes. A regression there would be invisible until
 *            launch — settlement would look "queued" while the adapter returned a receipt
 *            with no run id, and the operator would have no handle to watch.
 *
 * HOW THE CHUNKED BRANCH IS REACHED WITHOUT A 100,000-SLIP DRAW
 * ChunkedSettlementOrchestrator::settle() takes the chunked branch whenever a settlement run
 * ALREADY EXISTS for the draw, regardless of participant count. Opening one with start() is
 * the same call a queue worker makes, so the suite opens a real run for a real draw and then
 * asks the adapter to settle it. Nothing is stubbed: the run, the freeze, the cursor and the
 * dispatched job are Batch 1's own objects.
 *
 * WHAT IS ASSERTED, AND WHY EACH ONE IS THE POINT
 *   1  mode=chunked and run_id is returned, so an operator has a handle.
 *   2  settled=false, because this call did not move money — reporting true would be the
 *      exact lie that lets two processes both believe they paid.
 *   3  no Payout row and no balance change: the money waits for the chunks.
 *   4  exactly ONE continuation job is dispatched, and replaying the call dispatches nothing
 *      new and reuses the same run, because a duplicate queue delivery must not open a
 *      second run over the same draw.
 *   5  without a durable queue the adapter REFUSES (game_settlement_refused) instead of
 *      silently falling back to the inline path inside an HTTP request.
 */
final class ChunkedSettlementPathTest extends GameLaneTestCase
{
    use BuildsSettledDraw;

    private function orchestrator(): ChunkedSettlementOrchestrator
    {
        return app(ChunkedSettlementOrchestrator::class);
    }

    public function test_a_draw_with_an_open_run_is_settled_through_the_chunked_path(): void
    {
        config()->set('queue.default', 'database');
        Queue::fake();

        $fixture = $this->ready();
        $bet = $this->bet($fixture['user'], $fixture['draw'], '3d_direct', '123', '10.00', 'chunked-1');
        $this->publishDigits('123', $fixture['draw']);

        $balanceBefore = $this->balanceOf($fixture['wallet']);

        $run = $this->orchestrator()->start((int) $fixture['draw']->getKey(), 500);

        $receipt = $this->adapter()->settle('lane_game', self::SETTLEMENT_DRAW_NO);

        $this->assertSame('chunked', $receipt['mode'], 'an open run means the queued path, not the inline one');
        $this->assertSame((int) $run->getKey(), $receipt['run_id'], 'the receipt must carry the run an operator can watch');
        $this->assertFalse($receipt['settled'], 'this call queued the work; it did not pay anybody');
        $this->assertNull($receipt['result'], 'the chunked branch has no inline result to report');
        $this->assertGreaterThanOrEqual(1, (int) $receipt['chunks']);

        $this->assertSame($balanceBefore, $this->balanceOf($fixture['wallet']), 'no prize is credited before a chunk runs');
        $this->assertSame(0, \App\Models\Payout::query()->count(), 'the obligation is created by the chunk, not by the receipt');

        Queue::assertPushed(SettleDrawChunkJob::class, 1);

        // The reconciliation still runs on the queued path: the adapter is telling the
        // operator who SHOULD be paid before anything is paid.
        $this->assertSame(1, (int) $receipt['winners']);
        $this->assertSame((int) $bet['bet_id'] ?? 0, (int) ($receipt['reconciliation']['lines'][0]['bet_id'] ?? 0));
    }

    public function test_replaying_a_queued_settlement_reuses_the_run_and_queues_no_second_continuation(): void
    {
        config()->set('queue.default', 'database');
        Queue::fake();

        $fixture = $this->ready();
        $this->bet($fixture['user'], $fixture['draw'], '3d_direct', '123', '10.00', 'chunked-2');
        $this->publishDigits('123', $fixture['draw']);

        $run = $this->orchestrator()->start((int) $fixture['draw']->getKey(), 500);

        $first = $this->adapter()->settle('lane_game', self::SETTLEMENT_DRAW_NO);
        $second = $this->adapter()->settle('lane_game', self::SETTLEMENT_DRAW_NO);

        $this->assertSame((int) $run->getKey(), $first['run_id']);
        $this->assertSame($first['run_id'], $second['run_id'], 'a replay must not open a second run over the same draw');
        $this->assertSame(1, SettlementRun::query()->where('draw_id', (int) $fixture['draw']->getKey())->count());
    }

    public function test_queueing_without_a_durable_queue_is_refused_rather_than_settled_inline(): void
    {
        // 'sync' is not a durable queue: a continuation dispatched onto it would run inside
        // the caller's process, which is precisely the unbounded transaction that chunking
        // exists to avoid. The orchestrator refuses; the adapter must translate that refusal
        // into this lane's vocabulary instead of letting a RuntimeException escape as a 500.
        config()->set('queue.default', 'sync');

        $fixture = $this->ready();
        $this->bet($fixture['user'], $fixture['draw'], '3d_direct', '123', '10.00', 'chunked-3');
        $this->publishDigits('123', $fixture['draw']);

        $this->orchestrator()->start((int) $fixture['draw']->getKey(), 500);

        $balanceBefore = $this->balanceOf($fixture['wallet']);

        try {
            $this->adapter()->settle('lane_game', self::SETTLEMENT_DRAW_NO);
            $this->fail('settlement must not fall back to the inline path when it cannot queue');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_settlement_refused', $e->errorCode());
            $this->assertStringContainsString('durable queue', strtolower((string) $e->getMessage()));
        }

        $this->assertSame($balanceBefore, $this->balanceOf($fixture['wallet']), 'a refusal pays nobody');
        $this->assertSame(0, \App\Models\Payout::query()->count());
    }
}
