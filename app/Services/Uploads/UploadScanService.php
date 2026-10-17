<?php

declare(strict_types=1);

namespace App\Services\Uploads;

use App\Contracts\Security\MalwareScanner;
use App\DTOs\Security\MalwareScanResult;
use App\Events\MalwareScanCompleted;
use App\Models\UploadScanRecord;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Class UploadScanService
 *
 * Orchestrates asynchronous scanning and state transitions of quarantined uploads.
 */
class UploadScanService
{
    public function __construct(
        private readonly MalwareScanner $scanner
    ) {}

    /**
     * Execute scan on a staged upload record.
     */
    public function scanRecord(string $scanRecordId): MalwareScanResult
    {
        /** @var UploadScanRecord $record */
        $record = UploadScanRecord::findOrFail($scanRecordId);

        $localPath = Storage::disk('private')->path($record->private_storage_key);

        $scanResult = $this->scanner->scanFile($localPath, $record->original_filename);

        $record->update([
            'scan_status' => $scanResult->status->value,
            'threat_name' => $scanResult->threatName,
            'scanner_version' => $scanResult->scannerEngine,
            'scan_duration_ms' => $scanResult->scanDurationMs,
            'scanned_at' => now(),
            'retries_count' => $record->retries_count + 1,
        ]);

        if ($scanResult->status->isDangerous()) {
            Log::critical("MALWARE DETECTED IN UPLOAD: Record [{$record->id}] File: {$record->original_filename} Threat: {$scanResult->threatName}");
            // Delete infected file immediately from quarantine disk
            Storage::disk('private')->delete($record->private_storage_key);
        }

        event(new MalwareScanCompleted($record->id, $record->private_storage_key, $scanResult));

        return $scanResult;
    }
}
