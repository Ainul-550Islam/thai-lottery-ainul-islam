<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\Currency;
use App\Enums\LedgerAccountType;
use App\Exceptions\FinancialException;
use App\Models\User;
use App\Services\Finance\LedgerBalanceValidator;
use App\Services\Finance\LedgerPostingService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/** Disposable synthetic accounts, not provider funding or historical repair. */
final class LedgerPostingBalanceProjectionTest extends FinancialMasterTestCase
{
    private const MAX = '9999999999999999999999.99';

    /** SQLite has no exact DECIMAL(24,2); preserve exact refusal-fixture bytes. */
    private static function setStartingBalance(int $id, string $balance, LedgerAccountType $type): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::update('UPDATE ledger_accounts SET current_balance = CAST(? AS BLOB), type = ? WHERE id = ?', [$balance, $type->value, $id]);
        } else {
            DB::table('ledger_accounts')->where('id', $id)->update(['current_balance' => $balance, 'type' => $type->value]);
        }
    }

    public static function overflowCases(): array
    {
        $cases = [];
        foreach ([false, true] as $negative) {
            foreach ([0, 1] as $index) {
                foreach ([false, true] as $savepoint) {
                    foreach ([Currency::THB, Currency::BDT] as $currency) {
                        $cases[($negative ? 'lower' : 'upper').'-line'.$index.'-'.($savepoint ? 'savepoint' : 'root').'-'.$currency->value] = [$negative, $index, $savepoint, $currency];
                    }
                }
            }
        }

        return $cases;
    }

    #[DataProvider('overflowCases')]
    public function test_all_projected_balances_are_refused_before_any_write_and_caller_can_commit(bool $negative, int $index, bool $savepoint, Currency $currency): void
    {
        [$tx, $pairs] = LedgerPostingCurrentReadTest::bookFixture();
        DB::table('financial_transactions')->where('id', $tx->id)->update(['currency' => $currency->value]);
        DB::table('ledger_accounts')->whereIn('id', array_column(array_merge(...$pairs), 'ledger_account_id'))->update(['currency' => $currency->value]);
        $tx->refresh();
        $id = $pairs[0][$index]['ledger_account_id'];
        $type = ($index === 0) === $negative ? LedgerAccountType::Liability : LedgerAccountType::Asset;
        self::setStartingBalance($id, ($negative ? '-' : '').self::MAX, $type);
        $paper = LedgerPostingCurrentReadTest::book();
        $attributes = $tx->getAttributes();
        $marker = User::factory()->create();
        DB::beginTransaction();
        DB::table('users')->where('id', $marker->id)->update(['name' => 'projection refusal caller commit']);
        if ($savepoint) {
            DB::beginTransaction();
        }
        $error = null;
        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();
        try {
            app(LedgerPostingService::class)->post($tx, $pairs[0]);
        } catch (Throwable $caught) {
            $error = $caught;
        }
        $queries = DB::connection()->getQueryLog();
        DB::connection()->disableQueryLog();
        $depth = DB::transactionLevel();
        $physical = DB::connection()->getPdo()->inTransaction();
        $staged = LedgerPostingCurrentReadTest::book();
        if ($savepoint) {
            DB::rollBack();
        }
        DB::commit();
        $diagnostic = json_encode(['exception' => $error === null ? null : $error::class, 'staged_journal_rows' => count($staged['ledger_entries']), 'committed_journal_rows' => DB::table('ledger_entries')->count(), 'caller_depth_before_commit' => $depth, 'physical_transaction_before_commit' => $physical, 'caller_marker' => $marker->fresh()->name], JSON_THROW_ON_ERROR);

        $this->assertInstanceOf(FinancialException::class, $error, $diagnostic);
        $this->assertSame('ledger_account_balance_out_of_range', $error->errorCode(), $diagnostic);
        $this->assertSame(['ledger_account_id' => $id, 'entry_index' => $index, 'currency' => $currency->value, 'balance_phase' => 'projected'], $error->context(), $diagnostic);
        $this->assertSame($savepoint ? 2 : 1, $depth);
        $this->assertTrue($physical);
        $this->assertSame($attributes, $tx->getAttributes());
        $this->assertSame($paper, $staged, $diagnostic);
        $this->assertSame($paper, LedgerPostingCurrentReadTest::book(), $diagnostic);
        $this->assertSame('projection refusal caller commit', $marker->fresh()->name);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete)\b/i', $query['query'], $diagnostic);
        }
    }

    public static function validCarries(): array
    {
        $cases = [];
        foreach (LedgerAccountType::cases() as $type) {
            foreach ([false, true] as $savepoint) {
                $cases[$type->value.'-'.($savepoint ? 'savepoint' : 'root')] = [$type, $savepoint];
            }
        }

        return $cases;
    }

    #[DataProvider('validCarries')]
    public function test_writes_use_each_precomputed_intermediate_and_preserve_caller_rollback(LedgerAccountType $type, bool $savepoint): void
    {
        [$tx, $pairs] = LedgerPostingCurrentReadTest::bookFixture();
        $id = $pairs[0][0]['ledger_account_id'];
        DB::table('financial_transactions')->where('id', $tx->id)->update(['amount' => '7.25']);
        DB::table('ledger_accounts')->where('id', $id)->update(['type' => $type->value, 'current_balance' => '-2.50']);
        $tx->refresh();
        $draft = [['ledger_account_id' => $id, 'type' => 'debit', 'amount' => '7.25'], ['ledger_account_id' => $id, 'type' => 'credit', 'amount' => '7.25']];
        $paper = LedgerPostingCurrentReadTest::book();
        DB::beginTransaction();
        if ($savepoint) {
            DB::beginTransaction();
        }
        try {
            $entries = app(LedgerPostingService::class)->post($tx, $draft);
            $snapshots = array_map(fn ($entry) => $entry->balance_after, $entries);
            $balance = (string) DB::table('ledger_accounts')->where('id', $id)->value('current_balance');
            $depth = DB::transactionLevel();
            app(LedgerBalanceValidator::class)->assertTransactionBalanced($tx->fresh());
        } finally {
            if ($savepoint) {
                DB::rollBack();
            }
            DB::commit();
        }
        $this->assertCount(2, $entries);
        $this->assertSame([$type->isDebitNormal() ? '4.75' : '-9.75', '-2.50'], $snapshots);
        $this->assertSame(0, bccomp('-2.50', $balance, 2));
        $this->assertSame($savepoint ? 2 : 1, $depth);
        $this->assertSame(0, DB::transactionLevel());
        if ($savepoint) {
            $this->assertSame($paper, LedgerPostingCurrentReadTest::book());
        } else {
            $this->assertSame(2, DB::table('ledger_entries')->where('financial_transaction_id', $tx->id)->count());
        }
    }

    public static function snapshotModes(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('snapshotModes')]
    public function test_wallet_snapshot_overrides_keep_legacy_and_explicit_null_semantics(bool $explicit): void
    {
        [$tx, $pairs] = LedgerPostingCurrentReadTest::bookFixture();
        $entries = DB::transaction(fn () => app(LedgerPostingService::class)->post($tx, $pairs[0], [null, '123.45'], $explicit));
        $this->assertSame($explicit ? null : '10.00', $entries[0]->balance_after);
        $this->assertSame('123.45', $entries[1]->balance_after);
        foreach ($entries as $entry) {
            $this->assertSame(0, bccomp('10.00', (string) DB::table('ledger_accounts')->where('id', $entry->ledger_account_id)->value('current_balance'), 2));
        }
        app(LedgerBalanceValidator::class)->assertTransactionBalanced($tx->fresh());
    }

    public static function nativeBoundaries(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('nativeBoundaries')]
    public function test_native_decimal_store_commits_exact_inclusive_signed_boundary(bool $negative): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Exact 22-integer-digit DECIMAL persistence requires the native database, not SQLite numeric affinity.');
        }
        [$tx, $pairs] = LedgerPostingCurrentReadTest::bookFixture();
        $id = $pairs[0][0]['ledger_account_id'];
        self::setStartingBalance($id, ($negative ? '-' : '').'9999999999999999999989.99', $negative ? LedgerAccountType::Liability : LedgerAccountType::Asset);
        $entries = DB::transaction(fn () => app(LedgerPostingService::class)->post($tx, $pairs[0]));
        $expected = ($negative ? '-' : '').self::MAX;
        $this->assertSame($expected, (string) DB::table('ledger_accounts')->where('id', $id)->value('current_balance'));
        $this->assertSame($expected, $entries[0]->balance_after);
        $this->assertSame($expected, $entries[0]->fresh()->balance_after);
        $this->assertCount(2, $entries);
        app(LedgerBalanceValidator::class)->assertTransactionBalanced($tx->fresh());
    }
}
