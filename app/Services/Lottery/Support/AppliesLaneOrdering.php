<?php

declare(strict_types=1);

namespace App\Services\Lottery\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * "Newest first" for a result lane (PROMPT 9).
 *
 * WHY THIS REPLACED `orderByDesc('draw_date')->orderByDesc('id')`.
 *
 * The id tiebreaker was wrong in two different ways and only the second one
 * was visible before PCSO existed.
 *
 * 1. id is INSERTION order. Two draws imported in either order must present
 *    identically, and they did not: re-importing history in a different
 *    sequence silently reordered equal-dated rows.
 * 2. PCSO publishes SEVERAL draws on one date - 14:00, 17:00, 21:00. Ordering
 *    by date then id would have put them in import order, so "the latest
 *    result" could have been the 14:00 draw simply because it was imported
 *    last.
 *
 * The lane schema answers what the tiebreakers are: draw_time_local when the
 * lane has draw times, then draw_reference, which is derived from the draw's
 * own identity and is therefore stable no matter when a row was written.
 */
trait AppliesLaneOrdering
{
    /**
     * @param  Builder<covariant \App\Models\Support\AbstractLotteryDraw>  $query
     */
    protected function applyNewestFirst(Builder $query): void
    {
        foreach ($this->schema()->newestFirstOrder() as [$column, $direction]) {
            $query->orderBy($column, $direction);
        }
    }
}
