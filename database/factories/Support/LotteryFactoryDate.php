<?php

declare(strict_types=1);

namespace Database\Factories\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Schema-valid synthetic dates, not live draw scheduling or concurrent-writer reservation authority. */
final class LotteryFactoryDate
{
    /** @param class-string<Model> $model */
    public static function previous(string $model, string $timezone, int $start): Carbon
    {
        if ($start < 1 || $start > 900) {
            throw new \InvalidArgumentException('Synthetic factory date offset must be between1 and900.');
        }
        $anchor = now($timezone)->startOfDay();
        $instance = new $model;
        // Include every physical row (including soft-deleted rows), matching the real unique date constraint.
        $occupied = DB::connection($instance->getConnectionName())->table($instance->getTable())
            ->whereBetween('draw_date', [$anchor->copy()->subDays(900)->toDateString(), $anchor->copy()->subDay()->toDateString()])
            ->pluck('draw_date')->mapWithKeys(fn ($date) => [substr((string) $date, 0, 10) => true])->all();
        for ($i = 0; $i < 900; $i++) {
            $date = $anchor->copy()->subDays((($start - 1 + $i) % 900) + 1);
            if (! isset($occupied[$date->toDateString()])) {
                return $date;
            }
        }
        throw new \OverflowException('All900 historical dates are occupied; reset the disposable test database or use an explicit non-factory fixture.');
    }
}
