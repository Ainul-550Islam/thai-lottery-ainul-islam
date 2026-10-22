<?php

declare(strict_types=1);

namespace App\Services\Uploads;

use App\Enums\MalwareScanStatus;
use App\Models\UploadScanRecord;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Class UploadQuarantineService
 *
 * Stores raw uploads in private encrypted quarantine storage and enforces
 * strict pre-scan MIME and extension validation where required.
 */
class UploadQuarantineService
{
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'pdf', 'webp'];

    private const ALLOWED_MIME_TYPES = [
        'image/jpeg', 'image/png', 'application/pdf', 'image/webp',
    ];

    private const MAX_FILE_SIZE_BYTES = 10_485_760; // 10 MB

    /**
     * Stage any untrusted uploaded file directly into isolated quarantine storage
     * for mandatory anti-malware inspection before release.
     */
    public function quarantineUploadedFile(
        UploadedFile $file,
        string $owningUserId,
        string $purpose = 'GENERAL',
    ): UploadScanRecord {
        $extension = strtolower((string) $file->getClientOriginalExtension()) ?: 'bin';
        $fileSize = $file->getSize() ?: 0;
        if ($fileSize > self::MAX_FILE_SIZE_BYTES) {
            throw new InvalidArgumentException('File size exceeds 10MB limit.');
        }

        $detectedMime = (string) ($file->getMimeType() ?: $file->getClientMimeType() ?: 'application/octet-stream');
        $realPath = (string) $file->getRealPath();
        $fileSha256 = ($realPath !== '' && is_file($realPath)) ? (hash_file('sha256', $realPath) ?: '') : '';
        $storageKey = 'quarantine/'.date('Y/m/d').'/'.Str::uuid()->toString().'.'.$extension;

        $disk = Storage::disk('quarantine');
        $stored = $disk->putFileAs(dirname($storageKey), $file, basename($storageKey));
        if (! $stored) {
            throw new RuntimeException('Failed to store file in quarantine disk.');
        }

        return UploadScanRecord::create([
            'id' => Str::uuid()->toString(),
            'private_storage_key' => $storageKey,
            'owning_model_type' => $purpose,
            'owning_model_id' => $owningUserId,
            'original_filename' => $file->getClientOriginalName(),
            'file_mime_type' => $detectedMime,
            'file_size_bytes' => $fileSize,
            'file_sha256' => $fileSha256,
            'scan_status' => MalwareScanStatus::PENDING,
            'retries_count' => 0,
        ]);
    }

    /**
     * Open a read stream for a quarantined object across either 'quarantine' or 'private' disk.
     *
     * @return resource
     */
    public function readQuarantinedStream(string $storageKey)
    {
        foreach (['quarantine', 'private'] as $diskName) {
            if (Storage::disk($diskName)->exists($storageKey)) {
                $stream = Storage::disk($diskName)->readStream($storageKey);
                if (is_resource($stream)) {
                    return $stream;
                }
            }
        }

        throw new RuntimeException("Quarantined file [{$storageKey}] could not be opened.");
    }

    /**
     * Stage an incoming uploaded file into private quarantine with strict allowlist.
     */
    public function stageInQuarantine(
        UploadedFile $file,
        ?string $owningModelType = null,
        ?string $owningModelId = null,
    ): UploadScanRecord {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $fileSize = $file->getSize() ?: 0;

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new InvalidArgumentException("File extension .{$extension} is forbidden.");
        }

        if ($fileSize > self::MAX_FILE_SIZE_BYTES) {
            throw new InvalidArgumentException('File size exceeds 10MB limit.');
        }

        $detectedMime = (string) $file->getMimeType();
        if (! in_array($detectedMime, self::ALLOWED_MIME_TYPES, true)) {
            throw new InvalidArgumentException("Detected MIME [{$detectedMime}] does not match allowed content types.");
        }

        $fileSha256 = hash_file('sha256', (string) $file->getRealPath()) ?: '';
        $storageKey = 'quarantine/'.date('Y/m/d').'/'.Str::uuid()->toString().'.'.$extension;

        $stored = Storage::disk('private')->putFileAs(
            dirname($storageKey),
            $file,
            basename($storageKey),
        );

        if (! $stored) {
            throw new RuntimeException('Failed to store file in private quarantine disk.');
        }

        return UploadScanRecord::create([
            'id' => Str::uuid()->toString(),
            'private_storage_key' => $storageKey,
            'owning_model_type' => $owningModelType,
            'owning_model_id' => $owningModelId,
            'original_filename' => $file->getClientOriginalName(),
            'file_mime_type' => $detectedMime,
            'file_size_bytes' => $fileSize,
            'file_sha256' => $fileSha256,
            'scan_status' => MalwareScanStatus::PENDING,
            'retries_count' => 0,
        ]);
    }
}
