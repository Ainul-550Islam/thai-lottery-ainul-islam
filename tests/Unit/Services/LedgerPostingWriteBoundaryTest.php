<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\FinancialException;
use App\Services\Finance\LedgerPostingWriteBoundary;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LedgerPostingWriteBoundaryTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::clearResolvedInstance('db');
        parent::tearDown();
    }

    private function fixture(int $depth, string $name = 'mysql'): object
    {
        $state = (object) ['depth' => $depth, 'name' => $name, 'active' => true, 'throws' => false];
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturnCallback(function () use ($state) {
            if ($state->throws) {
                throw new PDOException('sensitive connection failure');
            }

            return $state->active;
        });
        $pdo->expects($this->never())->method('beginTransaction');
        $pdo->expects($this->never())->method('commit');
        $pdo->expects($this->never())->method('rollBack');
        $state->pdo = $pdo;
        $connection = $this->createMock(Connection::class);
        $connection->method('getRawPdo')->willReturnCallback(fn () => $state->pdo);
        $connection->method('transactionLevel')->willReturnCallback(fn () => $state->depth);
        foreach (['getPdo', 'reconnect', 'disconnect', 'beginTransaction', 'commit', 'rollBack', 'select', 'statement'] as $method) {
            $connection->expects($this->never())->method($method);
        }
        $manager = $this->createMock(DatabaseManager::class);
        $state->connections = [$name => $connection];
        $manager->method('getDefaultConnection')->willReturnCallback(fn () => $state->name);
        $manager->method('getConnections')->willReturnCallback(fn () => $state->connections);
        foreach (['connection', 'reconnect', 'disconnect', 'purge'] as $method) {
            $manager->expects($this->never())->method($method);
        }
        $state->manager = $manager;
        $state->connection = $connection;
        DB::swap($manager);

        return $state;
    }

    public static function mutations(): array
    {
        $rows = [];
        foreach ([1, 2, 5] as $depth) {
            foreach (['manager', 'default', 'missing', 'registration', 'pdo-null', 'pdo-lazy', 'pdo-other', 'depth-zero', 'depth-lower', 'depth-higher', 'inactive', 'pdo-throws'] as $kind) {
                $rows[$kind.'-'.$depth] = [$depth, $kind];
            }
        }

        return $rows;
    }

    #[DataProvider('mutations')]
    public function test_changed_context_refuses_without_sql_resolution_repair_or_payload_diagnostics(int $depth, string $kind): void
    {
        $state = $this->fixture($depth);
        $witness = LedgerPostingWriteBoundary::capture();
        $reason = match ($kind) {
            'manager' => 'manager_changed', 'default' => 'default_writer_changed',
            'missing', 'registration' => 'writer_registration_changed',
            'pdo-null', 'pdo-lazy', 'pdo-other' => 'write_pdo_changed',
            'depth-zero', 'depth-lower', 'depth-higher' => 'framework_depth_changed',
            'inactive' => 'physical_transaction_inactive', default => 'physical_state_unavailable',
        };
        match ($kind) {
            'manager' => DB::swap($this->createMock(DatabaseManager::class)),
            'default' => $state->name = 'unconfigured-sensitive-host',
            'missing' => $state->connections = [],
            'registration' => $state->connections['mysql'] = $this->createMock(Connection::class),
            'pdo-null' => $state->pdo = null,
            'pdo-lazy' => $state->pdo = function () {
                $this->fail('A PDO resolver must not run.');
            },
            'pdo-other' => $state->pdo = $this->createMock(PDO::class),
            'depth-zero' => $state->depth = 0,
            'depth-lower' => $state->depth = $depth - 1,
            'depth-higher' => $state->depth = $depth + 1,
            'inactive' => $state->active = false,
            'pdo-throws' => $state->throws = true,
        };
        $before = clone $state;
        try {
            $witness->assertUnchanged();
            $this->fail('Expected write boundary refusal.');
        } catch (FinancialException $error) {
            $this->assertSame('ledger_posting_write_context_changed', $error->errorCode());
            $this->assertSame(['stage' => 'payload_preflight', 'reason' => $reason], $error->context());
            $this->assertNull($error->getPrevious());
            $this->assertEquals($before, $state);
        }
    }

    public static function unchanged(): array
    {
        $rows = [];
        foreach (['sqlite', 'mysql'] as $name) {
            foreach ([1, 2, 5] as $depth) {
                $rows[] = [$name, $depth];
            }
        }

        return $rows;
    }

    #[DataProvider('unchanged')]
    public function test_unchanged_witness_can_be_checked_repeatedly_without_owning_the_context(string $name, int $depth): void
    {
        $state = $this->fixture($depth, $name);
        $before = clone $state;
        $one = LedgerPostingWriteBoundary::capture();
        $two = LedgerPostingWriteBoundary::capture();
        $one->assertUnchanged();
        $two->assertUnchanged();
        $one->assertUnchanged();
        $this->assertNotSame($one, $two);
        $this->assertEquals($before, $state);
    }

    public static function invalidCapture(): array
    {
        return [['zero'], ['negative'], ['null'], ['lazy'], ['inactive'], ['unregistered']];
    }

    #[DataProvider('invalidCapture')]
    public function test_capture_refuses_non_live_or_non_registered_writers_without_resolution(string $kind): void
    {
        $state = $this->fixture(1);
        $reason = match ($kind) {
            'zero', 'negative' => 'framework_depth_invalid', 'null', 'lazy' => 'write_pdo_unavailable',
            'inactive' => 'physical_transaction_inactive', default => 'writer_unregistered',
        };
        match ($kind) {
            'zero' => $state->depth = 0, 'negative' => $state->depth = -1,
            'null' => $state->pdo = null,
            'lazy' => $state->pdo = function () {
                $this->fail('Do not connect.');
            },
            'inactive' => $state->active = false, 'unregistered' => $state->connections = [],
        };
        try {
            LedgerPostingWriteBoundary::capture();
            $this->fail('Expected capture refusal.');
        } catch (FinancialException $error) {
            $this->assertSame('ledger_posting_write_context_changed', $error->errorCode());
            $this->assertSame($reason, $error->contextValue('reason'));
        }
    }
}
