<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Deliberate historical-corruption fixtures ONLY; never a production repair/writer. */
trait InjectsHistoricalAccountingFaults
{
    private function withoutHistoricalForeignKeys(callable $operation): mixed
    {
        return $this->historicalFault('foreign_keys', $operation);
    }

    private function withoutHistoricalWalletChecks(callable $operation): mixed
    {
        return $this->historicalFault('checks', $operation);
    }

    private function historicalFault(string $kind, callable $operation): mixed
    {
        $db = DB::connection();
        if (! app()->environment('testing') || basename((string) $db->getDatabaseName()) !== 'thai_lottery_test'
            || $db->transactionLevel() !== 0 || ! in_array($kind, ['foreign_keys', 'checks'], true)) {
            throw new RuntimeException('Historical corruption fixtures require a separately owned disposable test connection without an outer transaction.');
        }
        if ($db->getDriverName() === 'sqlite') {
            $setting = $kind === 'foreign_keys' ? 'foreign_keys' : 'ignore_check_constraints';
            $previous = (int) array_values((array) $db->selectOne('PRAGMA '.$setting))[0];
            $db->statement('PRAGMA '.$setting.' = '.($kind === 'foreign_keys' ? '0' : '1'));
            try {
                return $operation();
            } finally {
                $db->statement('PRAGMA '.$setting.' = '.$previous);
            }
        }
        $socket = $db->getConfig('unix_socket');
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true) || ! is_string($socket) || $socket === ''
            || ! str_contains((string) $db->selectOne('SELECT VERSION() AS v')->v, 'MariaDB')) {
            throw new RuntimeException('Only disposable local SQLite/MariaDB historical fault injection is supported.');
        }
        $setting = $kind === 'foreign_keys' ? 'foreign_key_checks' : 'check_constraint_checks';
        $previous = (int) $db->selectOne('SELECT @@SESSION.'.$setting.' AS v')->v;
        $db->statement('SET SESSION '.$setting.' = 0');
        try {
            return $operation();
        } finally {
            $db->statement('SET SESSION '.$setting.' = '.$previous);
        }
    }
}
