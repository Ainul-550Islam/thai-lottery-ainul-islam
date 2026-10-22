<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Jobs\Draw\FinalizeDrawSettlementJob;
use App\Jobs\Draw\ProcessPrizeSettlementJob;
use App\Jobs\Draw\SettleDrawChunkJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Payment\PaymentTestCase;

/** Actual database queue payload/release contract; does not execute settlement. */
final class SettlementRecoveryPolicyTest extends PaymentTestCase
{
    public static function envelopes(): array
    {
        return ['entry' => [ProcessPrizeSettlementJob::class, 2, 180],
            'chunk' => [SettleDrawChunkJob::class, 3, 120],
            'finalizer' => [FinalizeDrawSettlementJob::class, 3, 120]];
    }

    #[DataProvider('envelopes')]
    public function test_release_preserves_deadline_and_execution_safety_budgets(string $class, int $exceptions, int $timeout): void
    {
        $now = now()->startOfSecond();
        $epoch = $now->timestamp;
        $this->travelTo($now);
        try {
            config(['queue.default' => 'database']);
            $envelope = match ($class) {
                ProcessPrizeSettlementJob::class => new $class(1234),
                SettleDrawChunkJob::class => new $class(1234, 5678, 0),
                default => new $class(1234, 5678),
            };
            $queue = Queue::connection('database');
            $queue->push($envelope, '', $envelope->queue);
            $original = DB::table('jobs')->sole()->payload;
            $payload = json_decode($original, true, 32, JSON_THROW_ON_ERROR);
            $this->assertSame($epoch + 1800, $payload['retryUntil']);
            $this->assertSame($exceptions, $payload['maxExceptions']);
            $this->assertSame($exceptions, $payload['maxTries']);
            $this->assertSame($timeout, $payload['timeout']);
            $this->assertTrue($payload['failOnTimeout']);
            $delivery = $queue->pop($envelope->queue);
            $this->assertNotNull($delivery);
            $this->assertSame(1, $delivery->attempts());
            $this->travel(20)->seconds();
            $delivery->release(10);
            $this->assertSame($original, DB::table('jobs')->sole()->payload, 'Release must not manufacture a new recovery deadline.');
            $this->travel(11)->seconds();
            $retry = $queue->pop($envelope->queue);
            $this->assertNotNull($retry);
            $this->assertSame(2, $retry->attempts());
            $this->assertSame($epoch + 1800, $retry->retryUntil());
            $retry->delete();
            $this->assertSame(0, DB::table('jobs')->count());
        } finally {
            $this->travelBack();
        }
    }
}
