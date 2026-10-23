<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\Draw\SettleDrawChunkJob;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Regression guard for the PHP 8.4 queue-trait compatibility issue.
 *
 * SettleDrawChunkJob uses Illuminate\Bus\Batchable, which declares a $batchId
 * property. Declaring a public readonly promoted constructor property named
 * $batchId collides with the trait slot on PHP 8.4 and produces a fatal error
 * during class load. This test exercises the four surfaces that must keep
 * working after the fix: class autoload, withBatchId() overwrite, queue
 * serialization round-trip, and the overlap-lock middleware.
 */
final class SettleDrawChunkJobPhp84CompatibilityTest extends TestCase
{
    public function test_class_autoloads_cleanly_under_php84(): void
    {
        $job = new SettleDrawChunkJob(
            drawId: 1,
            batchId: 'batch-phpunit-regression',
            chunkIndex: 0,
            fromBetId: 1,
            toBetId: 100,
            resultFingerprint: 'fp-regression',
        );
        $this->assertSame('batch-phpunit-regression', $job->batchId);
    }

    public function test_with_batch_id_overwrites_trait_slot(): void
    {
        $job = new SettleDrawChunkJob(1, 'original', 0, 1, 100, 'fp');
        $returned = $job->withBatchId('overwritten');
        $this->assertSame($job, $returned);
        $this->assertSame('overwritten', $job->batchId);
    }

    public function test_queue_serialize_unserialize_roundtrip_preserves_fields(): void
    {
        $job = new SettleDrawChunkJob(
            drawId: 42,
            batchId: 'b-roundtrip',
            chunkIndex: 3,
            fromBetId: 201,
            toBetId: 300,
            resultFingerprint: 'fp-roundtrip',
        );
        /** @var SettleDrawChunkJob $restored */
        $restored = unserialize(serialize($job));
        $this->assertSame(42, $restored->drawId);
        $this->assertSame('b-roundtrip', $restored->batchId);
        $this->assertSame(3, $restored->chunkIndex);
        $this->assertSame(201, $restored->fromBetId);
        $this->assertSame(300, $restored->toBetId);
        $this->assertSame('fp-roundtrip', $restored->resultFingerprint);
    }

    public function test_middleware_returns_without_overlapping_lock(): void
    {
        $job = new SettleDrawChunkJob(7, 'b-mw', 1, 1, 100, 'fp');
        $middleware = $job->middleware();
        $this->assertIsArray($middleware);
        $this->assertNotEmpty($middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
    }
}
