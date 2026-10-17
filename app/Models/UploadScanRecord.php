<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class UploadScanRecord extends Model
{
    use HasUuids;

    protected $table = 'upload_scan_records';

    protected $fillable = [
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
        'file_size_bytes' => 'integer',
        'scan_duration_ms' => 'float',
        'retries_count' => 'integer',
        'scanned_at' => 'datetime',
    ];

    public function isClean(): bool
    {
        return $this->scan_status === 'clean';
    }
}
