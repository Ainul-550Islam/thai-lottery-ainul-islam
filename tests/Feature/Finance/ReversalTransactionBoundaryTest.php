<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\FinancialTransactionType;
use App\Enums\TransactionStatus;
use App\Exceptions\FinancialException;
use App\Models\FinancialTransaction;
use App\Services\Finance\FinancialReversalService;
use App\Services\Finance\LedgerBalanceValidator;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Payment\PaymentTestCase;
use Tests\Support\ReversalBoundaryOperation as Operation;

final class ReversalTransactionBoundaryTest extends PaymentTestCase
{
    public static function invalidProfiles(): array
    {
        $rows = [];
        foreach (['wallet-credit', 'wallet-debit', 'system'] as $kind) {
            foreach (['reverse-new', 'reverse-replay', 'existing-empty', 'existing-replay'] as $mode) {
                foreach (['commit', 'rollback', 'nested-commit', 'pdo-only'] as $state) {
                    $rows[$kind.'/'.$mode.'/'.$state] = [$kind, $mode, $state];
                }
            }
        }

        return $rows;
    }

    private function cleanupBoundary(): void
    {
        if (DB::connection()->getPdo()->inTransaction()) {
            DB::connection()->getPdo()->rollBack();
        }
        if (DB::transactionLevel() > 0) {
            DB::rollBack(0);
        }
    }

    #[DataProvider('invalidProfiles')]
    public function test_reversal_and_verified_lookup_refuse_before_any_routing_sql(string $kind, string $mode, string $state): void
    {
        $f = Operation::fixture($kind, str_ends_with($mode, 'replay'));
        $before = Operation::paper();
        if ($state === 'pdo-only') {
            DB::connection()->getPdo()->beginTransaction();
        } else {
            DB::beginTransaction();
            if ($state === 'nested-commit') {
                DB::beginTransaction();
            }
            if ($state === 'rollback') {
                DB::connection()->getPdo()->rollBack();
            } else {
                DB::connection()->getPdo()->commit();
            }
        }
        $level = DB::transactionLevel();
        $physical = DB::connection()->getPdo()->inTransaction();
        $code = null;
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        try {
            try {
                Operation::run($mode, $f['original']);
            } catch (FinancialException $e) {
                $code = $e->errorCode();
            }
            $this->assertSame('wallet_transaction_state_invalid', $code);
            $this->assertSame([], DB::connection()->getQueryLog(), 'Routing SQL must not precede entry admission.');
            $this->assertSame($level, DB::transactionLevel());
            $this->assertSame($physical, DB::connection()->getPdo()->inTransaction());
        } finally {
            DB::connection()->disableQueryLog();
            $this->cleanupBoundary();
        }
        $this->assertSame($before, Operation::paper());
    }

    public static function routingLossProfiles(): array
    {
        $rows = [];
        foreach (['wallet-credit', 'system'] as $kind) {
            foreach (['reverse-new', 'reverse-replay', 'existing-empty', 'existing-replay'] as $mode) {
                foreach (['commit', 'rollback'] as $finish) {
                    foreach ([1, 2] as $depth) {
                        $rows[$kind.'/'.$mode.'/'.$finish.'/'.$depth] = [$kind, $mode, $finish, $depth];
                    }
                }
            }
        }

        return $rows;
    }

    #[DataProvider('routingLossProfiles')]
    public function test_physical_loss_during_the_actual_routing_query_is_rechecked_before_savepoint(string $kind, string $mode, string $finish, int $depth): void
    {
        $f = Operation::fixture($kind, str_ends_with($mode, 'replay'));
        $before = Operation::paper();
        for ($i = 0; $i < $depth; $i++) {
            DB::beginTransaction();
        }
        $armed = true;
        $triggered = false;
        DB::listen(function (QueryExecuted $event) use (&$armed, &$triggered, $finish): void {
            if ($armed && preg_match('/^select ["`]?wallet_id["`]? from ["`]?financial_transactions/i', $event->sql)) {
                $armed = false;
                $triggered = true;
                if ($finish === 'commit') {
                    $event->connection->getPdo()->commit();
                } else {
                    $event->connection->getPdo()->rollBack();
                }
            }
        });
        $code = null;
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        try {
            try {
                Operation::run($mode, $f['original']);
            } catch (FinancialException $e) {
                $code = $e->errorCode();
            }
            $this->assertTrue($triggered, 'Fault injection must follow a real routing SELECT.');
            $this->assertSame('wallet_transaction_state_invalid', $code);
            $queries = DB::connection()->getQueryLog();
            $this->assertCount(1, $queries, 'Only the admitted routing SELECT may execute, never a replacement savepoint.');
            $this->assertMatchesRegularExpression('/^select/i', $queries[0]['query']);
            $this->assertSame($depth, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        } finally {
            $armed = false;
            DB::connection()->disableQueryLog();
            $this->cleanupBoundary();
        }
        $this->assertSame($before, Operation::paper());
    }

    public static function healthyProfiles(): array
    {
        $rows = [];
        foreach (['wallet-credit', 'wallet-debit', 'system'] as $kind) {
            foreach (['reverse-new', 'reverse-replay', 'existing-empty', 'existing-replay'] as $mode) {
                foreach ([0, 1, 2] as $depth) {
                    $rows[$kind.'/'.$mode.'/'.$depth] = [$kind, $mode, $depth];
                }
            }
        }

        return $rows;
    }

    #[DataProvider('healthyProfiles')]
    public function test_owned_and_nested_reversal_and_proved_replay_keep_caller_ownership(string $kind, string $mode, int $depth): void
    {
        $f = Operation::fixture($kind, str_ends_with($mode, 'replay'));
        $before = Operation::paper();
        for ($i = 0; $i < $depth; $i++) {
            DB::beginTransaction();
        }
        try {
            $result = Operation::run($mode, $f['original']);
            $this->assertSame($depth, DB::transactionLevel());
            $this->assertSame($depth > 0, DB::connection()->getPdo()->inTransaction());
            if ($mode === 'existing-empty') {
                $this->assertNull($result);
                $this->assertSame($before, Operation::paper());
            } elseif ($mode !== 'reverse-new') {
                $this->assertSame($f['compensation']->id, $result->id);
                $this->assertSame($before, Operation::paper());
            } else {
                $this->assertSame(TransactionStatus::Completed, $result->status);
                $this->assertSame(TransactionStatus::Reversed, $f['original']->fresh()->status);
                $this->assertDatabaseCount('financial_transactions', 2);
                $this->assertDatabaseCount('ledger_entries', 4);
                $this->assertSame($kind === 'wallet-debit' ? '1100.00' : '900.00', $f['wallet']->fresh()->balance);
                $this->assertSame($result->id, app(FinancialReversalService::class)->existingReversalFor($f['original'])->id);
                $this->assertNull(app(LedgerBalanceValidator::class)->assertTransactionBalanced($result));
            }
        } finally {
            if ($depth > 0) {
                DB::rollBack(0);
            }
        }
        if ($depth > 0) {
            $this->assertSame($before, Operation::paper(), 'Caller rollback includes new money, original labels and all journal rows.');
        }
    }

    public static function unmanagedProfiles(): array
    {
        return [['commit'], ['rollback']];
    }

    #[DataProvider('unmanagedProfiles')]
    public function test_unmanaged_caller_pending_writes_keep_the_callers_final_decision(string $finish): void
    {
        $f = Operation::fixture('wallet-credit');
        $old = $f['user']->name;
        $pdo = DB::connection()->getPdo();
        $pdo->beginTransaction();
        try {
            DB::table('users')->where('id', $f['user']->id)->update(['name' => 'Synthetic pending caller']);
            try {
                Operation::run('reverse-new', $f['original']);
                $this->fail('Must reject unmanaged caller state.');
            } catch (FinancialException $e) {
                $this->assertSame('wallet_transaction_state_invalid', $e->errorCode());
            }
            $this->assertTrue($pdo->inTransaction());
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('Synthetic pending caller', $f['user']->fresh()->name);
            if ($finish === 'commit') {
                $pdo->commit();
            } else {
                $pdo->rollBack();
            }
            $this->assertSame($finish === 'commit' ? 'Synthetic pending caller' : $old, $f['user']->fresh()->name);
        } finally {
            $this->cleanupBoundary();
        }
    }

    public static function atomicProfiles(): array
    {
        return [['wallet-credit', 0], ['wallet-debit', 0], ['wallet-credit', 1], ['wallet-debit', 1]];
    }

    #[DataProvider('atomicProfiles')]
    public function test_post_journal_failure_rolls_back_new_money_but_not_unrelated_caller_work(string $kind, int $depth): void
    {
        $f = Operation::fixture($kind);
        $before = Operation::paper();
        $seenBalance = null;
        $seenEntries = null;
        $event = 'eloquent.updating: '.FinancialTransaction::class;
        $dispatcher = FinancialTransaction::getEventDispatcher();
        $dispatcher->listen($event, function ($row) use ($f, &$seenBalance, &$seenEntries): void {
            if ($row->type === FinancialTransactionType::Reversal->toTransactionType()
                && $row->status === TransactionStatus::Completed) {
                $seenBalance = $f['wallet']->fresh()->balance;
                $seenEntries = DB::table('ledger_entries')->count();
                throw new \DomainException('Synthetic post-journal boundary failure');
            }
        });
        if ($depth > 0) {
            DB::beginTransaction();
            DB::table('users')->where('id', $f['user']->id)->update(['name' => 'Synthetic surviving caller']);
        }
        try {
            try {
                Operation::run('reverse-new', $f['original']);
                $this->fail('The real post-journal failure must propagate.');
            } catch (\DomainException $e) {
                $this->assertSame('Synthetic post-journal boundary failure', $e->getMessage());
            }
            $this->assertSame($kind === 'wallet-credit' ? '900.00' : '1100.00', $seenBalance);
            $this->assertSame(4, $seenEntries, 'The journal existed before the injected failure.');
            $this->assertSame($depth, DB::transactionLevel());
            $this->assertSame($depth > 0, DB::connection()->getPdo()->inTransaction());
            $this->assertSame($before, Operation::paper());
            if ($depth > 0) {
                $this->assertSame('Synthetic surviving caller', $f['user']->fresh()->name);
                DB::commit();
                $this->assertSame('Synthetic surviving caller', $f['user']->fresh()->name);
                $this->assertSame($before, Operation::paper());
            }
        } finally {
            $dispatcher->forget($event);
            if (DB::transactionLevel() > 0) {
                DB::rollBack(0);
            }
        }
    }
}
