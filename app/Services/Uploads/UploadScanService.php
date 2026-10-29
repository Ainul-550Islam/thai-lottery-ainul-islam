<?php

declare(strict_types=1);

namespace App\Services\Uploads;

use App\Contracts\Security\MalwareScanner;
use App\DTOs\Security\MalwareScanResult;
use App\Enums\MalwareScanStatus;
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

        if ($scanResult->status === MalwareScanStatus::CLEAN) {
            // The scan record identifies the staged upload. A clean reply for
            // different bytes must not make this record clean: consumers such
            // as slip verification later materialise the record's hash.
            $expected = (string) $record->file_sha256;
            $observed = $scanResult->fileSha256;
            if (preg_match('/\A[a-f0-9]{64}\z/', $expected) !== 1
                || preg_match('/\A[a-f0-9]{64}\z/', $observed) !== 1
                || ! hash_equals($expected, $observed)
                || (int) $record->file_size_bytes !== $scanResult->fileSizeBytes
                || ! $this->storedBytesMatch($diskUsed, (string) $record->private_storage_key, $expected, $scanResult->fileSizeBytes)) {
                $scanResult = new MalwareScanResult(
                    status: MalwareScanStatus::ERROR,
                    threatName: 'SCAN_CONTENT_MISMATCH',
                    scannerEngine: $scanResult->scannerEngine,
                    fileSha256: $observed,
                    fileSizeBytes: $scanResult->fileSizeBytes,
                    scanDurationMs: $scanResult->scanDurationMs,
                    scannedAt: $scanResult->scannedAt,
                );
            }
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

    /**
     * Refuse a clean state if the stored object changed during the scan.
     * Consumers still need to compare the digest again at the point of use;
     * this check cannot make a mutable storage backend immutable.
     */
    private function storedBytesMatch(string $disk, string $key, string $expected, int $expectedBytes): bool
    {
        $stream = null;
        try {
            $stream = Storage::disk($disk)->readStream($key);
            if (! is_resource($stream)) {
                return false;
            }
            $hash = hash_init('sha256');
            $bytes = 0;
            while (! feof($stream)) {
                $chunk = @fread($stream, 8192);
                if ($chunk === false) {
                    return false;
                }
                if ($chunk === '') {
                    if (! feof($stream)) {
                        return false;
                    }
                    break;
                }
                $bytes += strlen($chunk);
                if ($bytes > $expectedBytes) {
                    return false;
                }
                hash_update($hash, $chunk);
            }

            return $bytes === $expectedBytes && hash_equals($expected, hash_final($hash));
        } catch (\Throwable) {
            return false;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
