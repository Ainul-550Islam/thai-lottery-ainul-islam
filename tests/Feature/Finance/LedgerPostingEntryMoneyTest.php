<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\Currency;
use App\Exceptions\FinancialException;
use App\Models\User;
use App\Services\Finance\LedgerBalanceValidator;
use App\Services\Finance\LedgerPostingService;
use App\Services\Finance\Money;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/** Synthetic disposable books; not funding, settlement or production repair. */
final class LedgerPostingEntryMoneyTest extends FinancialMasterTestCase
{
    public static function invalidDrafts(): array
    {
        $cases = [];
        foreach (['amount-overflow', 'amount-precision', 'snapshot-type', 'snapshot-overflow', 'snapshot-precision'] as $kind) {
            foreach ([Currency::THB, Currency::BDT] as $currency) {
                foreach ([false, true] as $savepoint) {
                    $cases[$kind.'-'.$currency->value.'-'.($savepoint ? 'savepoint' : 'root')] = [$kind, $currency, $savepoint];
                }
            }
        }

        return $cases;
    }

    private static function entries(array $pairs, string $kind, Currency $currency): array
    {
        if ($kind === 'amount-overflow' || $kind === 'amount-precision') {
            return [
                $pairs[0][0],
                array_replace($pairs[1][0], ['amount' => $kind === 'amount-overflow' ? '1000000000000000000.00' : Money::of('0.009', $currency, 3)]),
                array_replace($pairs[0][1], ['amount' => $kind === 'amount-overflow' ? '1000000000000000010.00' : Money::of('10.009', $currency, 3)]),
            ];
        }

        return $pairs[0];
    }

    #[DataProvider('invalidDrafts')]
    public function test_every_entry_money_field_is_preflighted_before_writes_and_caller_can_commit(string $kind, Currency $currency, bool $savepoint): void
    {
        [$tx, $pairs] = LedgerPostingCurrentReadTest::bookFixture();
        DB::table('financial_transactions')->where('id', $tx->id)->update(['currency' => $currency->value]);
        DB::table('ledger_accounts')->whereIn('id', array_column(array_merge(...$pairs), 'ledger_account_id'))->update(['currency' => $currency->value]);
        $tx->refresh();
        $draft = self::entries($pairs, $kind, $currency);
        $overrides = match ($kind) {
            'snapshot-type' => [1 => 1.25],
            'snapshot-overflow' => [1 => '10000000000000000000000.00'],
            'snapshot-precision' => [1 => '0.009'],
            default => [],
        };
        $paper = LedgerPostingCurrentReadTest::book();
        $attributes = $tx->getAttributes();
        $marker = User::factory()->create();
        DB::beginTransaction();
        DB::table('users')->where('id', $marker->id)->update(['name' => 'entry money refusal caller commit']);
        if ($savepoint) {
            DB::beginTransaction();
        }
        $error = null;
        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();
        try {
            app(LedgerPostingService::class)->post($tx, $draft, $overrides);
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
        $diagnostic = json_encode(['exception' => $error === null ? null : $error::class, 'exception_code' => $error?->getCode(), 'staged_journal_rows' => count($staged['ledger_entries']), 'committed_journal_rows' => DB::table('ledger_entries')->count(), 'caller_depth_before_commit' => $depth, 'physical_transaction_before_commit' => $physical, 'caller_marker' => $marker->fresh()->name], JSON_THROW_ON_ERROR);
        $field = str_starts_with($kind, 'amount-') ? 'amount' : 'balance_after';
        $range = str_ends_with($kind, '-overflow');
        $this->assertInstanceOf(FinancialException::class, $error, $diagnostic);
        $this->assertSame('ledger_entry_'.$field.'_'.($range ? 'out_of_range' : 'invalid'), $error->errorCode(), $diagnostic);
        $this->assertSame(1, $error->contextValue('entry_index'));
        $this->assertSame($field, $error->contextValue('field'));
        $this->assertSame($currency->value, $error->contextValue('currency'));
        $this->assertSame($savepoint ? 2 : 1, $depth);
        $this->assertTrue($physical);
        $this->assertSame($attributes, $tx->getAttributes());
        $this->assertSame($paper, $staged, $diagnostic);
        $this->assertSame($paper, LedgerPostingCurrentReadTest::book(), $diagnostic);
        $this->assertSame('entry money refusal caller commit', $marker->fresh()->name);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete)\b/i', $query['query'], $diagnostic);
        }
    }

    public static function validForms(): array
    {
        return [[Currency::THB, false], [Currency::THB, true], [Currency::BDT, false], [Currency::BDT, true]];
    }

    #[DataProvider('validForms')]
    public function test_lossless_money_objects_and_snapshots_keep_null_and_currency_semantics(Currency $currency, bool $explicit): void
    {
        [$tx, $pairs] = LedgerPostingCurrentReadTest::bookFixture();
        DB::table('financial_transactions')->where('id', $tx->id)->update(['currency' => $currency->value]);
        DB::table('ledger_accounts')->whereIn('id', array_column(array_merge(...$pairs), 'ledger_account_id'))->update(['currency' => $currency->value]);
        $tx->refresh();
        $money = Money::of('10.0000', $currency, 4);
        $draft = [array_replace($pairs[0][0], ['amount' => $money]), $pairs[0][1]];
        $entries = DB::transaction(fn () => app(LedgerPostingService::class)->post($tx, $draft, [null, ' +000123.4500 ', 40 => ['ignored unused value']], $explicit));
        $this->assertCount(2, $entries);
        $this->assertSame('10.0000', $money->toString());
        $this->assertSame($explicit ? null : '10.00', $entries[0]->balance_after);
        $this->assertSame('123.45', $entries[1]->balance_after);
        foreach ($entries as $entry) {
            $this->assertSame('10.00', $entry->amount);
            $this->assertSame($currency, $entry->currency);
            $this->assertSame(0, bccomp('10.00', (string) DB::table('ledger_accounts')->where('id', $entry->ledger_account_id)->value('current_balance'), 2));
        }
        app(LedgerBalanceValidator::class)->assertTransactionBalanced($tx->fresh());
    }

    public function test_native_store_preserves_exact_amount_and_signed_snapshot_column_boundaries(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Exact DECIMAL(20,2) amount and DECIMAL(24,2) snapshot persistence requires the native store.');
        }
        [$tx, $pairs] = LedgerPostingCurrentReadTest::bookFixture();
        $maximum = '999999999999999999.99';
        DB::table('financial_transactions')->where('id', $tx->id)->update(['amount' => $maximum]);
        $tx->refresh();
        $draft = array_map(fn ($entry) => array_replace($entry, ['amount' => $maximum]), $pairs[0]);
        $snapshots = ['9999999999999999999999.99', '-9999999999999999999999.99'];
        $entries = DB::transaction(fn () => app(LedgerPostingService::class)->post($tx, $draft, $snapshots));
        foreach ($entries as $index => $entry) {
            $this->assertSame($maximum, $entry->fresh()->amount);
            $this->assertSame($snapshots[$index], $entry->fresh()->balance_after);
            $this->assertSame($maximum, (string) DB::table('ledger_accounts')->where('id', $entry->ledger_account_id)->value('current_balance'));
        }
        app(LedgerBalanceValidator::class)->assertTransactionBalanced($tx->fresh());
    }
}
