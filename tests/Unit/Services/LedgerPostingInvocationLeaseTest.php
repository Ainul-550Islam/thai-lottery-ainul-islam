<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\FinancialException;
use App\Services\Finance\LedgerPostingInvocationLease;
use Illuminate\Database\Connection;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TypeError;
use WeakReference;

final class LedgerPostingInvocationLeaseTest extends TestCase
{
    private function handle(): PDO
    {
        $pdo = $this->createMock(PDO::class);
        foreach (['beginTransaction', 'commit', 'rollBack', 'inTransaction', 'query', 'exec', 'prepare', 'getAttribute'] as $method) {
            $pdo->expects($this->never())->method($method);
        }

        return $pdo;
    }

    private function assertBusy(PDO $pdo): void
    {
        try {
            LedgerPostingInvocationLease::acquire($pdo);
            $this->fail('Overlapping same-PDO invocation must refuse.');
        } catch (FinancialException $error) {
            $this->assertSame('ledger_posting_reentrant', $error->errorCode());
            $this->assertSame(['stage' => 'posting_invocation'], $error->context());
            $this->assertNull($error->getPrevious());
        }
    }

    public static function exits(): array
    {
        $rows = [];
        foreach (['normal', 'exception', 'error'] as $exit) {
            foreach ([1, 2, 5] as $attempts) {
                foreach ([false, true] as $repeatRelease) {
                    $rows[] = [$exit, $attempts, $repeatRelease];
                }
            }
        }

        return $rows;
    }

    #[DataProvider('exits')]
    public function test_owner_finally_releases_after_return_exception_or_error(string $exit, int $attempts, bool $repeatRelease): void
    {
        $pdo = $this->handle();
        $lease = LedgerPostingInvocationLease::acquire($pdo);
        try {
            for ($i = 0; $i < $attempts; $i++) {
                $this->assertBusy($pdo);
            }
            match ($exit) {
                'exception' => throw new RuntimeException('synthetic failure'),
                'error' => throw new TypeError('synthetic error'),
                default => null,
            };
        } catch (RuntimeException|TypeError $expected) {
            $this->assertSame('synthetic '.($exit === 'error' ? 'error' : 'failure'), $expected->getMessage());
        } finally {
            $lease->release();
        }
        if ($repeatRelease) {
            $lease->release();
        }
        $next = LedgerPostingInvocationLease::acquire($pdo);
        $this->assertBusy($pdo);
        $next->release();
        $this->addToAssertionCount(1);
    }

    public static function staleOwners(): array
    {
        $rows = [];
        foreach ([1, 2, 3, 5] as $cycles) {
            foreach ([1, 2, 4] as $repeats) {
                $rows[] = [$cycles, $repeats];
            }
        }

        return $rows;
    }

    #[DataProvider('staleOwners')]
    public function test_old_repeated_release_cannot_clear_a_new_owner(int $cycles, int $repeats): void
    {
        $pdo = $this->handle();
        $old = [];
        for ($i = 0; $i < $cycles; $i++) {
            $lease = LedgerPostingInvocationLease::acquire($pdo);
            foreach ($old as $stale) {
                for ($j = 0; $j < $repeats; $j++) {
                    $stale->release();
                }
            }
            $this->assertBusy($pdo);
            $lease->release();
            $old[] = $lease;
        }
        $next = LedgerPostingInvocationLease::acquire($pdo);
        $this->assertBusy($pdo);
        $next->release();
    }

    public static function independent(): array
    {
        return [[2, false], [2, true], [3, false], [3, true], [5, false], [5, true]];
    }

    #[DataProvider('independent')]
    public function test_independent_handles_are_not_globally_serialized(int $count, bool $reverse): void
    {
        $pdos = $leases = [];
        for ($i = 0; $i < $count; $i++) {
            $pdos[$i] = $this->handle();
            $leases[$i] = LedgerPostingInvocationLease::acquire($pdos[$i]);
        }
        $order = array_keys($leases);
        if ($reverse) {
            $order = array_reverse($order);
        }
        foreach ($order as $key) {
            foreach ($leases as $i => $lease) {
                $this->assertBusy($pdos[$i]);
            }
            $leases[$key]->release();
            unset($leases[$key]);
            $replacement = LedgerPostingInvocationLease::acquire($pdos[$key]);
            $replacement->release();
        }
        $this->assertSame([], $leases);
    }

    public static function collection(): array
    {
        return [[1], [2], [5], [10]];
    }

    #[DataProvider('collection')]
    public function test_released_handles_are_not_retained_by_the_weak_registry(int $cycles): void
    {
        for ($i = 0; $i < $cycles; $i++) {
            $pdo = new PDO('sqlite::memory:');
            $weak = WeakReference::create($pdo);
            $lease = LedgerPostingInvocationLease::acquire($pdo);
            $lease->release();
            unset($lease, $pdo);
            gc_collect_cycles();
            $this->assertNull($weak->get());
        }
    }

    public static function aliases(): array
    {
        $rows = [];
        foreach (['mysql', 'mysql::write', 'book_alias', 'tenant_ignored'] as $alias) {
            foreach ([false, true] as $differentDatabaseLabel) {
                $rows[] = [$alias, $differentDatabaseLabel];
            }
        }

        return $rows;
    }

    #[DataProvider('aliases')]
    public function test_connection_wrapper_labels_do_not_bypass_physical_handle_identity(string $alias, bool $differentDatabaseLabel): void
    {
        $pdo = $this->handle();
        $first = new Connection($pdo, 'synthetic', '', ['name' => 'mysql']);
        $other = new Connection($pdo, $differentDatabaseLabel ? 'label-only' : 'synthetic', '', ['name' => $alias]);
        $lease = LedgerPostingInvocationLease::acquire($first->getRawPdo());
        try {
            $this->assertNotSame($first, $other);
            $this->assertSame($first->getRawPdo(), $other->getRawPdo());
            $this->assertBusy($other->getRawPdo());
        } finally {
            $lease->release();
        }
        $next = LedgerPostingInvocationLease::acquire($other->getRawPdo());
        $next->release();
    }
}
