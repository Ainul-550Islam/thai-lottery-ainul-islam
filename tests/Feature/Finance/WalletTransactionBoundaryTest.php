<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Exceptions\FinancialException;
use App\Models\Wallet;
use App\Services\Finance\WalletLockService;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Wallet\Conversion\ConversionTestCase;
use Tests\Support\WalletBoundaryOperation as Operation;

final class WalletTransactionBoundaryTest extends ConversionTestCase
{
    public static function invalidProfiles(): array
    {
        $rows = [];
        foreach (Operation::profiles() as [$op, $code]) {
            foreach (['commit', 'rollback', 'nested-commit', 'pdo-only'] as $state) {
                $rows[$op.'/'.$state] = [$op, $code, $state];
            }
            if ($code !== 'wallet_transaction_state_invalid') {
                $rows[$op.'/none'] = [$op, $code, 'none'];
            }
        }

        return $rows;
    }

    protected function fixtureWallet(): Wallet
    {
        $this->seed(LedgerAccountSeeder::class);
        $wallet = $this->makeFundedMember()['cash'];
        $wallet->forceFill(['locked_balance' => '200.00'])->save();

        return $wallet;
    }

    protected function divergentState(string $state): void
    {
        if ($state === 'none') {
            return;
        }
        if ($state === 'pdo-only') {
            DB::connection()->getPdo()->beginTransaction();

            return;
        }
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

    protected function cleanBoundary(): void
    {
        if (DB::connection()->getPdo()->inTransaction()) {
            DB::connection()->getPdo()->rollBack();
        }
        if (DB::transactionLevel() > 0) {
            DB::rollBack(0);
        }
    }

    #[DataProvider('invalidProfiles')]
    public function test_no_sql_or_money_effect_precedes_divergent_boundary_refusal(string $op, string $code, string $state): void
    {
        $wallet = $this->fixtureWallet();
        $before = Operation::paper();
        $this->divergentState($state);
        $level = DB::transactionLevel();
        $physical = DB::connection()->getPdo()->inTransaction();
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        $caught = null;
        try {
            $this->assertFalse(app(WalletLockService::class)->insideTransaction());
            try {
                Operation::run($op, $wallet);
            } catch (FinancialException $e) {
                $caught = $e->errorCode();
            }
            $this->assertSame($code, $caught, $op.'/'.$state);
            $this->assertSame([], DB::connection()->getQueryLog(), 'No SQL, savepoint or mutation before refusal.');
            $this->assertSame($level, DB::transactionLevel(), 'Do not repair the caller counter.');
            $this->assertSame($physical, DB::connection()->getPdo()->inTransaction(), 'Do not take ownership or reopen.');
        } finally {
            DB::connection()->disableQueryLog();
            $this->cleanBoundary();
        }
        $this->assertSame($before, Operation::paper());
    }

    public static function wrapperProfiles(): array
    {
        $rows = [];
        foreach (['commit', 'rollback', 'nested-commit', 'pdo-only'] as $state) {
            foreach ([true, false] as $savepoint) {
                $rows[$state.'/'.(int) $savepoint] = [$state, $savepoint];
            }
        }

        return $rows;
    }

    #[DataProvider('wrapperProfiles')]
    public function test_wrapper_never_runs_callback_or_repairs_divergence(string $state, bool $savepoint): void
    {
        $this->fixtureWallet();
        $this->divergentState($state);
        $level = DB::transactionLevel();
        $physical = DB::connection()->getPdo()->inTransaction();
        $ran = false;
        $caught = null;
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        try {
            try {
                app(WalletLockService::class)->withinTransaction(function () use (&$ran): void {
                    $ran = true;
                }, $savepoint);
            } catch (FinancialException $e) {
                $caught = $e->errorCode();
            }
            $this->assertSame('wallet_transaction_state_invalid', $caught);
            $this->assertFalse($ran);
            $this->assertSame([], DB::connection()->getQueryLog());
            $this->assertSame($level, DB::transactionLevel());
            $this->assertSame($physical, DB::connection()->getPdo()->inTransaction());
        } finally {
            DB::connection()->disableQueryLog();
            $this->cleanBoundary();
        }
    }

    public static function healthyProfiles(): array
    {
        $rows = [];
        foreach ([0, 1, 2] as $depth) {
            foreach ([true, false] as $savepoint) {
                foreach ([true, false] as $throw) {
                    $rows[$depth.'/'.(int) $savepoint.'/'.(int) $throw] = [$depth, $savepoint, $throw];
                }
            }
        }

        return $rows;
    }

    #[DataProvider('healthyProfiles')]
    public function test_healthy_root_savepoint_and_direct_join_keep_their_ownership(int $depth, bool $savepoint, bool $throw): void
    {
        $wallet = $this->fixtureWallet();
        $before = Operation::paper();
        for ($i = 0; $i < $depth; $i++) {
            DB::beginTransaction();
        }
        $expectedLevel = $depth === 0 ? 1 : $depth + (int) $savepoint;
        try {
            try {
                $result = app(WalletLockService::class)->withinTransaction(function () use ($wallet, $throw, $expectedLevel): string {
                    $this->assertSame($expectedLevel, DB::transactionLevel());
                    $this->assertTrue(app(WalletLockService::class)->insideTransaction());
                    DB::table('wallets')->where('id', $wallet->id)->update(['balance' => '970.00']);
                    if ($throw) {
                        throw new \DomainException('synthetic caller failure');
                    }

                    return 'caller-result';
                }, $savepoint);
                $this->assertFalse($throw);
                $this->assertSame('caller-result', $result);
            } catch (\DomainException $e) {
                $this->assertTrue($throw);
                $this->assertSame('synthetic caller failure', $e->getMessage());
            }
            $this->assertSame($depth, DB::transactionLevel());
            $this->assertSame($depth > 0, DB::connection()->getPdo()->inTransaction());
            $rolledBack = $throw && ($depth === 0 || $savepoint);
            $this->assertSame($rolledBack ? '1000.00' : '970.00', $wallet->fresh()->balance);
        } finally {
            if ($depth > 0) {
                DB::rollBack(0);
            }
        }
        if ($depth > 0 || $throw) {
            $this->assertSame($before, Operation::paper());
        }
    }

    public static function monetaryProfiles(): array
    {
        return array_map(fn ($p) => [$p], ['credit', 'debit', 'execute', 'hold', 'release', 'lock-funds', 'unlock-funds']);
    }

    #[DataProvider('monetaryProfiles')]
    public function test_real_wallet_mutations_and_postings_remain_inside_the_callers_rollback(string $op): void
    {
        $wallet = $this->fixtureWallet();
        $before = Operation::paper();
        DB::beginTransaction();
        try {
            Operation::run($op, $wallet);
            $this->assertSame(1, DB::transactionLevel());
            $this->assertTrue(DB::connection()->getPdo()->inTransaction());
            $this->assertNotSame($before, Operation::paper());
            $this->assertTrue($this->ledgerIsBalanced()['balanced']);
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, Operation::paper());
    }

    #[DataProvider('monetaryProfiles')]
    public function test_standalone_real_operations_commit_an_owned_root(string $op): void
    {
        $wallet = $this->fixtureWallet();
        $before = Operation::paper();
        Operation::run($op, $wallet);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->assertNotSame($before, Operation::paper());
        $this->assertTrue($this->ledgerIsBalanced()['balanced']);
    }

    public static function unmanagedProfiles(): array
    {
        return [['commit'], ['rollback']];
    }

    #[DataProvider('unmanagedProfiles')]
    public function test_raw_caller_writes_keep_the_callers_final_decision(string $finish): void
    {
        $wallet = $this->fixtureWallet();
        $pdo = DB::connection()->getPdo();
        $pdo->beginTransaction();
        try {
            DB::table('wallets')->where('id', $wallet->id)->update(['balance' => '1234.00']);
            try {
                app(WalletLockService::class)->withinTransaction(fn () => $this->fail('Must not enter caller work.'));
                $this->fail('Must reject raw PDO ownership.');
            } catch (FinancialException $e) {
                $this->assertSame('wallet_transaction_state_invalid', $e->errorCode());
            }
            $this->assertTrue($pdo->inTransaction());
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('1234.00', $wallet->fresh()->balance);
            if ($finish === 'commit') {
                $pdo->commit();
            } else {
                $pdo->rollBack();
            }
            $this->assertSame($finish === 'commit' ? '1234.00' : '1000.00', $wallet->fresh()->balance);
        } finally {
            $this->cleanBoundary();
        }
    }
}
