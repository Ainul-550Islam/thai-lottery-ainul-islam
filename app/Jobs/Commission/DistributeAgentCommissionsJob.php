<?php

declare(strict_types=1);

namespace App\Jobs\Commission;

use App\Services\Commission\MultiTierCommissionDistributionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Class DistributeAgentCommissionsJob
 *
 * Asynchronous job to distribute multi-tier agent commissions without blocking the main entry placement thread.
 */
class DistributeAgentCommissionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $backoff = 10;
    public int $timeout = 60;

    public function __construct(
        public readonly string $lotteryEntryId
    ) {
        $this->onQueue('commissions');
    }

    public function handle(MultiTierCommissionDistributionService $commissionService): void
    {
        try {
            $commissionService->distributeForEntry($this->lotteryEntryId);
        } catch (Throwable $e) {
            Log::error("Failed to distribute agent commissions for entry [{$this->lotteryEntryId}]: " . $e->getMessage(), [
                'exception' => $e,
                'entry_id' => $this->lotteryEntryId,
            ]);
            throw $e;
        }
    }
}
