<?php

declare(strict_types=1);

namespace App\Lottery\Schema;

use InvalidArgumentException;

/**
 * Everything a result lane's engine needs to know about that lane (PROMPT 9).
 *
 * THE PROBLEM THIS SOLVES. The shared engine in
 * App\Services\Lottery\Support\* is used by Weekly, Bingo/Mega and PCSO. Until
 * now it learned each lane's shape through a handful of small abstract methods
 * that every subclass had to remember to implement consistently - and PROMPT 8
 * found the failure mode the hard way: a column gate that still named Weekly's
 * three columns silently broke every Mega search.
 *
 * Now the engine asks ONE object, and that object is built from ONE place: the
 * lane's config file. A lane cannot half-describe itself, because a missing
 * field is a missing entry in config('<lane>.fields') rather than an
 * unimplemented method somewhere else.
 *
 * WHAT VARIES BETWEEN LANES
 * ---------------------------------------------------------------------------
 *   Weekly     6 / 3 / 2      one draw per date
 *   Mega       6 / 3 / 2      one draw per date
 *   PCSO       6 / 4 / 3 / 2  SEVERAL draws per date, each at its own time
 *
 * The draw-time flag is part of the schema rather than a separate setting
 * because it changes what a DRAW IS. In a lane without times, a date
 * identifies a draw; in PCSO it does not, and an engine that assumed it would
 * quietly overwrite the 14:00 result with the 17:00 one.
 */
final class LaneSchema
{
    /**
     * @param  string  $laneKey  config/product key, e.g. 'pcso_lottery'
     * @param  string  $canonicalVersion  fingerprint layout label, e.g. 'PCSO1'
     * @param  list<ResultFieldSchema>  $fields  in public reading order
     * @param  bool  $hasDrawTimes  true when a date alone does not identify a draw
     * @param  bool  $requiresCompleteResultSet  true when a draw publishes all
     *                                           of its fields or none of them
     */
    public function __construct(
        public readonly string $laneKey,
        public readonly string $canonicalVersion,
        public readonly array $fields,
        public readonly bool $hasDrawTimes,
        public readonly bool $requiresCompleteResultSet = true,
    ) {
        if ($fields === []) {
            throw new InvalidArgumentException('A lane schema must declare at least one result field.');
        }
    }

    /**
     * How many fields this lane publishes.
     */
    public function fieldCount(): int
    {
        return count($this->fields);
    }

    /**
     * Storage column names, in public reading order.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        return array_map(static fn (ResultFieldSchema $f): string => $f->column, $this->fields);
    }

    /**
     * Exact width per column.
     *
     * @return array<string, int>
     */
    public function widths(): array
    {
        $widths = [];

        foreach ($this->fields as $field) {
            $widths[$field->column] = $field->width;
        }

        return $widths;
    }

    public function field(string $column): ?ResultFieldSchema
    {
        foreach ($this->fields as $field) {
            if ($field->column === $column) {
                return $field;
            }
        }

        return null;
    }

    public function fieldForSearchType(string $type): ?ResultFieldSchema
    {
        foreach ($this->fields as $field) {
            if ($field->searchType === $type) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The ONLY column names this lane's search may ever touch.
     *
     * The search engine checks a config-supplied column against this list, so a
     * tampered or mistaken type_map cannot introduce a column name of its own.
     *
     * @return list<string>
     */
    public function searchableColumns(): array
    {
        $columns = [];

        foreach ($this->fields as $field) {
            if ($field->isSearchable()) {
                $columns[] = $field->column;
            }
        }

        return $columns;
    }

    /**
     * Widths a public search may ask for, smallest first.
     *
     * @return list<int>
     */
    public function searchableWidths(): array
    {
        $widths = [];

        foreach ($this->fields as $field) {
            if ($field->isSearchable()) {
                $widths[] = $field->width;
            }
        }

        $widths = array_values(array_unique($widths));
        sort($widths);

        return $widths;
    }

    /**
     * Deterministic ordering for "newest first".
     *
     * draw_reference is the final tiebreaker rather than id: id is an insertion
     * order, and the order results were IMPORTED has nothing to do with the
     * order they were DRAWN. Two rows inserted in either order must present
     * identically.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function newestFirstOrder(): array
    {
        $order = [['draw_date', 'desc']];

        if ($this->hasDrawTimes) {
            $order[] = ['draw_time_local', 'desc'];
        }

        $order[] = ['draw_reference', 'desc'];

        return $order;
    }
}
