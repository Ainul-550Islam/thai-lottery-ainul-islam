<?php

declare(strict_types=1);

namespace App\Models\Support;

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
 * One draw of a public result lane (extracted in PROMPT 8).
 *
 * WHY THIS EXISTS. The Weekly Lottery lane and the Bingo/Mega lane publish the
 * same SHAPE of record: a date, a result status, a publication state, a
 * provenance state, and a pointer to whichever version currently answers for
 * the draw. Everything below was written once for Weekly and would otherwise
 * have been pasted a second time with three words changed. A second copy is a
 * second place for a leading-zero bug or a date-format bug to survive a fix.
 *
 * WHAT STAYS IN THE SUBCLASS. Only the facts that genuinely differ: the table,
 * the reference prefix, and which concrete result/version classes the draw
 * relates to. Everything a subclass must supply is an abstract method, so a new
 * lane cannot half-implement one and silently inherit Weekly's table.
 *
 * THIS IS NOT App\Models\Draw. That model belongs to the operator betting
 * engine and owns tickets, stakes and payouts. A lottery-lane draw owns none
 * of those: it is a published record, not a market.
 *
 * ROUTE KEY IS draw_reference, NEVER id. A public URL carrying an
 * auto-increment key tells a visitor how many rows exist and invites them to
 * walk the table. The reference is derived from the draw date, which is
 * already public.
 *
 * @property int $id
 * @property string $uuid
 * @property string $draw_reference
 * @property CarbonImmutable $draw_date
 * @property int $draw_year
 * @property string $draw_timezone
 * @property string $result_status
 * @property DrawPublicationStatus $publication_status
 * @property Carbon|null $published_at
 * @property string $source_state
 * @property int|null $current_result_version_id
 * @property array<string, mixed>|null $metadata
 */
abstract class AbstractLotteryDraw extends Model
{
    use HasFactory;

    /** The draw published a full set of numbers. */
    public const RESULT_PUBLISHED = 'published';

    /**
     * The draw happened and no numbers are available for it.
     *
     * This is a real, displayable state - not an error, and emphatically not a
     * placeholder for zeros. A lane that renders an unavailable draw as
     * "000000" has invented a result.
     */
    public const RESULT_UNAVAILABLE = 'unavailable';

    protected $fillable = [
        'uuid',
        'draw_reference',
        'draw_date',
        'draw_year',
        'draw_timezone',
        'result_status',
        'publication_status',
        'published_at',
        'source_state',
        'current_result_version_id',
        'metadata',
    ];

    /**
     * The prefix a public draw reference carries, e.g. 'WK' or 'MG'.
     *
     * Kept distinct per lane so a reference can never be mistaken for one
     * belonging to another lane, in a URL, a log line or an import file.
     */
    abstract protected static function referencePrefix(): string;

    /**
     * Concrete result model class for this lane.
     *
     * @return class-string<AbstractLotteryResult>
     */
    abstract protected static function resultClass(): string;

    /**
     * Concrete version model class for this lane.
     *
     * @return class-string<AbstractLotteryResultVersion>
     */
    abstract protected static function versionClass(): string;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // draw_date is deliberately NOT cast to 'date'. Laravel would then
            // serialise it with the connection's datetime format, and SQLite -
            // which has no real DATE type - would store
            // '2026-09-18 00:00:00'. An exact-match lookup on '2026-09-18'
            // would miss, and the unique index on draw_date would stop meaning
            // "one draw per date". The accessor/mutator below keeps the stored
            // value exactly ten characters on every driver.
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

    protected static function booted(): void
    {
        static::creating(function (Model $model): void {
            if ((string) $model->uuid === '') {
                $model->uuid = (string) Str::uuid();
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
     * The date is reformatted from its CHARACTERS. No arithmetic and no
     * locale-dependent formatter is involved, so the reference is stable
     * regardless of the request's locale and a leading zero in a month or day
     * cannot be dropped.
     */
    public static function buildReference(string $isoDate, ?string $localTime = null): string
    {
        $reference = static::referencePrefix().'-'.str_replace('-', '', $isoDate);

        // A lane with several draws per date must carry the time in the
        // reference, or the three draws on one day would share one public URL
        // and the second import would look like a correction of the first.
        if ($localTime !== null && $localTime !== '') {
            $reference .= '-'.str_replace(':', '', $localTime);
        }

        return $reference;
    }

    /**
     * @return HasMany<AbstractLotteryResultVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(static::versionClass(), 'draw_id');
    }

    /**
     * @return HasMany<AbstractLotteryResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(static::resultClass(), 'draw_id');
    }

    /**
     * The numbers the public may currently see for this draw.
     *
     * @return HasOne<AbstractLotteryResult, $this>
     */
    public function currentResult(): HasOne
    {
        return $this->hasOne(static::resultClass(), 'draw_id')
            ->where('is_current', true);
    }

    /**
     * @return HasOne<AbstractLotteryResultVersion, $this>
     */
    public function currentVersion(): HasOne
    {
        return $this->hasOne(static::versionClass(), 'id', 'current_result_version_id');
    }

    public function isPubliclyLive(): bool
    {
        return $this->publication_status instanceof DrawPublicationStatus
            && $this->publication_status->isPubliclyLive();
    }

    public function hasPublishedNumbers(): bool
    {
        return $this->result_status === self::RESULT_PUBLISHED;
    }
}
