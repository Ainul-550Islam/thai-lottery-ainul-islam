<?php

declare(strict_types=1);

namespace App\Models\Support;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The published numbers of one result version (extracted in PROMPT 8).
 *
 * EVERY VALUE IS A STRING, ON PURPOSE, ON EVERY LAYER
 * ---------------------------------------------------------------------------
 * The columns are CHAR(n). The casts force 'string' so a driver that helpfully
 * hands back a PHP int for an all-numeric value cannot turn '001234' into 1234
 * on the way out. The accessors then pad defensively: if a value ever arrived
 * through a path that lost a zero, padded output restores the documented width
 * instead of printing a shorter string that reads as a different number.
 *
 * No accessor, mutator, scope or helper here performs arithmetic on a result
 * value. Comparison is string comparison.
 *
 * MISSING IS NULL, NEVER ZERO
 * ---------------------------------------------------------------------------
 * When a draw publishes nothing, every value column is NULL and result_status
 * is 'unavailable'. The accessors return null and the views render the
 * translated "not published" wording. Nothing in this class can produce
 * '000000', '000' or '00' from an absent value.
 *
 * IMMUTABLE ONCE ITS VERSION IS PUBLISHED
 * ---------------------------------------------------------------------------
 * A published row is never UPDATEd. The boot guard below refuses to save
 * changes to a current row whose draw is publicly live; a correction goes
 * through the lane's import service, which writes a NEW version and a NEW row.
 * The guard is a backstop, not the mechanism: the mechanism is that nothing
 * calls update() on these tables.
 *
 * WHY IT IS SHARED. Weekly and Bingo/Mega both publish a six, a three and a
 * two. Only the COLUMN NAMES differ, so the widths and names arrive from
 * valueColumns() and every rule above is written once.
 *
 * @property int $id
 * @property int $draw_id
 * @property int $result_version_id
 * @property string $result_status
 * @property bool $is_current
 */
abstract class AbstractLotteryResult extends Model
{
    use HasFactory;

    /**
     * Ordered map of value column => exact documented width.
     *
     * Ordered because it drives the public projection, and a lane's numbers
     * have a reading order.
     *
     * @return array<string, int>
     */
    abstract public static function valueColumns(): array;

    /**
     * Concrete draw model class for this lane.
     *
     * @return class-string<AbstractLotteryDraw>
     */
    abstract protected static function drawClass(): string;

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
        $casts = [
            'result_status' => 'string',
            'is_current' => 'boolean',
        ];

        foreach (array_keys(static::valueColumns()) as $column) {
            // 'string' is load-bearing: it stops a numeric-looking column from
            // round-tripping through an int and losing its leading zeros.
            $casts[$column] = 'string';
        }

        return $casts;
    }

    protected static function booted(): void
    {
        // Backstop against an accidental in-place edit of a live result.
        static::updating(function (Model $model): bool {
            if ($model->is_current !== true) {
                return true;
            }

            $draw = $model->draw;

            if ($draw instanceof AbstractLotteryDraw && $draw->isPubliclyLive()) {
                // Only the is_current flag may move, and only to false - that
                // is how a superseding version retires this row.
                $dirty = array_keys($model->getDirty());
                sort($dirty);

                return $dirty === [] || $dirty === ['is_current'] || $dirty === ['updated_at']
                    || $dirty === ['is_current', 'updated_at'];
            }

            return true;
        });
    }

    /**
     * @return BelongsTo<AbstractLotteryDraw, $this>
     */
    public function draw(): BelongsTo
    {
        return $this->belongsTo(static::drawClass(), 'draw_id');
    }

    /**
     * @return BelongsTo<AbstractLotteryResultVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(static::versionClass(), 'result_version_id');
    }

    public function isUnavailable(): bool
    {
        return $this->result_status === AbstractLotteryDraw::RESULT_UNAVAILABLE;
    }

    /**
     * One value at its documented width, zeros intact - or null when nothing
     * was published.
     */
    public function value(string $column): ?string
    {
        $widths = static::valueColumns();

        if (! array_key_exists($column, $widths)) {
            return null;
        }

        return $this->padded($this->{$column}, $widths[$column]);
    }

    /**
     * The public projection of these numbers.
     *
     * Deliberately excludes id, draw_id and result_version_id: a public page
     * has no use for an auto-increment key, and publishing one invites a
     * visitor to walk the table.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        $available = ! $this->isUnavailable();

        $projection = [
            'status' => (string) $this->result_status,
            'available' => $available,
        ];

        foreach (array_keys(static::valueColumns()) as $column) {
            $projection[$column] = $available ? $this->value($column) : null;
        }

        return $projection;
    }

    /**
     * Restore the documented width with leading zeros, or pass the absence
     * through untouched.
     *
     * str_pad with '0' is a string operation. No numeric formatting function
     * is used anywhere in this class, and an absent value is never widened
     * into a fabricated one.
     */
    protected function padded(mixed $value, int $width): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (string) $value;

        return strlen($value) >= $width ? $value : str_pad($value, $width, '0', STR_PAD_LEFT);
    }
}
