<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\TransactionStatus;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\FinancialTransaction;
use App\Models\Withdrawal;
use App\Services\Finance\FinancialStateTransitionService;
use App\Services\Finance\FinancialTransactionService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LifecycleBoundaryOperation as Operation;

class LifecycleTransactionBoundaryTest extends FinancialMasterTestCase
{
    protected Deposit $deposit;

    protected Withdrawal $withdrawal;

    protected FinancialTransaction $transaction;

    protected function setUp(): void
    {
        parent::setUp();
        $wallet = $this->createPlayer()['wallet'];
        $fields = ['user_id' => $wallet->user_id, 'wallet_id' => $wallet->id];
        $this->deposit = Deposit::factory()->create($fields);
        $this->withdrawal = Withdrawal::factory()->create($fields);
        $this->transaction = FinancialTransaction::factory()->create($fields);
    }

    protected function invoke(string $operation): mixed
    {
        return Operation::run($operation, $this->deposit, $this->withdrawal, $this->transaction);
    }

    protected function close(): void
    {
        $db = DB::connection();
        if ($db->getPdo()->inTransaction() && $db->transactionLevel() === 0) {
            $db->getPdo()->rollBack();
        }
        while ($db->transactionLevel() > 0) {
            $db->rollBack(0);
        }
    }

    public static function invalidProfiles(): array
    {
        $rows = [];
        foreach (Operation::profiles() as [$operation]) {
            foreach (['empty', 'unmanaged', 'commit', 'rollback', 'nested'] as $mode) {
                $rows[$operation.'-'.$mode] = [$operation, $mode];
            }
        }

        return $rows;
    }

    #[DataProvider('invalidProfiles')]
    public function test_invalid_physical_boundary_refuses_before_sql_or_model_mutation(string $operation, string $mode): void
    {
        $db = DB::connection();
        if ($mode === 'unmanaged') {
            $db->getPdo()->beginTransaction();
        } elseif ($mode !== 'empty') {
            $db->beginTransaction();
            if ($mode === 'nested') {
                $db->beginTransaction();
            }
            $mode === 'rollback' ? $db->getPdo()->rollBack() : $db->getPdo()->commit();
        }
        $paper = Operation::paper();
        $memory = $this->transaction->getAttributes();
        $level = $db->transactionLevel();
        $physical = $db->getPdo()->inTransaction();
        $db->enableQueryLog();
        $db->flushQueryLog();
        try {
            $this->invoke($operation);
            $this->fail('Invalid physical boundary was admitted.');
        } catch (FinancialException $e) {
            $this->assertSame('financial_state_transition_outside_transaction', $e->errorCode());
            $this->assertSame([], $db->getQueryLog());
            $this->assertSame($level, $db->transactionLevel());
            $this->assertSame($physical, $db->getPdo()->inTransaction());
            $this->assertSame($memory, $this->transaction->getAttributes());
        } finally {
            $db->disableQueryLog();
            $this->close();
        }
        $this->assertSame($paper, Operation::paper());
    }

    public static function healthyProfiles(): array
    {
        $rows = [];
        foreach (Operation::profiles() as [$operation]) {
            foreach ([1, 2] as $depth) {
                $rows[$operation.'-'.$depth] = [$operation, $depth];
            }
        }

        return $rows;
    }

    #[DataProvider('healthyProfiles')]
    public function test_healthy_root_and_savepoint_are_caller_owned_and_rollback_restores_every_row(string $operation, int $depth): void
    {
        $paper = Operation::paper();
        for ($i = 0; $i < $depth; $i++) {
            DB::beginTransaction();
        }
        try {
            $result = $this->invoke($operation);
            $this->assertNotNull($result);
            $this->assertSame($depth, DB::transactionLevel());
            $this->assertTrue(DB::connection()->getPdo()->inTransaction());
            if ($operation === 'transaction-prepare') {
                $this->assertSame($paper, Operation::paper(), 'Preparation has no implicit save.');
                $result->save();
            }
            if (str_ends_with($operation, 'transition') || str_ends_with($operation, 'fail') || $operation === 'transaction-prepare') {
                $this->assertNotSame($paper, Operation::paper());
            }
        } finally {
            $this->close();
        }
        $this->assertSame($paper, Operation::paper());
    }

    public static function lostReadProfiles(): array
    {
        $rows = [];
        foreach (['deposit-lock' => 'deposits', 'withdrawal-lock' => 'withdrawals', 'transaction-lock' => 'financial_transactions', 'deposit-transition' => 'deposits', 'withdrawal-transition' => 'withdrawals', 'transaction-fail' => 'financial_transactions', 'checkout-read' => 'deposit_checkout_attempts'] as $op => $table) {
            foreach (['commit', 'rollback'] as $finish) {
                $rows[$op.'-'.$finish] = [$op === 'checkout-read' ? 'deposit-transition' : $op, $table, $finish];
            }
        }

        return $rows;
    }

    #[DataProvider('lostReadProfiles')]
    public function test_loss_after_real_lock_or_checkout_read_refuses_before_status_save(string $operation, string $table, string $finish): void
    {
        $paper = Operation::paper();
        $db = DB::connection();
        $fired = false;
        $queries = [];
        DB::listen(function (QueryExecuted $event) use (&$fired, &$queries, $table, $finish, $db): void {
            $queries[] = $event->sql;
            if (! $fired && preg_match('/^select /i', $event->sql) && str_contains($event->sql, $table)) {
                $fired = true;
                $finish === 'commit' ? $db->getPdo()->commit() : $db->getPdo()->rollBack();
            }
        });
        $db->beginTransaction();
        try {
            $this->invoke($operation);
            $this->fail('Post-read physical loss was admitted.');
        } catch (FinancialException $e) {
            $this->assertSame('financial_state_transition_outside_transaction', $e->errorCode());
            $this->assertTrue($fired);
            $this->assertSame(1, $db->transactionLevel());
            $this->assertFalse($db->getPdo()->inTransaction());
            $this->assertCount($table === 'deposit_checkout_attempts' ? 2 : 1, $queries);
        } finally {
            $this->close();
        }
        $this->assertSame($paper, Operation::paper());
    }

    public function test_mark_failed_reloads_current_metadata_and_does_not_save_dirty_money_fields(): void
    {
        $this->transaction->amount = '9999.00';
        $this->transaction->metadata = ['stale' => true];
        DB::table('financial_transactions')->where('id', $this->transaction->id)->update(['metadata' => json_encode(['current' => true])]);
        $result = DB::transaction(fn () => $this->invoke('transaction-fail'));
        $this->assertNotSame($this->transaction, $result);
        $this->assertSame('1000.00', $result->fresh()->amount);
        $this->assertSame(['current' => true, 'failure_reason' => 'synthetic boundary failure'], $result->fresh()->metadata);
        $this->assertSame(TransactionStatus::Failed, $result->fresh()->status);
        $this->assertSame(TransactionStatus::Pending, $this->transaction->status);
    }

    public function test_stale_pending_object_cannot_mark_a_now_completed_transaction_failed(): void
    {
        DB::table('financial_transactions')->where('id', $this->transaction->id)->update(['status' => 'completed']);
        $paper = Operation::paper();
        try {
            DB::transaction(fn () => $this->invoke('transaction-fail'));
            $this->fail('Stale pending status authorized a completed-to-failed overwrite.');
        } catch (FinancialException $e) {
            $this->assertSame('transaction_invalid_status_transition', $e->errorCode());
        }
        $this->assertSame($paper, Operation::paper());
    }

    public static function writerProfiles(): array
    {
        return [['deposit-transition'], ['withdrawal-transition'], ['transaction-fail'], ['transaction-prepare']];
    }

    #[DataProvider('writerProfiles')]
    public function test_nested_rollback_preserves_root_then_a_new_nested_write_commits(string $operation): void
    {
        $paper = Operation::paper();
        DB::beginTransaction();
        DB::beginTransaction();
        $result = $this->invoke($operation);
        if ($operation === 'transaction-prepare') {
            $result->save();
        }
        $this->assertNotSame($paper, Operation::paper());
        DB::rollBack();
        $this->assertSame(1, DB::transactionLevel());
        $this->assertTrue(DB::connection()->getPdo()->inTransaction());
        $this->assertSame($paper, Operation::paper());
        // A rollback does not rewind an Eloquent object's in-memory attributes.
        $this->transaction->refresh();
        DB::beginTransaction();
        $result = $this->invoke($operation);
        if ($operation === 'transaction-prepare') {
            $result->save();
        }
        $changed = Operation::paper();
        DB::commit();
        $this->assertSame(1, DB::transactionLevel());
        $this->assertTrue(DB::connection()->getPdo()->inTransaction());
        DB::commit();
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->assertSame($changed, Operation::paper());
        $this->assertNotSame($paper, $changed);
    }

    public static function missingProfiles(): array
    {
        return [['unsaved'], ['soft-deleted'], ['missing']];
    }

    #[DataProvider('missingProfiles')]
    public function test_failure_writer_refuses_unsaved_or_unavailable_rows(string $profile): void
    {
        if ($profile === 'unsaved') {
            $this->transaction = new FinancialTransaction;
        } elseif ($profile === 'soft-deleted') {
            $this->transaction->delete();
        } else {
            DB::table('financial_transactions')->where('id', $this->transaction->id)->delete();
        }
        $paper = Operation::paper();
        try {
            DB::transaction(fn () => $this->invoke('transaction-fail'));
            $this->fail('An unavailable row authorized a lifecycle write.');
        } catch (FinancialException $exception) {
            $this->assertSame($profile === 'unsaved' ? 'financial_entity_not_persisted' : 'financial_entity_missing', $exception->errorCode());
        }
        $this->assertSame($paper, Operation::paper());
    }

    public function test_null_failure_reason_preserves_current_database_metadata(): void
    {
        DB::table('financial_transactions')->where('id', $this->transaction->id)->update(['metadata' => json_encode(['current' => true, 'failure_reason' => 'existing'])]);
        $result = DB::transaction(fn () => app(FinancialTransactionService::class)->markFailed($this->transaction));
        $this->assertSame(['current' => true, 'failure_reason' => 'existing'], $result->fresh()->metadata);
        $this->assertSame(TransactionStatus::Failed, $result->fresh()->status);
    }

    public function test_pure_advisory_validation_remains_available_without_owning_a_transaction(): void
    {
        $paper = Operation::paper();
        $states = app(FinancialStateTransitionService::class);
        $states->assertTransactionTransition($this->transaction, TransactionStatus::Failed);
        $this->assertFalse($states->insideTransaction());
        $this->assertSame($paper, Operation::paper());
    }
}
