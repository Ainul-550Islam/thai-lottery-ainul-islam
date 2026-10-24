<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Portable SQL JSON text extraction across PostgreSQL, MySQL/MariaDB, and SQLite (Audit §E7).
 *
 * Replaces SQLite-only `json_extract(col, '$.a.b')` raw predicates which fail
 * at runtime on PostgreSQL (`ERROR: function json_extract(jsonb, unknown) does
 * not exist`) and return quoted JSON strings on MySQL (`"approved"` instead of
 * `approved`).
 */
final class DatabaseJson
{
    private const IDENTIFIER_PATTERN = '/\A[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)?\z/';

    private const SEGMENT_PATTERN = '/\A[a-zA-Z0-9_]+\z/';

    /**
     * Compile a driver-native SQL expression that extracts a scalar text value
     * at `$dotPath` (e.g. `'approval.status'` or `'action'`) from `$column`.
     */
    public static function extractText(string $column, string $dotPath, ?string $driver = null): string
    {
        if (preg_match(self::IDENTIFIER_PATTERN, $column) !== 1) {
            throw new InvalidArgumentException("Unsafe SQL column identifier [{$column}].");
        }

        $segments = self::parsePath($dotPath);
        $resolvedDriver = strtolower($driver ?? DB::connection()->getDriverName());

        return match ($resolvedDriver) {
            'pgsql' => sprintf(
                '(%s::jsonb #>> \'{%s}\')',
                $column,
                implode(',', $segments)
            ),
            'mysql', 'mariadb' => sprintf(
                "CASE WHEN JSON_TYPE(JSON_EXTRACT(%s, '$.%s')) = 'NULL' THEN NULL ELSE JSON_UNQUOTE(JSON_EXTRACT(%s, '$.%s')) END",
                $column,
                implode('.', $segments),
                $column,
                implode('.', $segments)
            ),
            default => sprintf(
                "json_extract(%s, '$.%s')",
                $column,
                implode('.', $segments)
            ),
        };
    }

    /**
     * Wrap `extractText()` in a Laravel query `Expression` for `whereIn(...)`.
     */
    public static function rawText(string $column, string $dotPath, ?string $driver = null): Expression
    {
        return DB::raw(self::extractText($column, $dotPath, $driver));
    }

    /**
     * Apply a parameterized equality predicate on a JSON text path across
     * PostgreSQL (`#>>`), MySQL/MariaDB (`JSON_UNQUOTE(JSON_EXTRACT(...))`),
     * and SQLite (`json_extract(...)`).
     *
     * @template TBuilder of \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>|\Illuminate\Database\Query\Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function whereEquals(
        Builder|\Illuminate\Database\Query\Builder $query,
        string $column,
        string $dotPath,
        string|int|float|bool $value,
        ?string $driver = null,
    ): Builder|\Illuminate\Database\Query\Builder {
        $expr = self::extractText($column, $dotPath, $driver);

        return $query->whereRaw("{$expr} = ?", [$value]);
    }

    /**
     * @return non-empty-list<string>
     */
    private static function parsePath(string $dotPath): array
    {
        $normalized = ltrim(trim($dotPath), '$.');
        if ($normalized === '') {
            throw new InvalidArgumentException('JSON extraction path must not be empty.');
        }

        $segments = explode('.', $normalized);
        foreach ($segments as $segment) {
            if (preg_match(self::SEGMENT_PATTERN, $segment) !== 1) {
                throw new InvalidArgumentException("Unsafe JSON path segment [{$segment}].");
            }
        }

        return $segments;
    }
}
