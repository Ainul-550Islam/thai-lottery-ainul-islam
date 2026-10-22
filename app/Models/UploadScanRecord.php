<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MalwareScanStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class UploadScanRecord extends Model
{
    use HasUuids;

    protected $table = 'upload_scan_records';

    protected $fillable = [
        'id',
        'private_storage_key',
        'owning_model_type',
        'owning_model_id',
        'original_filename',
        'file_mime_type',
        'file_size_bytes',
        'file_sha256',
        'scan_status',
        'threat_name',
        'scanner_version',
        'scan_duration_ms',
        'retries_count',
        'scanned_at',
    ];

    protected $casts = [
        'scan_status' => MalwareScanStatus::class,
        'file_size_bytes' => 'integer',
        'scan_duration_ms' => 'float',
        'retries_count' => 'integer',
        'scanned_at' => 'datetime',
    ];

    public function isClean($attributes = null): bool
    {
        if ($attributes !== null) {
            return parent::isClean(...func_get_args());
        }

        return $this->scan_status === MalwareScanStatus::CLEAN;
    }
}
