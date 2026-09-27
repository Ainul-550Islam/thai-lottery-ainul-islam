<?php

declare(strict_types=1);

namespace App\Lottery\Schema;

/**
 * One publishable value in a result lane (PROMPT 9).
 *
 * WHY A VALUE OBJECT. Before this existed, the facts about a single field were
 * spread across four places: the width lived in config('<lane>.fields'), the
 * search pattern lived in config('<lane>.search.type_map'), the column list
 * lived on the result model, and the shared services each re-derived what they
 * needed. Weekly and Mega happened to agree, so the duplication was invisible;
 * PCSO has SIX/FOUR/THREE/TWO and the disagreement would have surfaced as a
 * field that validates at one width and searches at another.
 *
 * A field is a STRING of an EXACT width. There is no numeric type here and no
 * arithmetic anywhere in this class, because '09' and 9 are different results
 * and a width is the only thing that distinguishes '0049' from '49'.
 */
final class ResultFieldSchema
{
    /**
     * @param  string  $column  storage column, e.g. 'four_digit'
     * @param  int  $width  exact character count, never a minimum
     * @param  string  $pattern  anchored, length-exact validation pattern
     * @param  string|null  $searchType  public search type, or null when the field is not searchable
     * @param  string  $labelKey  translation key for the public column heading
     */
    public function __construct(
        public readonly string $column,
        public readonly int $width,
        public readonly string $pattern,
        public readonly ?string $searchType,
        public readonly string $labelKey,
    ) {}

    public function isSearchable(): bool
    {
        return $this->searchType !== null;
    }

    /**
     * Does this value have exactly the documented width and shape?
     *
     * Deliberately has no "repair" counterpart. A payload of the wrong width is
     * refused by the importer; padding '1234' into '001234' would invent a
     * result nobody published.
     */
    public function accepts(string $value): bool
    {
        return strlen($value) === $this->width
            && preg_match($this->pattern, $value) === 1;
    }
}
