<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\Currency;
use App\Enums\LedgerAccountType;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Exceptions\FinancialException;
use App\Models\FinancialTransaction;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\Finance\LedgerBalanceValidator;
use App\Services\Finance\LedgerPostingService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

final class LedgerPostingCurrentReadTest extends FinancialMasterTestCase
{
    /** Synthetic walletless adjustment journals, never external funding. */
    public static function bookFixture(): array
    {
        $tx = FinancialTransaction::factory()->create(['user_id' => null, 'wallet_id' => null, 'type' => TransactionType::Adjustment, 'amount' => '10.00', 'description' => 'Persisted canonical journal']);
        $pairs = [];
        foreach (range(1, 3) as $unused) {
            $asset = LedgerAccount::factory()->create(['type' => LedgerAccountType::Asset]);
            $liability = LedgerAccount::factory()->create(['type' => LedgerAccountType::Liability]);
            $pairs[] = [['ledger_account_id' => $asset->id, 'type' => 'debit', 'amount' => '10.00'], ['ledger_account_id' => $liability->id, 'type' => 'credit', 'amount' => '10.00']];
        }

        return [$tx, $pairs];
    }

    public static function book(): array
    {
        $paper = [];
        foreach (['financial_transactions', 'ledger_entries', 'ledger_accounts', 'wallets'] as $table) {
            $paper[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }

        return $paper;
    }

    public static function terminalStates(): array
    {
        $rows = [];
        foreach (['completed', 'failed', 'cancelled', 'reversed'] as $persisted) {
            foreach (['pending', 'processing'] as $memory) {
                $rows[$persisted.'-'.$memory] = [$persisted, $memory];
            }
        }

        return $rows;
    }

    #[DataProvider('terminalStates')]
    public function test_current_terminal_state_refuses_a_stale_postable_model_and_caller_can_commit(string $persisted, string $memory): void
    {
        [$tx, $pairs] = self::bookFixture();
        $tx->status = TransactionStatus::from($memory);
        DB::table('financial_transactions')->where('id', $tx->id)->update(['status' => $persisted]);
        $attributes = $tx->getAttributes();
        $marker = User::factory()->create();
        DB::beginTransaction();
        DB::table('users')->where('id', $marker->id)->update(['name' => 'current journal caller commit']);
        $paper = self::book();
        try {
            app(LedgerPostingService::class)->post($tx, $pairs[0]);
            $this->fail('A stale label authorized a journal for a terminal transaction.');
        } catch (FinancialException $e) {
            $this->assertSame('ledger_posting_invalid_transaction_status', $e->errorCode());
            $this->assertSame(1, DB::transactionLevel());
            $this->assertTrue(DB::connection()->getPdo()->inTransaction());
            $this->assertSame($attributes, $tx->getAttributes());
        } finally {
            DB::commit();
        }
        $this->assertSame($paper, self::book());
        $this->assertSame('current journal caller commit', $marker->fresh()->name);
    }

    public static function staleFields(): array
    {
        return [['currency'], ['wallet'], ['reference'], ['status']];
    }

    #[DataProvider('staleFields')]
    public function test_posting_uses_locked_persisted_defaults_without_mutating_supplied_model(string $field): void
    {
        [$tx, $pairs] = self::bookFixture();
        match ($field) {
            'currency' => $tx->currency = Currency::BDT,
            'wallet' => $tx->wallet_id = 999999,
            'reference' => [$tx->reference_type = 'foreign-reference', $tx->reference_id = 999999, $tx->description = 'stale description'],
            'status' => $tx->status = TransactionStatus::Failed,
        };
        $attributes = $tx->getAttributes();
        $paper = self::book();
        DB::beginTransaction();
        $entries = app(LedgerPostingService::class)->post($tx, $pairs[0]);
        $this->assertCount(2, $entries);
        foreach ($entries as $entry) {
            $this->assertSame(Currency::THB, $entry->currency);
            $this->assertNull($entry->wallet_id);
            $this->assertNull($entry->reference_type);
            $this->assertNull($entry->reference_id);
            $this->assertSame('Persisted canonical journal', $entry->description);
        }
        $this->assertSame($attributes, $tx->getAttributes());
        app(LedgerBalanceValidator::class)->assertTransactionBalanced($tx->fresh());
        DB::rollBack();
        $this->assertSame($paper, self::book());
    }

    public static function missingRows(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('missingRows')]
    public function test_missing_or_deleted_persisted_transaction_refuses_before_account_changes(bool $soft): void
    {
        [$tx, $pairs] = self::bookFixture();
        $query = DB::table('financial_transactions')->where('id', $tx->id);
        $soft ? $query->update(['deleted_at' => now()]) : $query->delete();
        $paper = self::book();
        DB::beginTransaction();
        try {
            app(LedgerPostingService::class)->post($tx, $pairs[0]);
            $this->fail('A missing transaction was adopted.');
        } catch (FinancialException $e) {
            $this->assertSame('ledger_posting_transaction_missing', $e->errorCode());
        } finally {
            DB::commit();
        }
        $this->assertSame($paper, self::book());
    }

    #[DataProvider('missingRows')]
    public function test_existing_posting_refuses_disjoint_account_set_even_when_rows_are_tombstoned(bool $tombstone): void
    {
        [$tx, $pairs] = self::bookFixture();
        DB::transaction(fn () => app(LedgerPostingService::class)->post($tx, $pairs[0]));
        if ($tombstone) {
            // Contradictory historical fixture, not an authorized delete/repair API.
            DB::table('ledger_entries')->update(['deleted_at' => now()]);
        }
        $paper = self::book();
        DB::beginTransaction();
        try {
            app(LedgerPostingService::class)->post($tx, $pairs[1]);
            $this->fail('A different account set bypassed once-only posting.');
        } catch (FinancialException $e) {
            $this->assertSame('ledger_duplicate_posting', $e->errorCode());
        } finally {
            DB::commit();
        }
        $this->assertSame($paper, self::book());
    }

    public function test_transaction_root_is_read_before_accounts_and_caller_savepoint_rollback_owns_all_writes(): void
    {
        [$tx, $pairs] = self::bookFixture();
        $paper = self::book();
        DB::beginTransaction();
        DB::beginTransaction();
        $db = DB::connection();
        $db->enableQueryLog();
        $db->flushQueryLog();
        app(LedgerPostingService::class)->post($tx, $pairs[0]);
        $queries = array_column($db->getQueryLog(), 'query');
        $db->disableQueryLog();
        $this->assertStringContainsString('financial_transactions', $queries[0]);
        $this->assertStringContainsString('ledger_accounts', $queries[1]);
        $this->assertStringContainsString('ledger_entries', $queries[2]);
        $this->assertSame(2, DB::transactionLevel());
        DB::rollBack();
        $this->assertSame($paper, self::book());
        $this->assertSame(1, DB::transactionLevel());
        DB::commit();
    }
}
