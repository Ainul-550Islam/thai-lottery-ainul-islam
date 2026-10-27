<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;

/**
 * Exercise the real SQLite connection grammar, not a string-only config grep.
 * No production database or money-bearing row is created by these tests.
 */
final class SqliteLockingConfigurationTest extends TestCase
{
    public function test_sqlite_reserves_the_writer_before_transactional_reads(): void
    {
        $configuration = config('database.connections.sqlite');
        $expectedTimeout = max(0, (int) env('DB_BUSY_TIMEOUT', 15000));
        $this->assertSame('IMMEDIATE', $configuration['transaction_mode']);
        $this->assertSame($expectedTimeout, $configuration['busy_timeout']);
        $connection = DB::build([...$configuration, 'database' => ':memory:']);

        try {
            $this->assertSame($expectedTimeout, (int) $connection->selectOne('PRAGMA busy_timeout')->timeout);
        } finally {
            $connection->disconnect();
        }
    }

    public function test_a_new_connection_applies_its_bounded_busy_timeout(): void
    {
        $connection = DB::build([
            ...config('database.connections.sqlite'),
            'database' => ':memory:',
            'busy_timeout' => 2250,
        ]);

        try {
            $timeout = $connection->selectOne('PRAGMA busy_timeout');

            $this->assertSame(2250, (int) $timeout->timeout);
            $this->assertSame('IMMEDIATE', $connection->getConfig('transaction_mode'));
        } finally {
            $connection->disconnect();
        }
    }

    public function test_begin_itself_serializes_two_writers_before_either_reads_a_row(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'lottery-sqlite-lock-');
        $this->assertIsString($path);
        $configuration = [
            ...config('database.connections.sqlite'),
            'database' => $path,
            'busy_timeout' => 1,
        ];
        $first = DB::build($configuration);
        $second = DB::build($configuration);

        try {
            // No table or row has been read or written. IMMEDIATE nevertheless
            // owns the writer reservation, preventing the deferred-upgrade race.
            $first->beginTransaction();
            $caught = null;

            try {
                $second->beginTransaction();
            } catch (PDOException $exception) {
                $caught = $exception;
            }

            $this->assertInstanceOf(PDOException::class, $caught);
            $this->assertSame(5, ((int) $caught->errorInfo[1]) & 0xFF, 'The second writer must report SQLITE_BUSY.');
            $this->assertSame(1, $first->transactionLevel());
            $this->assertSame(0, $second->transactionLevel());

            $first->rollBack();
            $second->beginTransaction();
            $this->assertSame(1, $second->transactionLevel(), 'The writer is available after the first transaction ends.');
            $second->rollBack();
        } finally {
            foreach ([$first, $second] as $connection) {
                if ($connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
                $connection->disconnect();
            }
            @unlink($path);
        }
    }
}
