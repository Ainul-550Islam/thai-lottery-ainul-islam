<?php

declare(strict_types=1);

namespace App\Jobs\Settlement;

use App\Enums\QueueName;
use App\Exceptions\LegacyLotteryEntryException;
use App\Services\Broadcast\RealtimeSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Keep the serialized job shape so previously queued jobs fail closed. */
class ChunkedSettlementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(
        public readonly int|string $drawId,
        public readonly int $chunkIndex,
        public readonly int|string $drawResultId
    ) {
        $this->onQueue(QueueName::FinancialCritical->value);
    }

    public function handle(RealtimeSyncService $syncService): void
    {
        throw LegacyLotteryEntryException::unavailable('chunk settlement');
    }
}
