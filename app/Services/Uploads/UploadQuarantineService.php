<?php

declare(strict_types=1);

namespace App\Services\Uploads;

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
 * strict pre-scan MIME and extension validation.
 */
class UploadQuarantineService
{
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'pdf', 'webp'];
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg', 'image/png', 'application/pdf', 'image/webp'
    ];
    private const MAX_FILE_SIZE_BYTES = 10_485_760; // 10 MB

    /**
     * Stage an incoming uploaded file into private quarantine.
     */
    public function stageInQuarantine(
        UploadedFile $file,
        ?string $owningModelType = null,
        ?string $owningModelId = null
    ): UploadScanRecord {
        // 1. Extension & Size Validation
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $fileSize = $file->getSize() ?: 0;

        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new InvalidArgumentException("File extension .{$extension} is forbidden.");
        }

        if ($fileSize > self::MAX_FILE_SIZE_BYTES) {
            throw new InvalidArgumentException("File size exceeds 10MB limit.");
        }

        // 2. Real MIME Inspection (MIME magic check)
        $detectedMime = (string) $file->getMimeType();
        if (!in_array($detectedMime, self::ALLOWED_MIME_TYPES, true)) {
            throw new InvalidArgumentException("Detected MIME [{$detectedMime}] does not match allowed content types.");
        }

        $fileSha256 = hash_file('sha256', (string) $file->getRealPath()) ?: '';
        $storageKey = 'quarantine/' . date('Y/m/d') . '/' . Str::uuid()->toString() . '.' . $extension;

        // Store to private quarantine disk (never public webroot)
        $stored = Storage::disk('private')->putFileAs(
            dirname($storageKey),
            $file,
            basename($storageKey)
        );

        if (!$stored) {
            throw new RuntimeException("Failed to store file in private quarantine disk.");
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
            'scan_status' => 'pending_scan',
            'retries_count' => 0,
        ]);
    }
}
