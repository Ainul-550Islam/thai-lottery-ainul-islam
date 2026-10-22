<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\FinancialTransactionType;
use App\Exceptions\FinancialException;
use App\Models\FinancialTransaction;
use App\Services\Finance\FinancialTransactionService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletLockService;
use App\Services\Finance\WalletService;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LifecycleBoundaryOperation;

final class FinancialRetryOwnershipTest extends FinancialMasterTestCase
{
    public static function badStates(): array
    {
        return [['unmanaged'], ['commit'], ['rollback'], ['nested']];
    }

    #[DataProvider('badStates')]
    public function test_divergent_entry_never_invokes_the_callback(string $state): void
    {
        $db = DB::connection();
        if ($state === 'unmanaged') {
            $db->getPdo()->beginTransaction();
        } else {
            $db->beginTransaction();
            if ($state === 'nested') {
                $db->beginTransaction();
            }
            $state === 'rollback' ? $db->getPdo()->rollBack() : $db->getPdo()->commit();
        }
        $level = $db->transactionLevel();
        $physical = $db->getPdo()->inTransaction();
        $calls = (object) ['count' => 0];
        $db->enableQueryLog();
        $db->flushQueryLog();
        try {
            app(FinancialTransactionService::class)->executeWithDeadlockRetry(function () use (&$calls): void {
                $calls->count++;
            });
            $this->fail('Divergent boundary invoked work.');
        } catch (FinancialException $exception) {
            $this->assertSame('wallet_transaction_state_invalid', $exception->errorCode());
            $this->assertSame(0, $calls->count);
            $this->assertSame([], $db->getQueryLog());
            $this->assertSame($level, $db->transactionLevel());
            $this->assertSame($physical, $db->getPdo()->inTransaction());
        } finally {
            $db->disableQueryLog();
            if ($state === 'unmanaged') {
                $db->getPdo()->rollBack();
            } else {
                $db->rollBack(0);
            }
        }
    }

    public static function failureCodes(): array
    {
        return [[1213], [1205], ['1213'], ['1205'], [1062], [9999], [null]];
    }

    public static function fault(int|string|null $code): QueryException
    {
        $isDeadlock = in_array($code, [1213, '1213'], true);
        $previous = new \PDOException($isDeadlock ? 'Deadlock found when trying to get lock; try restarting transaction' : 'Lock wait timeout exceeded; try restarting transaction', $isDeadlock ? 40001 : 0);
        $previous->errorInfo = [$isDeadlock ? '40001' : 'HY000', $code, $previous->getMessage()];

        return new QueryException(DB::connection()->getName(), 'synthetic financial retry fault', [], $previous);
    }

    #[DataProvider('failureCodes')]
    public function test_owned_root_retries_only_native_canonical_codes_after_full_accounting_rollback(int|string|null $code): void
    {
        config(['finance.locking.max_retries' => 3, 'finance.locking.retry_delay_milliseconds' => 0]);
        $wallet = $this->createPlayer('0.00')['wallet'];
        $paper = LifecycleBoundaryOperation::paper();
        $calls = (object) ['count' => 0];
        $exception = self::fault($code);
        $retry = DB::connection()->getDriverName() !== 'sqlite' && in_array($code, [1213, 1205, '1213', '1205'], true);
        try {
            $result = app(FinancialTransactionService::class)->executeWithDeadlockRetry(fn () => app(WalletLockService::class)->withinTransaction(function () use ($wallet, &$calls, $exception): FinancialTransaction {
                $calls->count++;
                if ($calls->count > 1) {
                    $this->assertSame('0.00', $wallet->fresh()->balance);
                    $this->assertSame(0, FinancialTransaction::query()->count());
                }
                $row = app(WalletService::class)->credit($wallet, Money::of('10.00', $wallet->currency), FinancialTransactionType::Deposit, 'retry-owned-stable');
                if ($calls->count === 1) {
                    throw $exception;
                }

                return $row;
            }));
            $this->assertTrue($retry);
            $this->assertSame(2, $calls->count);
            $this->assertSame('10.00', $wallet->fresh()->balance);
            $this->assertSame(1, FinancialTransaction::query()->count());
            $this->assertSame(2, DB::table('ledger_entries')->count());
            $this->assertSame('completed', $result->status->value);
        } catch (QueryException|DeadlockException $actual) {
            $this->assertFalse($retry);
            $this->assertSame($exception, $actual);
            $this->assertSame(1, $calls->count);
            $this->assertSame($paper, LifecycleBoundaryOperation::paper());
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    #[DataProvider('failureCodes')]
    public function test_caller_owned_savepoint_is_never_retried_and_partial_accounting_is_undone(int|string|null $code): void
    {
        config(['finance.locking.max_retries' => 3, 'finance.locking.retry_delay_milliseconds' => 0]);
        $wallet = $this->createPlayer('0.00')['wallet'];
        $paper = LifecycleBoundaryOperation::paper();
        $calls = (object) ['count' => 0];
        $originalName = $wallet->user->name;
        DB::beginTransaction();
        DB::table('users')->where('id', $wallet->user_id)->update(['name' => 'unrelated caller work']);
        $fault = self::fault($code);
        $rootAborted = DB::connection()->getDriverName() !== 'sqlite' && in_array($code, [1213, '1213'], true);
        try {
            app(FinancialTransactionService::class)->executeWithDeadlockRetry(fn () => app(WalletLockService::class)->withinTransaction(function () use ($wallet, &$calls, $fault, $rootAborted): void {
                $calls->count++;
                app(WalletService::class)->credit($wallet, Money::of('10.00', $wallet->currency), FinancialTransactionType::Deposit, 'retry-ambient-stable');
                // Synthetic code test models engine-wide abort; real 1213 is separately exercised in two children.
                if ($rootAborted) {
                    DB::connection()->getPdo()->rollBack();
                }
                throw $fault;
            }));
            $this->fail('Fault disappeared.');
        } catch (QueryException|DeadlockException $actual) {
            $this->assertSame($fault, $actual instanceof DeadlockException ? $actual->getPrevious() : $actual);
            $this->assertSame(1, $calls->count);
            $this->assertSame(1, DB::transactionLevel());
            $this->assertSame(! $rootAborted, DB::connection()->getPdo()->inTransaction());
            $this->assertSame($paper, LifecycleBoundaryOperation::paper());
            $rootAborted ? DB::rollBack(0) : DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack(0);
            }
        }
        $this->assertSame($rootAborted ? $originalName : 'unrelated caller work', $wallet->user->fresh()->name);
    }

    public static function budgets(): array
    {
        return [[0], [1], [2], [3]];
    }

    #[DataProvider('budgets')]
    public function test_exhaustion_preserves_the_failure_and_leaves_no_money(int $budget): void
    {
        config(['finance.locking.max_retries' => $budget, 'finance.locking.retry_delay_milliseconds' => 0]);
        $wallet = $this->createPlayer('0.00')['wallet'];
        $paper = LifecycleBoundaryOperation::paper();
        $calls = (object) ['count' => 0];
        $fault = self::fault(1213);
        try {
            app(FinancialTransactionService::class)->executeWithDeadlockRetry(fn () => app(WalletLockService::class)->withinTransaction(function () use (&$calls, $fault, $wallet): void {
                $calls->count++;
                app(WalletService::class)->credit($wallet, Money::of('10.00', $wallet->currency), FinancialTransactionType::Deposit, 'retry-exhaustion');
                throw $fault;
            }));
            $this->fail('Exhausted failure disappeared.');
        } catch (QueryException $actual) {
            $this->assertSame($fault, $actual);
        }
        $this->assertSame(DB::connection()->getDriverName() === 'sqlite' ? 1 : max(1, $budget), $calls->count);
        $this->assertSame($paper, LifecycleBoundaryOperation::paper());
    }

    public function test_lost_caller_root_does_not_grant_retry_ownership(): void
    {
        DB::beginTransaction();
        $calls = (object) ['count' => 0];
        $fault = self::fault(1205);
        try {
            app(FinancialTransactionService::class)->executeWithDeadlockRetry(function () use (&$calls, $fault): void {
                $calls->count++;
                DB::rollBack(0);
                throw $fault;
            });
        } catch (QueryException $actual) {
            $this->assertSame($fault, $actual);
        }
        $this->assertSame(1, $calls->count);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_owned_callback_leaving_a_transaction_is_not_adopted_or_retried(): void
    {
        $calls = (object) ['count' => 0];
        $fault = self::fault(1205);
        try {
            app(FinancialTransactionService::class)->executeWithDeadlockRetry(function () use (&$calls, $fault): void {
                $calls->count++;
                DB::beginTransaction();
                throw $fault;
            });
        } catch (QueryException $actual) {
            $this->assertSame($fault, $actual);
        }
        $this->assertSame(1, $calls->count);
        $this->assertSame(1, DB::transactionLevel());
        $this->assertTrue(DB::connection()->getPdo()->inTransaction());
        DB::rollBack(0);
    }
}
