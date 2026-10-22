<?php

declare(strict_types=1);

namespace App\Services\Concerns;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/*
 * B6.2 — safety helpers for queries that compress many rows into one string
 * with GROUP_CONCAT.
 *
 * WHY THIS EXISTS
 *
 * Reconciliation compresses ledger rows with GROUP_CONCAT(amount) instead of
 * SQL SUM() so that money stays a decimal string and addition happens in
 * bcmath, which is exact on every driver. SQLite has no DECIMAL type, so
 * SUM() there would round through a float — that trade is deliberate.
 *
 * The cost of that trade is that MySQL caps the result at
 * `group_concat_max_len`, which defaults to 1024 bytes, and when the cap is
 * hit MySQL truncates the string and raises nothing: no error, no warning,
 * no SQL note. A ledger with more than roughly sixty 16-character amounts
 * per group therefore produces a SHORT list, and every total derived from it
 * is quietly wrong.
 *
 * In this codebase that is worse than a normal wrong number, because the
 * affected call site is the double-entry check. A truncated list makes a
 * perfectly balanced financial transaction look unbalanced and raises a
 * Critical discrepancy against it — the reconciliation report accuses the
 * ledger of an error that does not exist.
 *
 * Two defences, because either alone can still fail:
 *
 *   1. Raise the cap for the session on MySQL/MariaDB, which moves the
 *      ceiling far out of reach.
 *   2. Cross-check the number of values received against COUNT(*) from the
 *      same query. If they disagree, the list was truncated — refuse to do
 *      arithmetic on it rather than returning a confident wrong answer.
 */
trait GuardsGroupConcat
{
    /**
     * 1 MiB. Sized well past any realistic per-group row count for these
     * queries; a group that still exceeds it is a genuine anomaly and should
     * fail loudly rather than be silently clipped.
     */
    private const GROUP_CONCAT_MAX_LEN = 1048576;

    /**
     * Raises the session cap before a GROUP_CONCAT query runs.
     *
     * A no-op on drivers that do not impose the limit (SQLite, PostgreSQL,
     * which does not have GROUP_CONCAT at all).
     */
    private function raiseGroupConcatLimit(): void
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        /*
         * Not a binding candidate: the value is this class's own constant, so
         * it is interpolated directly. Never pass user input through here.
         */
        DB::statement('SET SESSION group_concat_max_len = '.self::GROUP_CONCAT_MAX_LEN);
    }

    /**
     * Number of values in a comma-separated GROUP_CONCAT result. An empty or
     * NULL result (a group whose CASE matched nothing) counts as zero.
     */
    private function countCsvItems(?string $csv): int
    {
        if ($csv === null || $csv === '') {
            return 0;
        }

        return substr_count($csv, ',') + 1;
    }

    /**
     * Refuses to proceed when a compressed list holds fewer values than the
     * query's own COUNT(*).
     */
    private function assertCompleteList(?string $csv, int $expectedCount, string $context): void
    {
        $received = $this->countCsvItems($csv);

        if ($received === $expectedCount) {
            return;
        }

        throw new RuntimeException(sprintf(
            'GROUP_CONCAT was truncated while %s: the query counted %d row(s) but only %d value(s) came back. '
            .'Every total derived from this list would be wrong, so reconciliation is stopping instead of reporting it. '
            .'Raise group_concat_max_len beyond the current session value of %d bytes, or narrow the period being reconciled.',
            $context,
            $expectedCount,
            $received,
            self::GROUP_CONCAT_MAX_LEN,
        ));
    }

    /**
     * Compile driver-native comma-aggregation SQL (`STRING_AGG` on PostgreSQL,
     * `GROUP_CONCAT` on MySQL/MariaDB/SQLite) for exact BCMath decimal summation
     * without float rounding or MySQL driver lock-in (Audit §F8).
     */
    private function groupConcatSql(string $expression): string
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return "STRING_AGG(({$expression})::text, ',')";
        }

        return "GROUP_CONCAT({$expression})";
    }

    /**
     * Returns the list after proving it is complete, so it can be handed
     * straight to a bcmath sum.
     */
    private function completeList(?string $csv, int $expectedCount, string $context): string
    {
        $this->assertCompleteList($csv, $expectedCount, $context);

        return (string) $csv;
    }
}
