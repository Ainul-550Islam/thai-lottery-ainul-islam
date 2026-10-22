<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Exceptions\FinancialException;
use App\Services\Finance\LedgerPostingObserver;
use App\Services\Finance\LedgerPostingService;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RunsServerFinancialRaces;

final class LedgerPostingObserverOwnershipTest extends FinancialMasterTestCase
{
    use RunsServerFinancialRaces;

    public static function borrowedProfiles(): array
    {
        $rows = [];
        foreach (['same-object', 'shared-pdo', 'managed-idle', 'managed-active', 'unregistered-active', 'read-pdo'] as $kind) {
            foreach (['root', 'savepoint'] as $mode) {
                $rows[$kind.'-'.$mode] = [$kind, $mode];
            }
        }

        return $rows;
    }

    #[DataProvider('borrowedProfiles')]
    public function test_borrowed_candidates_are_refused_without_disconnecting_or_owning_caller_state(string $kind, string $mode): void
    {
        [$tx, $pairs] = LedgerPostingCurrentReadTest::bookFixture();
        $writer = DB::connection();
        $pdo = $writer->getPdo();
        $factory = app('db.factory');
        $target = null;
        $registered = false;
        $config = $writer->getConfig();
        unset($config['read'], $config['write']);
        DB::beginTransaction();
        DB::table('financial_transactions')->where('id', $tx->id)->update(['description' => 'caller marker survives refusal']);
        if ($mode === 'savepoint') {
            DB::beginTransaction();
        }
        try {
            if ($kind === 'same-object') {
                $target = $writer;
            } elseif ($kind === 'shared-pdo') {
                $target = new ObserverDisconnectSpy($pdo, $writer->getDatabaseName(), '', $config);
            } elseif ($kind === 'read-pdo') {
                $fresh = $factory->make($config);
                $target = new ObserverDisconnectSpy($fresh->getPdo(), $writer->getDatabaseName(), '', $config);
                $target->setReadPdo($pdo);
            } else {
                // A separate temporary table proves an unrelated active borrowed
                // transaction survives; never stage writes against caller rows.
                $otherConfig = $config;
                if ($writer->getDriverName() === 'sqlite') {
                    $otherConfig['database'] = ':memory:';
                }
                if ($kind === 'unregistered-active') {
                    $fresh = $factory->make($otherConfig);
                    $target = new ObserverDisconnectSpy($fresh->getPdo(), $fresh->getDatabaseName(), '', $otherConfig);
                } else {
                    $target = DB::connectUsing('part78_borrowed', $otherConfig, true);
                    $registered = true;
                }
                $target->getPdo();
                if (in_array($kind, ['managed-active', 'unregistered-active'], true)) {
                    $target->getPdo()->exec('CREATE TEMPORARY TABLE part78_borrowed_marker (id INT PRIMARY KEY)');
                    $target->beginTransaction();
                    $target->getPdo()->exec('INSERT INTO part78_borrowed_marker (id) VALUES (78)');
                }
            }
            $targetPdo = $target->getRawPdo();
            $targetReadPdo = $target->getRawReadPdo();
            $targetDepth = $target->transactionLevel();
            $targetPhysical = $targetPdo->inTransaction();
            app()->instance('db.factory', new class(app(), $target) extends ConnectionFactory
            {
                public function __construct($app, private readonly Connection $target)
                {
                    parent::__construct($app);
                }

                public function make(array $config, $name = null)
                {
                    return $this->target;
                }
            });
            try {
                if ($writer->getDriverName() === 'mysql') {
                    app(LedgerPostingService::class)->post($tx, $pairs[0]);
                } else {
                    // SQLite posting needs no secondary observer. Exercise this
                    // ownership helper directly with genuine SQLite PDO handles.
                    app(LedgerPostingObserver::class)->hasPosting($writer, $tx->id);
                }
                $this->fail('Borrowed observer must not be used or disconnected.');
            } catch (FinancialException $e) {
                $this->assertSame('ledger_posting_current_reader_invalid', $e->errorCode());
            } finally {
                app()->instance('db.factory', $factory);
            }
            $this->assertSame($pdo, $writer->getRawPdo());
            $this->assertSame($mode === 'root' ? 1 : 2, $writer->transactionLevel());
            $this->assertTrue($pdo->inTransaction());
            $this->assertSame($targetPdo, $target->getRawPdo());
            $this->assertSame($targetReadPdo, $target->getRawReadPdo());
            $this->assertSame($targetDepth, $target->transactionLevel());
            $this->assertSame($targetPhysical, $targetPdo->inTransaction());
            if ($target instanceof ObserverDisconnectSpy) {
                $this->assertSame(0, $target->disconnects);
            }
            if (in_array($kind, ['managed-active', 'unregistered-active'], true)) {
                $this->assertSame(1, (int) $target->getPdo()->query('SELECT COUNT(*) FROM part78_borrowed_marker')->fetchColumn());
            }
            $this->assertSame(0, DB::table('ledger_entries')->count());
            $this->assertSame(0, DB::table('ledger_accounts')->where('current_balance', '<>', 0)->count());
            if ($mode === 'savepoint') {
                DB::rollBack();
                $this->assertSame(1, $writer->transactionLevel());
                $this->assertTrue($pdo->inTransaction());
            }
            DB::commit();
            $this->assertSame('caller marker survives refusal', $tx->fresh()->description);
            $this->assertSame(0, $writer->transactionLevel());
            $this->assertFalse($pdo->inTransaction());
        } finally {
            app()->instance('db.factory', $factory);
            if ($writer->transactionLevel() > 0) {
                $writer->rollBack(0);
            }
            if ($target !== null && $target !== $writer) {
                if ($target->transactionLevel() > 0) {
                    $target->rollBack(0);
                }
                $target->disconnect();
            }
            if ($registered) {
                DB::purge('part78_borrowed');
            }
        }
    }

    public static function ownedProfiles(): array
    {
        $rows = [];
        foreach (['healthy', 'autocommit-off', 'probe-failure'] as $kind) {
            foreach (['root', 'savepoint'] as $mode) {
                $rows[$kind.'-'.$mode] = [$kind, $mode];
            }
        }

        return $rows;
    }

    #[DataProvider('ownedProfiles')]
    public function test_owned_observer_is_cleaned_once_without_committing_repairing_or_disconnecting_caller(string $kind, string $mode): void
    {
        $this->requireServerRace();
        [$tx, $pairs] = LedgerPostingCurrentReadTest::bookFixture();
        $writer = DB::connection();
        $pdo = $writer->getPdo();
        $factory = app('db.factory');
        $config = $writer->getConfig();
        unset($config['read'], $config['write']);
        $fresh = $factory->make($config);
        $otherPdo = $fresh->getPdo();
        $target = new OwnedMySqlObserverSpy($otherPdo, $fresh->getDatabaseName(), '', $config);
        if ($kind === 'autocommit-off') {
            $otherPdo->exec('SET SESSION autocommit = 0');
            $this->assertFalse($otherPdo->inTransaction());
        } elseif ($kind === 'probe-failure') {
            $target->beforeExecuting(function (string $query): void {
                if (str_contains($query, 'ledger_entries')) {
                    throw new \RuntimeException('synthetic observation query failure');
                }
            });
        }
        DB::beginTransaction();
        DB::table('financial_transactions')->where('id', $tx->id)->update(['description' => 'owned observer caller marker']);
        if ($mode === 'savepoint') {
            DB::beginTransaction();
        }
        try {
            app()->instance('db.factory', new class(app(), $target) extends ConnectionFactory
            {
                public function __construct($app, private readonly Connection $target)
                {
                    parent::__construct($app);
                }

                public function make(array $config, $name = null)
                {
                    return $this->target;
                }
            });
            try {
                app(LedgerPostingService::class)->post($tx, $pairs[0]);
                $this->assertSame('healthy', $kind);
            } catch (FinancialException $e) {
                $this->assertSame('autocommit-off', $kind);
                $this->assertSame('ledger_posting_current_reader_invalid', $e->errorCode());
            } catch (\RuntimeException $e) {
                $this->assertSame('probe-failure', $kind);
                $this->assertSame('synthetic observation query failure', $e->getMessage());
            } finally {
                app()->instance('db.factory', $factory);
            }
            $this->assertSame(1, $target->disconnects);
            $this->assertNull($target->getRawPdo());
            $this->assertSame($pdo, $writer->getRawPdo());
            $this->assertSame($mode === 'root' ? 1 : 2, $writer->transactionLevel());
            $this->assertTrue($pdo->inTransaction());
            $this->assertSame($kind === 'healthy' ? 2 : 0, DB::table('ledger_entries')->count());
            if ($kind === 'autocommit-off') {
                $this->assertSame(0, (int) $otherPdo->query('SELECT @@SESSION.autocommit')->fetchColumn());
            }
            if ($mode === 'savepoint') {
                DB::rollBack();
            }
            DB::commit();
            $this->assertSame('owned observer caller marker', $tx->fresh()->description);
            $expected = $kind === 'healthy' && $mode === 'root' ? 2 : 0;
            $this->assertSame($expected, DB::table('ledger_entries')->count());
            $this->assertSame($expected, DB::table('ledger_accounts')->where('current_balance', '10.00')->count());
            $this->assertSame(0, DB::table('wallets')->count());
            $this->assertSame(0, $writer->transactionLevel());
            $this->assertFalse($pdo->inTransaction());
        } finally {
            app()->instance('db.factory', $factory);
            if ($writer->transactionLevel() > 0) {
                $writer->rollBack(0);
            }
            // Test cleanup only; application code must not repair this setting.
            $otherPdo->exec('SET SESSION autocommit = 1');
            $target->disconnect();
        }
    }
}

final class ObserverDisconnectSpy extends Connection
{
    public int $disconnects = 0;

    public function disconnect()
    {
        $this->disconnects++;
        parent::disconnect();
    }
}

final class OwnedMySqlObserverSpy extends MySqlConnection
{
    public int $disconnects = 0;

    public function disconnect()
    {
        $this->disconnects++;
        parent::disconnect();
    }
}
