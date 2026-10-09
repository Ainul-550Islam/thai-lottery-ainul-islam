<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\QueryException;

/**
 * A write conflict between two concurrent transactions, classified and repaired.
 *
 * WHY THIS EXISTS
 * Every money path in this application is built the same way: open a transaction,
 * lock the row, decide, write. That design is correct on a server with row locks,
 * but it has one failure mode the application cannot see coming — two connections
 * can read the SAME pre-transaction state, both conclude they may write, and then
 * the second writer's UPDATE is refused by the database's writer lock. The losing
 * connection did nothing wrong; it simply is not the connection whose decision
 * survives.
 *
 * Left alone, that refusal reaches the player as a failed purchase or a failed
 * settlement. Worse, it is reported as an *unknown* failure, so a retry is the only
 * recovery a client has — and on a purchase path a blind client retry is exactly
 * the double-charge the idempotency layer exists to prevent.
 *
 * So the refusal is classified here, and the caller is given the tool to observe
 * the winner instead of surfacing the error. What must NOT happen is a retry of the
 * write: this connection already decided the player could afford the bet. A second
 * attempt on the same decision is the duplicate. Only a READ is retried, and a read
 * has no side effect at all.
 *
 * DRIVER CODES, NOT MESSAGES
 * The classification is by SQLSTATE and driver error code. Matching message text
 * would break the moment a driver is localised, upgraded or wrapped, and would also
 * risk matching an unrelated error that merely mentions "locked".
 *   SQLite     SQLSTATE[HY000], driver 5 (SQLITE_BUSY) or 6 (SQLITE_LOCKED)
 *   PostgreSQL 40P01 (deadlock_detected) or 40001 (serialization_failure)
 *   MySQL      1213 (deadlock) or 1205 (lock wait timeout)
 *
 * THE SQLITE NOTE
 * A busy timeout does NOT repair this case, which is why none is configured.
 * SQLite skips the busy handler when the connection already holds a SHARED lock
 * from an earlier read in the same transaction, because waiting there would
 * deadlock: the winner cannot commit while the loser holds a read lock. The loser
 * is told SQLITE_BUSY immediately and must resolve it itself — by waiting OUTSIDE
 * the transaction and reading the winner's committed row.
 */
final class WriteConflict
{
    /**
     * How many times a caller re-reads for the winner before giving up.
     *
     * Ten polls 50 ms apart is half a second: comfortably longer than any
     * transaction on these paths can run, and short enough that a genuinely
     * contended request still answers promptly rather than hanging a player or a
     * queue worker.
     */
    public const ATTEMPTS = 10;

    public const BACKOFF_MICROSECONDS = 50_000;

    /**
     * Whether a driver-level query failure is a lost write race rather than a defect.
     */
    public static function isLostRace(QueryException $exception): bool
    {
        $sqlState = (string) $exception->getCode();
        $driverCode = isset($exception->errorInfo[1]) ? (int) $exception->errorInfo[1] : 0;

        return match (true) {
            $driverCode === 5, $driverCode === 6 => true,              // SQLite
            $sqlState === '40P01', $sqlState === '40001' => true,      // PostgreSQL
            $driverCode === 1205, $driverCode === 1213 => true,        // MySQL/MariaDB
            default => false,
        };
    }

    /**
     * Wait for the winner of a lost race to become visible, then return what the
     * probe found. Returns null when nothing became visible within the budget, in
     * which case the caller MUST re-throw the original exception.
     *
     * The probe is expected to be a read that returns null while the winner has not
     * committed yet. It must never mutate: it runs outside any transaction and its
     * result is the only thing this class trusts.
     *
     * @template T
     *
     * @param  callable(): ?T  $probe
     * @return T|null
     */
    public static function awaitWinner(callable $probe): mixed
    {
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            usleep(self::BACKOFF_MICROSECONDS);

            $found = $probe();

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
