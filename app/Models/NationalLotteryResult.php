<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The numbers of one National Lottery result version (PROMPT 5).
 *
 * EVERY VALUE IS A STRING, ON PURPOSE AND ON EVERY LAYER
 * ---------------------------------------------------------------------------
 * The columns are CHAR(6) / CHAR(3) / CHAR(2). The casts below force 'string'
 * so that a driver which helpfully hands back a PHP int (some do, for
 * all-numeric values) cannot turn '004615' into 4615 on the way out. The
 * accessors then pad defensively: if a value ever arrived through a path that
 * lost a zero, padded output restores the documented width instead of printing
 * a shorter string that looks like a different number.
 *
 * There is no accessor, mutator, scope or helper on this model that performs
 * arithmetic on a result value. Comparison is string comparison.
 *
 * IMMUTABLE ONCE ITS VERSION IS PUBLISHED
 * A published row is never UPDATEd. The model boots a guard that refuses to
 * save changes to a row flagged current whose draw is publicly live; a
 * correction must go through NationalLotteryImportService, which writes a NEW
 * version and a NEW row. The guard is a backstop, not the mechanism: the
 * mechanism is that nothing calls update() on this table.
 *
 * @property int $id
 * @property int $draw_id
 * @property int $result_version_id
 * @property string $first_prize
 * @property string|null $three_up
 * @property string|null $two_up
 * @property string|null $two_down
 * @property array<int, string>|null $three_front
 * @property array<int, string>|null $three_after
 * @property int $three_front_count
 * @property int $three_after_count
 * @property bool $is_current
 */
class NationalLotteryResult extends Model
{
    use HasFactory;

    protected $table = 'national_lottery_results';

    protected $fillable = [
        'draw_id',
        'result_version_id',
        'first_prize',
        'three_up',
        'two_up',
        'two_down',
        'three_front',
        'three_after',
        'three_front_count',
        'three_after_count',
        'is_current',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // 'string' is load-bearing: it stops a numeric-looking column from
            // round-tripping through an int and losing its leading zeros.
            'first_prize' => 'string',
            'three_up' => 'string',
            'two_up' => 'string',
            'two_down' => 'string',
            'three_front' => 'array',
            'three_after' => 'array',
            'three_front_count' => 'integer',
            'three_after_count' => 'integer',
            'is_current' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Backstop against an accidental in-place edit of a live result.
        static::updating(function (self $model): bool {
            if ($model->is_current !== true) {
                return true;
            }

            $draw = $model->draw;

            if ($draw instanceof NationalLotteryDraw && $draw->isPubliclyLive()) {
                // Only the is_current flag may move, and only to false - that
                // is how a superseding version retires this row.
                $dirty = array_keys($model->getDirty());

                return $dirty === [] || $dirty === ['is_current'] || $dirty === ['updated_at']
                    || $dirty === ['is_current', 'updated_at'] || $dirty === ['updated_at', 'is_current'];
            }

            return true;
        });
    }

    /**
     * @return BelongsTo<NationalLotteryDraw, $this>
     */
    public function draw(): BelongsTo
    {
        return $this->belongsTo(NationalLotteryDraw::class, 'draw_id');
    }

    /**
     * @return BelongsTo<NationalLotteryResultVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(NationalLotteryResultVersion::class, 'result_version_id');
    }

    /**
     * Exactly six characters, zeros intact.
     */
    public function firstPrize(): string
    {
        return $this->padded((string) $this->first_prize, 6);
    }

    public function threeUp(): ?string
    {
        return $this->three_up === null || $this->three_up === ''
            ? null
            : $this->padded((string) $this->three_up, 3);
    }

    public function twoUp(): ?string
    {
        return $this->two_up === null || $this->two_up === ''
            ? null
            : $this->padded((string) $this->two_up, 2);
    }

    public function twoDown(): ?string
    {
        return $this->two_down === null || $this->two_down === ''
            ? null
            : $this->padded((string) $this->two_down, 2);
    }

    /**
     * @return list<string>
     */
    public function threeFront(): array
    {
        return $this->paddedList($this->three_front, 3);
    }

    /**
     * @return list<string>
     */
    public function threeAfter(): array
    {
        return $this->paddedList($this->three_after, 3);
    }

    /**
     * The public projection of these numbers.
     *
     * Deliberately excludes id, draw_id and result_version_id: a public page
     * has no use for an auto-increment key, and publishing one invites a
     * visitor to walk the table.
     *
     * @return array{
     *     first_prize: string,
     *     three_up: string|null,
     *     two_up: string|null,
     *     three_front: list<string>,
     *     three_after: list<string>,
     *     two_down: string|null,
     *     three_front_count: int,
     *     three_after_count: int
     * }
     */
    public function toPublicArray(): array
    {
        $front = $this->threeFront();
        $after = $this->threeAfter();

        return [
            'first_prize' => $this->firstPrize(),
            'three_up' => $this->threeUp(),
            'two_up' => $this->twoUp(),
            'three_front' => $front,
            'three_after' => $after,
            'two_down' => $this->twoDown(),
            'three_front_count' => count($front),
            'three_after_count' => count($after),
        ];
    }

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    private function paddedList($values, int $width): array
    {
        if (! is_array($values)) {
            return [];
        }

        $out = [];

        foreach ($values as $value) {
            if (is_int($value) || is_string($value)) {
                // Order is preserved exactly as stored. Nothing sorts here.
                $out[] = $this->padded((string) $value, $width);
            }
        }

        return $out;
    }

    /**
     * Restore the documented width with leading zeros.
     *
     * STR_PAD_LEFT with '0' is a string operation. No numeric formatting
     * function is used anywhere in this class.
     */
    private function padded(string $value, int $width): string
    {
        return strlen($value) >= $width ? $value : str_pad($value, $width, '0', STR_PAD_LEFT);
    }
}
