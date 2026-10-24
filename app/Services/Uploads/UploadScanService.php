<?php

declare(strict_types=1);

namespace App\Services\Uploads;

use App\Contracts\Security\MalwareScanner;
use App\DTOs\Security\MalwareScanResult;
use App\Events\MalwareScanCompleted;
use App\Models\UploadScanRecord;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Class UploadScanService
 *
 * Orchestrates asynchronous scanning and state transitions of quarantined uploads.
 */
class UploadScanService
{
    private readonly UploadQuarantineService $quarantineService;

    private readonly MalwareScanner $scanner;

    public function __construct(
        mixed $quarantineOrScanner = null,
        ?MalwareScanner $scanner = null,
    ) {
        $quarantineOrScanner ??= app(MalwareScanner::class);
        if ($quarantineOrScanner instanceof UploadQuarantineService) {
            if (! $scanner instanceof MalwareScanner) {
                throw new InvalidArgumentException('MalwareScanner instance is required.');
            }
            $this->quarantineService = $quarantineOrScanner;
            $this->scanner = $scanner;
        } else {
            $this->quarantineService = app(UploadQuarantineService::class);
            $this->scanner = $quarantineOrScanner;
        }
    }

    /**
     * Execute scan on a staged upload record.
     */
    public function scanRecord(string $scanRecordId): MalwareScanResult
    {
        /** @var UploadScanRecord $record */
        $record = UploadScanRecord::findOrFail($scanRecordId);

        if (Storage::disk('quarantine')->exists($record->private_storage_key)) {
            $stream = $this->quarantineService->readQuarantinedStream($record->private_storage_key);
            try {
                $scanResult = $this->scanner->scanStream($stream, $record->original_filename);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            $diskUsed = 'quarantine';
        } else {
            $localPath = Storage::disk('private')->path($record->private_storage_key);
            $scanResult = $this->scanner->scanFile($localPath, $record->original_filename);
            $diskUsed = 'private';
        }

        $record->update([
            'scan_status' => $scanResult->status->value,
            'threat_name' => $scanResult->threatName,
            'scanner_version' => $scanResult->scannerEngine,
            'scan_duration_ms' => $scanResult->scanDurationMs,
            'scanned_at' => now(),
            'retries_count' => $record->retries_count + 1,
        ]);

        if ($scanResult->status->isDangerous()) {
            $safeName = preg_replace('/[\r\n\t\x00-\x1F\x7F]+/', ' ', (string) $record->original_filename) ?? 'unknown';
            $safeThreat = preg_replace('/[\r\n\t\x00-\x1F\x7F]+/', ' ', (string) $scanResult->threatName) ?? 'unknown';
            Log::critical("MALWARE DETECTED IN UPLOAD: Record [{$record->id}] File: {$safeName} Threat: {$safeThreat}");
            Storage::disk($diskUsed)->delete($record->private_storage_key);
        }

        event(new MalwareScanCompleted($record->id, $record->private_storage_key, $scanResult));

        return $scanResult;
    }
}
