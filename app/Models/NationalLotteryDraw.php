<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DrawPublicationStatus;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One National Lottery draw (PROMPT 5).
 *
 * SEPARATE PRODUCT LANE. This is not App\Models\Draw (the operator betting
 * lane) and not a GLO model. It owns a date, a publication state, a
 * provenance state and a pointer to whichever version currently answers for
 * it. It owns no tickets, no stakes and no payouts.
 *
 * ROUTE KEY IS draw_reference, NEVER id. A public URL that exposes an
 * auto-increment primary key tells a visitor how many rows exist and lets them
 * walk the table. The reference is derived from the draw date, which is
 * already public information.
 *
 * @property int $id
 * @property string $uuid
 * @property string $draw_reference
 * @property CarbonImmutable $draw_date
 * @property int $draw_year
 * @property string $status
 * @property DrawPublicationStatus $publication_status
 * @property Carbon|null $published_at
 * @property string $source_state
 * @property int|null $current_result_version_id
 * @property array<string, mixed>|null $metadata
 */
class NationalLotteryDraw extends Model
{
    use HasFactory;

    /** Lane lifecycle values (distinct from publication status). */
    public const STATUS_AWAITING_RESULT = 'awaiting_result';

    public const STATUS_RESULT_RECORDED = 'result_recorded';

    public const STATUS_CONFLICT = 'conflict';

    protected $table = 'national_lottery_draws';

    protected $fillable = [
        'uuid',
        'draw_reference',
        'draw_date',
        'draw_year',
        'draw_status',
        'status',
        'publication_status',
        'published_at',
        'source_state',
        'current_result_version_id',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // draw_date is deliberately NOT cast to 'date'. Laravel would
            // then serialise it with the connection's datetime format, and
            // SQLite - which has no real DATE type - would store
            // '2026-09-16 00:00:00'. An exact-match lookup on '2026-09-16'
            // would miss, and the unique index on draw_date would stop
            // meaning "one draw per date". The accessor/mutator below keeps
            // the stored value exactly ten characters on every driver.
            'draw_year' => 'integer',
            'publication_status' => DrawPublicationStatus::class,
            'published_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * Business date in, exact 'Y-m-d' string stored, CarbonImmutable out.
     *
     * @return Attribute<CarbonImmutable|null, string>
     */
    protected function drawDate(): Attribute
    {
        return Attribute::make(
            get: static fn (mixed $value): ?CarbonImmutable => $value === null
                ? null
                : CarbonImmutable::parse(substr((string) $value, 0, 10))->startOfDay(),
            set: static fn (mixed $value): string => $value instanceof DateTimeInterface
                ? $value->format('Y-m-d')
                : substr(trim((string) $value), 0, 10),
        );
    }

    /**
     * Compatibility alias for older import payloads; the database keeps one
     * authoritative lifecycle column named status.
     */
    public function setDrawStatusAttribute(mixed $value): void
    {
        $this->attributes['status'] = $value instanceof \BackedEnum ? $value->value : $value;
    }

    public function getDrawStatusAttribute(): mixed
    {
        return $this->getAttribute('status');
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if ((string) $model->uuid === '') {
                $model->uuid = (string) Str::uuid();
            }

            if ($model->draw_year === null && $model->draw_date !== null) {
                $model->draw_year = (int) CarbonImmutable::parse((string) $model->draw_date)->format('Y');
            }

            if ($model->status === null && $model->draw_status !== null) {
                $model->status = $model->draw_status;
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'draw_reference';
    }

    /**
     * Build the public reference for a draw date.
     *
     * The date is formatted from its parts as STRINGS. No arithmetic and no
     * locale-dependent formatter is involved, so the reference is stable
     * regardless of the request's locale.
     */
    public static function buildReference(string $isoDate): string
    {
        return 'NL-'.str_replace('-', '', $isoDate);
    }

    /**
     * @return HasMany<NationalLotteryResultVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(NationalLotteryResultVersion::class, 'draw_id');
    }

    /**
     * @return HasMany<NationalLotteryResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(NationalLotteryResult::class, 'draw_id');
    }

    /**
     * The numbers the public may currently see for this draw.
     *
     * @return HasOne<NationalLotteryResult, $this>
     */
    public function currentResult(): HasOne
    {
        return $this->hasOne(NationalLotteryResult::class, 'draw_id')
            ->where('is_current', true);
    }

    /**
     * @return HasOne<NationalLotteryResultVersion, $this>
     */
    public function currentVersion(): HasOne
    {
        return $this->hasOne(NationalLotteryResultVersion::class, 'id', 'current_result_version_id');
    }

    public function isPubliclyLive(): bool
    {
        return $this->publication_status instanceof DrawPublicationStatus
            && $this->publication_status->isPubliclyLive();
    }
}
