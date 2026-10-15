<?php

declare(strict_types=1);

namespace App\Jobs\Draw;

use App\Exceptions\LegacyChunkedSettlementUnavailable;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;

/**
 * Loadable legacy queue payload with an explicitly unavailable money handler.
 *
 * This is not a settlement implementation. The previous class could not even
 * compose: its promoted readonly batchId conflicted with Batchable's mutable
 * framework-owned batchId. It also called an absent per-bet method and queried
 * an unsupported result-fingerprint column. Those execution paths are retired,
 * not replaced with payout automation or a successful no-op.
 *
 * Plan correlation and Laravel batch membership are different identities.
 * Keep the existing six constructor parameter names for current producers,
 * but store the producer's batchId argument as immutable planId. Batchable alone
 * owns batchId; Laravel may assign the real queue batch id through withBatchId.
 */
final class SettleDrawChunkJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** A permanent capability refusal must not churn through transient retries. */
    public int $tries = 1;

    public int $maxExceptions = 1;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    public readonly string $planId;

    public function __construct(
        public readonly int $drawId,
        string $batchId,
        public readonly int $chunkIndex,
        public readonly int $fromBetId,
        public readonly int $toBetId,
        public readonly string $resultFingerprint,
    ) {
        if ($drawId < 1 || $chunkIndex < 0 || $fromBetId < 1 || $toBetId < $fromBetId
            || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/D', $batchId) !== 1
            || preg_match('/^[a-fA-F0-9]{64}$/D', $resultFingerprint) !== 1) {
            throw new InvalidArgumentException('Invalid legacy chunk metadata. No execution is available.');
        }

        $this->planId = $batchId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("settle-draw:{$this->drawId}:chunk:{$this->chunkIndex}"))
                ->expireAfter(300),
        ];
    }

    /** No injected money service, lookup, config promotion or success branch. */
    public function handle(): never
    {
        throw new LegacyChunkedSettlementUnavailable;
    }

    /** Diagnostics intentionally omit plan ids, batch ids and fingerprints. */
    public function tags(): array
    {
        return [
            'legacy-chunked-execution-disabled',
            'draw:'.$this->drawId,
            'chunk-index:'.$this->chunkIndex,
        ];
    }
}
