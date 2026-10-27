<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\Currency;
use App\Enums\LedgerAccountType;
use App\Enums\TransactionType;
use App\Exceptions\FinancialException;
use App\Models\FinancialTransaction;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\Finance\LedgerBalanceValidator;
use App\Services\Finance\LedgerPostingService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

final class LedgerPostingAdmissionTest extends FinancialMasterTestCase
{
    private function fixture(): array
    {
        $transaction = FinancialTransaction::factory()->create(['user_id' => null, 'wallet_id' => null, 'type' => TransactionType::Adjustment, 'amount' => '10.00']);
        $asset = LedgerAccount::factory()->create(['type' => LedgerAccountType::Asset]);
        $liability = LedgerAccount::factory()->create(['type' => LedgerAccountType::Liability]);
        $entries = [['ledger_account_id' => $asset->id, 'type' => 'debit', 'amount' => '10.00'], ['ledger_account_id' => $liability->id, 'type' => 'credit', 'amount' => '10.00']];

        return [$transaction, $entries, $asset, $liability];
    }

    private function book(): array
    {
        $result = [];
        foreach (['financial_transactions', 'ledger_entries', 'ledger_accounts', 'wallets'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $result;
    }

    public static function invalidAddresses(): array
    {
        $rows = [];
        foreach (['post', 'has-posting', 'posted-entries', 'balance-proof'] as $route) {
            foreach (['leading-zero', 'suffix', 'exponent', 'zero', 'negative', 'foreign-name', 'wrong-table', 'wrong-key'] as $address) {
                $rows[$route.'-'.$address] = [$route, $address];
            }
        }

        return $rows;
    }

    #[DataProvider('invalidAddresses')]
    public function test_invalid_model_address_refuses_without_sql_or_journal_side_effects_and_caller_commits(string $route, string $address): void
    {
        [$transaction, $entries] = $this->fixture();
        $id = $transaction->id;
        match ($address) {
            'leading-zero' => $transaction->id = '0'.$id,
            'suffix' => $transaction->id = $id.'junk',
            'exponent' => $transaction->id = $id.'e0',
            'zero' => $transaction->id = 0,
            'negative' => $transaction->id = -$id,
            'foreign-name' => $transaction->setConnection('ledger_unconfigured_foreign'),
            'wrong-table' => $transaction->setTable('foreign_financial_transactions'),
            'wrong-key' => $transaction->setRawAttributes($transaction->getAttributes() + ['foreign_key' => $id])->setKeyName('foreign_key'),
        };
        $memory = $transaction->getAttributes();
        $marker = User::factory()->create();
        $db = DB::connection();
        $db->beginTransaction();
        DB::table('users')->where('id', $marker->id)->update(['name' => 'ledger admission caller commit']);
        $paper = $this->book();
        $pdo = $db->getPdo();
        $db->enableQueryLog();
        $db->flushQueryLog();
        try {
            $posting = app(LedgerPostingService::class);
            match ($route) {
                'post' => $posting->post($transaction, $entries),
                'has-posting' => $posting->hasPosting($transaction),
                'posted-entries' => $posting->postedEntriesFor($transaction),
                'balance-proof' => app(LedgerBalanceValidator::class)->assertTransactionBalanced($transaction),
            };
            $this->fail('An invalid ledger model address was adopted.');
        } catch (FinancialException $exception) {
            $expected = match ($address) {
                'foreign-name' => 'financial_entity_connection_invalid',
                'wrong-table', 'wrong-key' => 'financial_entity_routing_invalid',
                default => $route === 'post' ? 'ledger_posting_unpersisted_transaction' : 'financial_entity_not_persisted',
            };
            $this->assertSame($expected, $exception->errorCode());
            $this->assertSame([], $db->getQueryLog());
            $this->assertSame($pdo, $db->getPdo());
            $this->assertTrue($pdo->inTransaction());
            $this->assertSame(1, $db->transactionLevel());
            $this->assertSame($memory, $transaction->getAttributes());
            $this->assertArrayNotHasKey('ledger_unconfigured_foreign', DB::getConnections());
        } finally {
            $db->disableQueryLog();
            $db->commit();
        }
        $this->assertSame($paper, $this->book());
        $this->assertSame('ledger admission caller commit', $marker->fresh()->name);
    }

    public static function invalidBoundaries(): array
    {
        return [['raw-commit'], ['raw-rollback'], ['unmanaged'], ['missing']];
    }

    #[DataProvider('invalidBoundaries')]
    public function test_posting_refuses_a_framework_counter_without_its_owned_physical_transaction(string $boundary): void
    {
        [$transaction, $entries] = $this->fixture();
        $paper = $this->book();
        $db = DB::connection();
        $pdo = $db->getPdo();
        if (in_array($boundary, ['raw-commit', 'raw-rollback'], true)) {
            $db->beginTransaction();
            $boundary === 'raw-commit' ? $pdo->commit() : $pdo->rollBack();
        } elseif ($boundary === 'unmanaged') {
            $pdo->beginTransaction();
        }
        $level = $db->transactionLevel();
        $physical = $pdo->inTransaction();
        $db->enableQueryLog();
        $db->flushQueryLog();
        try {
            app(LedgerPostingService::class)->post($transaction, $entries);
            $this->fail('A posting escaped the physical transaction admission gate.');
        } catch (FinancialException $exception) {
            $this->assertSame($level > 0 ? 'wallet_transaction_state_invalid' : 'ledger_posting_outside_transaction', $exception->errorCode());
            $this->assertSame([], $db->getQueryLog());
            $this->assertSame($level, $db->transactionLevel());
            $this->assertSame($physical, $pdo->inTransaction());
            $this->assertSame($pdo, $db->getPdo());
        } finally {
            $db->disableQueryLog();
            // Only fixture teardown resolves the boundary it deliberately broke.
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $db->rollBack(0);
        }
        $this->assertSame($paper, $this->book());
    }

    public static function validContexts(): array
    {
        return [[false, false], [false, true], [true, false], [true, true]];
    }

    #[DataProvider('validContexts')]
    public function test_canonical_posting_keeps_accounting_duplicate_refusal_and_caller_rollback(bool $explicit, bool $nested): void
    {
        [$transaction, $entries, $asset, $liability] = $this->fixture();
        $paper = $this->book();
        $transaction->setConnection($explicit ? DB::connection()->getName() : null);
        $transaction->id = (string) $transaction->id;
        DB::beginTransaction();
        if ($nested) {
            DB::beginTransaction();
        }
        $level = DB::transactionLevel();
        $posting = app(LedgerPostingService::class);
        $this->assertCount(2, $posting->post($transaction, $entries));
        $this->assertTrue($posting->hasPosting($transaction));
        $this->assertCount(2, $posting->postedEntriesFor($transaction));
        app(LedgerBalanceValidator::class)->assertTransactionBalanced($transaction);
        $this->assertSame('10.00', $asset->fresh()->current_balance);
        $this->assertSame('10.00', $liability->fresh()->current_balance);
        try {
            $posting->post($transaction, $entries);
            $this->fail('Duplicate posting was adopted.');
        } catch (FinancialException $exception) {
            $this->assertSame('ledger_duplicate_posting', $exception->errorCode());
        }
        $this->assertSame(2, DB::table('ledger_entries')->count());
        $this->assertSame($level, DB::transactionLevel());
        DB::rollBack();
        $this->assertSame($paper, $this->book());
        if ($nested) {
            $this->assertSame(1, DB::transactionLevel());
            DB::commit();
        }
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_transaction_loaded_from_foreign_store_cannot_post_to_colliding_local_transaction(): void
    {
        [$local, $entries] = $this->fixture();
        config(['database.connections.ledger_foreign_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $foreign = DB::connection('ledger_foreign_fixture');
        $foreign->statement('CREATE TABLE financial_transactions (id INTEGER PRIMARY KEY, amount TEXT, currency TEXT, status TEXT, deleted_at TEXT)');
        $foreign->table('financial_transactions')->insert(['id' => $local->id, 'amount' => '99.00', 'currency' => Currency::THB->value, 'status' => 'pending']);
        $model = FinancialTransaction::on('ledger_foreign_fixture')->findOrFail($local->id);
        $paper = $this->book();
        DB::beginTransaction();
        try {
            app(LedgerPostingService::class)->post($model, $entries);
            $this->fail('A foreign transaction was posted to the default book.');
        } catch (FinancialException $exception) {
            $this->assertSame('financial_entity_connection_invalid', $exception->errorCode());
        } finally {
            DB::commit();
        }
        $this->assertSame($paper, $this->book());
        $this->assertSame('99.00', $foreign->table('financial_transactions')->value('amount'));
        $this->assertSame(0, DB::transactionLevel());
        DB::purge('ledger_foreign_fixture');
    }
}
