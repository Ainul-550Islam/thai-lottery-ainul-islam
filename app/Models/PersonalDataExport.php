<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PersonalDataExportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A built personal-data archive.
 *
 * THE THREE THINGS A CALLER MAY ASK, AND WHAT THEY MEAN
 *   isReady()          the file exists and has been released to the requester
 *   isExpired()        expires_at has passed (or the row was retired)
 *   canBeDownloaded()  ready AND unexpired AND under max_downloads
 *
 * Keeping them here means the signed-URL endpoint and the Filament screen
 * cannot disagree about whether a link should work.
 *
 * THE HASH IS CHECKED AT DELIVERY, NOT TRUSTED. sha256 is the digest of the
 * bytes that were written; the download controller re-reads the file and
 * compares before streaming, so a truncated archive is refused rather than
 * handed to the person as if it were complete.
 *
 * @property int $id
 * @property int $request_id
 * @property int|null $user_id
 * @property string $disk
 * @property string $path
 * @property string $sha256
 * @property int $size_bytes
 * @property string $status
 * @property Carbon|null $released_at
 * @property Carbon $expires_at
 * @property int $download_count
 * @property int $max_downloads
 * @property Carbon|null $last_downloaded_at
 */
class PersonalDataExport extends Model
{
    /** @use HasFactory<PersonalDataExportFactory> */
    use HasFactory;

    public const STATUS_BUILDING = 'building';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'request_id',
        'user_id',
        'disk',
        'path',
        'sha256',
        'size_bytes',
        'status',
        'released_at',
        'expires_at',
        'download_count',
        'max_downloads',
        'last_downloaded_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'path',
        'sha256',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'download_count' => 'integer',
            'max_downloads' => 'integer',
            'released_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_downloaded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DataSubjectRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(DataSubjectRequest::class, 'request_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isBuilding(): bool
    {
        return $this->status === self::STATUS_BUILDING;
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function isReleased(): bool
    {
        return $this->released_at instanceof Carbon;
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED
            || ($this->expires_at instanceof Carbon && $this->expires_at->isPast());
    }

    public function hasDownloadsLeft(): bool
    {
        return (int) $this->download_count < max(1, (int) $this->max_downloads);
    }

    /**
     * The single answer both the endpoint and the panel use.
     */
    public function canBeDownloaded(): bool
    {
        return $this->isReady() && ! $this->isExpired() && $this->hasDownloadsLeft();
    }

    /**
     * A human-readable size for the operator screen.
     */
    public function humanSize(): string
    {
        $bytes = max(0, (int) $this->size_bytes);

        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024) {
                return $bytes.' '.$unit;
            }

            $bytes = intdiv($bytes, 1024);
        }

        return $bytes.' TB';
    }
}
