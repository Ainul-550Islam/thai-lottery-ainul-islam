<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property \App\Models\UploadScanRecord $resource
 */
class UploadScanRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'original_filename' => $this->resource->original_filename,
            'file_mime_type' => $this->resource->file_mime_type,
            'file_size_bytes' => $this->resource->file_size_bytes,
            'file_sha256' => $this->resource->file_sha256,
            'scan_status' => $this->resource->scan_status,
            'threat_name' => $this->resource->threat_name,
            'scanner_version' => $this->resource->scanner_version,
            'scan_duration_ms' => $this->resource->scan_duration_ms,
            'retries_count' => $this->resource->retries_count,
            'scanned_at' => $this->resource->scanned_at?->toIso8601String(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
