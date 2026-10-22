<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\QueueName;
use App\Services\Uploads\UploadScanService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ScanQuarantinedUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public readonly string $scanRecordId
    ) {
        $this->onQueue(QueueName::Default->value);
    }

    public function handle(UploadScanService $scanService): void
    {
        try {
            $scanService->scanRecord($this->scanRecordId);
        } catch (Throwable $e) {
            Log::error("Failed to execute quarantine malware scan for record [{$this->scanRecordId}]: ".$e->getMessage(), [
                'exception' => $e,
            ]);
            throw $e;
        }
    }
}
