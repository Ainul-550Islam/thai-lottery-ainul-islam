<?php

declare(strict_types=1);

namespace App\Console\Commands\Security;

use App\Jobs\ScanQuarantinedUploadJob;
use App\Models\UploadScanRecord;
use Illuminate\Console\Command;

class RetryStaleUploadScansCommand extends Command
{
    protected $signature = 'security:retry-stale-scans {minutes=15 : Requeue scans pending longer than minutes}';

    protected $description = 'Requeues pending or error-state quarantined uploads without marking them clean.';

    public function handle(): int
    {
        $minutes = (int) $this->argument('minutes');
        $cutoff = now()->subMinutes($minutes);

        $staleRecords = UploadScanRecord::whereIn('scan_status', ['pending_scan', 'error'])
            ->where('created_at', '<=', $cutoff)
            ->where('retries_count', '<', 5)
            ->get();

        $count = $staleRecords->count();
        $this->info("Found {$count} stale upload records awaiting scan retry.");

        foreach ($staleRecords as $record) {
            ScanQuarantinedUploadJob::dispatch($record->id);
        }

        return self::SUCCESS;
    }
}
