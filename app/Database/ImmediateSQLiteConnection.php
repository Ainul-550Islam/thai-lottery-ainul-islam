<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Database\SQLiteConnection;

final class ImmediateSQLiteConnection extends SQLiteConnection
{
    protected function createTransaction()
    {
        if ($this->transactions > 0) {
            parent::createTransaction();
            return;
        }
        if ($this->getPdo() instanceof \PDO) {
            $mode = strtoupper((string) ($this->getConfig('transaction_mode') ?? 'IMMEDIATE'));
            if (! in_array($mode, ['DEFERRED', 'IMMEDIATE', 'EXCLUSIVE'], true)) {
                throw new \InvalidArgumentException('Unsupported SQLite transaction mode.');
            }
            $this->getPdo()->exec('BEGIN '.$mode.' TRANSACTION');
            return;
        }
        parent::createTransaction();
    }
}
