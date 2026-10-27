<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\LedgerEntryType;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\FinancialTransaction;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Finance\FinancialRecordIdentifier;
use App\Services\Finance\FinancialReversalService;
use App\Services\Finance\FinancialStateTransitionService;
use App\Services\Finance\FinancialTransactionService;
use App\Services\Finance\LedgerBalanceValidator;
use App\Services\Finance\Money;
use App\Services\Finance\WalletHoldService;
use App\Services\Finance\WalletLockService;
use App\Services\Finance\WalletService;
use Illuminate\Database\Connection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LifecycleBoundaryOperation;

final class FinancialModelRoutingContextTest extends FinancialMasterTestCase
{
    public static function refusedRoutes(): array
    {
        $rows = [];
        foreach (['credit', 'debit', 'relock', 'reserve-funds', 'release-funds', 'freeze', 'unfreeze', 'apply-credit', 'apply-debit', 'reverse-credit', 'reverse-debit', 'hold', 'release', 'release-if-held', 'consume', 'user-wallet', 'deposit-lock', 'withdrawal-lock', 'transaction-lock', 'mark-failed', 'reverse'] as $route) {
            foreach (['unknown-connection', 'alternate-alias', 'altered-table', 'altered-key'] as $context) {
                $rows[$route.'-'.$context] = [$route, $context];
            }
        }

        return $rows;
    }

    #[DataProvider('refusedRoutes')]
    public function test_foreign_routing_context_refuses_before_sql_and_caller_can_commit(string $route, string $context): void
    {
        $wallet = $this->createPlayer('0.00')['wallet'];
        $this->creditFixture($wallet, '500.00', 'routing context funding');
        $transaction = FinancialTransaction::query()->sole();
        $fields = ['wallet_id' => $wallet->id, 'user_id' => $wallet->user_id];
        $deposit = Deposit::factory()->create($fields);
        $withdrawal = Withdrawal::factory()->create($fields);
        $user = $wallet->user;
        $models = [$wallet, $transaction, $deposit, $withdrawal, $user];
        $alias = 'financial_context_unconfigured';
        if ($context === 'alternate-alias') {
            $alias = 'financial_context_same_backend_alias';
            config(['database.connections.'.$alias => config('database.connections.'.config('database.default'))]);
        }
        foreach ($models as $model) {
            match ($context) {
                'unknown-connection', 'alternate-alias' => $model->setConnection($alias),
                'altered-table' => $model->setTable('foreign_'.$model->getTable()),
                'altered-key' => $model->setRawAttributes($model->getAttributes() + ['foreign_key' => $model->id])->setKeyName('foreign_key'),
            };
        }
        $memory = array_map(fn ($model) => $model->getAttributes(), $models);
        $wallets = app(WalletService::class);
        $locks = app(WalletLockService::class);
        $holds = app(WalletHoldService::class);
        $states = app(FinancialStateTransitionService::class);
        $amount = Money::of('10.00', Currency::THB);
        $db = DB::connection();
        $db->beginTransaction();
        DB::table('users')->where('id', $fields['user_id'])->update(['name' => 'committable unrelated caller marker']);
        $paper = LifecycleBoundaryOperation::paper();
        $pdo = $db->getPdo();
        $this->assertArrayNotHasKey($alias, DB::getConnections());
        $db->enableQueryLog();
        $db->flushQueryLog();
        try {
            match ($route) {
                'credit' => $wallets->credit($wallet, $amount, idempotencyKey: 'foreign-context-must-not-credit'),
                'debit' => $wallets->debit($wallet, $amount, idempotencyKey: 'foreign-context-must-not-debit'),
                'relock' => $locks->relock($wallet),
                'reserve-funds' => $wallets->lockFunds($wallet, $amount),
                'release-funds' => $wallets->unlockFunds($wallet, $amount),
                'freeze' => $wallets->lockWallet($wallet, 'foreign-context-must-not-freeze'),
                'unfreeze' => $wallets->unlockWallet($wallet),
                'apply-credit' => $wallets->applyCredit($wallet, $amount, FinancialTransactionType::Deposit),
                'apply-debit' => $wallets->applyDebit($wallet, $amount, FinancialTransactionType::Withdrawal),
                'reverse-credit' => $wallets->applyReversal($wallet, $amount, LedgerEntryType::Credit),
                'reverse-debit' => $wallets->applyReversal($wallet, $amount, LedgerEntryType::Debit),
                'hold' => $holds->hold($wallet, $amount),
                'release' => $holds->release($wallet, $amount),
                'release-if-held' => $holds->releaseIfHeld($wallet, $amount),
                'consume' => $holds->consume($wallet, $amount),
                'user-wallet' => $wallets->getOrCreateWallet($user),
                'deposit-lock' => $states->lockDeposit($deposit),
                'withdrawal-lock' => $states->lockWithdrawal($withdrawal),
                'transaction-lock' => $states->lockFinancialTransaction($transaction),
                'mark-failed' => app(FinancialTransactionService::class)->markFailed($transaction, 'foreign context'),
                'reverse' => app(FinancialReversalService::class)->reverse($transaction),
            };
            $this->fail('A foreign routing context was silently adopted.');
        } catch (FinancialException $exception) {
            $this->assertSame(in_array($context, ['altered-table', 'altered-key'], true) ? 'financial_entity_routing_invalid' : 'financial_entity_connection_invalid', $exception->errorCode());
            $this->assertSame([], $db->getQueryLog());
            $this->assertSame(1, $db->transactionLevel());
            $this->assertTrue($pdo->inTransaction());
            $this->assertSame($pdo, $db->getPdo());
            $this->assertSame($memory, array_map(fn ($model) => $model->getAttributes(), $models));
            $this->assertArrayNotHasKey($alias, DB::getConnections());
        } finally {
            $db->disableQueryLog();
            $db->commit();
        }
        $this->assertSame($paper, LifecycleBoundaryOperation::paper());
        $this->assertSame('committable unrelated caller marker', DB::table('users')->where('id', $fields['user_id'])->value('name'));
    }

    public static function canonicalContexts(): array
    {
        return [[false, false], [false, true], [true, false], [true, true]];
    }

    #[DataProvider('canonicalContexts')]
    public function test_default_and_explicit_writer_models_keep_canonical_accounting_and_caller_rollback(bool $explicit, bool $ambient): void
    {
        $wallet = $this->createPlayer('0.00')['wallet'];
        $this->creditFixture($wallet, '500.00', 'canonical context funding');
        $wallet->setConnection($explicit ? DB::connection()->getName() : null);
        $wallet->id = (string) $wallet->id;
        $before = LifecycleBoundaryOperation::paper();
        if ($ambient) {
            DB::beginTransaction();
        }
        $service = app(WalletService::class);
        $amount = Money::of('10.00', Currency::THB);
        $first = $service->credit($wallet, $amount, idempotencyKey: 'canonical-routing-credit');
        $this->assertSame($first->id, $service->credit($wallet, $amount, idempotencyKey: 'canonical-routing-credit')->id);
        $this->assertSame('510.00', $wallet->fresh()->balance);
        $service->lockFunds($wallet, $amount);
        $service->unlockFunds($wallet, $amount);
        $this->assertSame('0.00', $wallet->fresh()->locked_balance);
        foreach (FinancialTransaction::query()->get() as $row) {
            app(LedgerBalanceValidator::class)->assertTransactionBalanced($row);
        }
        $this->assertSame(2, FinancialTransaction::query()->count());
        $this->assertSame(4, DB::table('ledger_entries')->count());
        $this->assertSame($ambient ? 1 : 0, DB::transactionLevel());
        if ($ambient) {
            DB::rollBack(0);
            $this->assertSame($before, LifecycleBoundaryOperation::paper());
        }
    }

    public function test_matching_declared_name_does_not_authorize_a_different_connection_object(): void
    {
        $wallet = new class extends Wallet
        {
            public Connection $foreign;

            public function getConnection()
            {
                return $this->foreign;
            }
        };
        $wallet->setTable('wallets');
        $wallet->setRawAttributes(['id' => 1]);
        $wallet->setConnection(DB::connection()->getName());
        $wallet->foreign = new SQLiteConnection(new \PDO('sqlite::memory:'), ':memory:', '', ['name' => DB::connection()->getName()]);
        $this->expectException(FinancialException::class);
        $this->expectExceptionMessage('current canonical financial writer');
        FinancialRecordIdentifier::fromModel($wallet);
    }

    public function test_loaded_foreign_store_wallet_cannot_credit_colliding_local_id(): void
    {
        $local = $this->createPlayer('0.00')['wallet'];
        $this->creditFixture($local, '500.00', 'local canonical foreign-store collision funding');
        config(['database.connections.foreign_financial_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $foreign = DB::connection('foreign_financial_fixture');
        $foreign->statement('CREATE TABLE wallets (id INTEGER PRIMARY KEY, user_id INTEGER, currency TEXT, status TEXT, balance TEXT, deleted_at TEXT)');
        // Foreign observational sentinel, not a claimed canonical funding journal.
        $foreign->table('wallets')->insert(['id' => $local->id, 'user_id' => $local->user_id, 'currency' => 'THB', 'status' => 'active', 'balance' => '900.00']);
        $model = Wallet::on('foreign_financial_fixture')->findOrFail($local->id);
        $this->assertSame('900.00', $model->balance);
        $paper = LifecycleBoundaryOperation::paper();
        try {
            app(WalletService::class)->credit($model, Money::of('10.00', Currency::THB), idempotencyKey: 'foreign-store-must-not-credit');
            $this->fail('A foreign-store key silently credited the default store.');
        } catch (FinancialException $exception) {
            $this->assertSame('financial_entity_connection_invalid', $exception->errorCode());
        }
        $this->assertSame($paper, LifecycleBoundaryOperation::paper());
        $this->assertSame('500.00', $local->fresh()->balance);
        $this->assertSame('900.00', $foreign->table('wallets')->where('id', $local->id)->value('balance'));
        $this->assertSame(0, DB::transactionLevel());
        DB::purge('foreign_financial_fixture');
    }
}
