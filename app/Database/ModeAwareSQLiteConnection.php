<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Database\SQLiteConnection;
use InvalidArgumentException;

/**
 * SQLite connection that starts every transaction with the configured lock mode.
 *
 * A default (DEFERRED) transaction takes no write lock at BEGIN. Two requests can both read a
 * balance, then one of them is refused with SQLITE_BUSY when it tries to write. Under load that
 * surfaces as a refused purchase or a database-locked error, and it can happen after the first
 * read has already been used for a money decision. BEGIN IMMEDIATE reserves the writer at BEGIN,
 * so competing writers queue behind the busy timeout instead of failing mid-transaction.
 *
 * Laravel only applies `transaction_mode` on PHP 8.4 and later, so this class applies it on
 * every supported PHP version.
 */
final class ModeAwareSQLiteConnection extends SQLiteConnection
{
    public const MODES = ['DEFERRED', 'IMMEDIATE', 'EXCLUSIVE'];

    protected function executeBeginTransactionStatement(): void
    {
        $mode = strtoupper((string) ($this->getConfig('transaction_mode') ?? 'DEFERRED'));

        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException(
                "Unsupported SQLite transaction_mode [{$mode}]. Expected one of: ".implode(', ', self::MODES).'.',
            );
        }

        $this->getPdo()->exec("BEGIN {$mode} TRANSACTION");
    }
}
