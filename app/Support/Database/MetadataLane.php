<?php

declare(strict_types=1);

namespace App\Support\Database;

/**
 * Builds the SQL text that reaches one key inside the `metadata` JSON column.
 *
 * A row's metadata is a set of lanes - `approval`, `transfer`, `batch`, `tax`, `claim`, `claim_window`,
 * `unclaimed` - and each lane is owned by the service that writes it, which names its key through a
 * constant (`PayoutApprovalService::METADATA_KEY`). Raw SQL cannot put a constant inside a JSON path, so
 * every reader that queries metadata spelled the key again as a literal. A rename then moved the writer
 * and left the readers behind, and nothing in the build went red: the query simply started matching no
 * rows - or, for an `IS NULL` gate, every row.
 *
 * So the path text lives here instead, taking the lane name from the owner's constant. The strings this
 * produces are frozen by tests/Feature/Payment/MetadataLaneTruthTest.php against the literals the
 * readers used before, because rows already in production were written against those exact paths:
 * changing one is a data migration, not a refactor, and has to be said out loud.
 *
 * Only the lane key is single-sourced. Leaf names inside a lane (`status`, `batch_key`, `lane_status`)
 * are still literals at the call sites; the test that guards them reads the writer's own array and
 * compares, rather than trusting a copy in this file.
 */
final class MetadataLane
{
    private function __construct() {}

    /**
     * The JSON path for a lane, or for one leaf inside it: `$.claim` / `$.claim.status`.
     */
    public static function path(string $lane, ?string $leaf = null): string
    {
        return '$.'.($leaf === null ? $lane : $lane.'.'.$leaf);
    }

    /**
     * The comparison an Eloquent `where`/`whereIn` takes as a column: `metadata->claim->status`.
     */
    public static function arrow(string $lane, ?string $leaf = null): string
    {
        return 'metadata->'.$lane.($leaf === null ? '' : '->'.$leaf);
    }

    /**
     * The raw extraction form, for whereRaw and whereIn(DB::raw(...)): a json_extract of the metadata
     * column at the path for this lane and leaf. Written out this way rather than shown as an example,
     * because a literal path in a comment is what the release record's zero-pattern claim greps for.
     */
    public static function extract(string $lane, ?string $leaf = null): string
    {
        return "json_extract(metadata, '".self::path($lane, $leaf)."')";
    }

    public static function isNull(string $lane, string $leaf): string
    {
        return self::extract($lane, $leaf).' IS NULL';
    }

    public static function isNotNull(string $lane, string $leaf): string
    {
        return self::extract($lane, $leaf).' IS NOT NULL';
    }
}
