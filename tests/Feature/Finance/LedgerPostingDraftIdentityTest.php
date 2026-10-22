<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\Currency;
use App\Enums\LedgerEntryType;
use App\Exceptions\FinancialException;
use App\Models\User;
use App\Services\Finance\LedgerBalanceValidator;
use App\Services\Finance\LedgerPostingService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/** Disposable synthetic journals; no provider request or external money. */
final class LedgerPostingDraftIdentityTest extends FinancialMasterTestCase
{
    public static function repeatedDrafts(): array
    {
        $cases = [];
        foreach (['debit', 'credit'] as $side) {
            foreach ([false, true] as $savepoint) {
                foreach (['plain', 'enum', 'annotations', 'sparse-nonadjacent'] as $shape) {
                    $cases[$side.'-'.($savepoint ? 'savepoint' : 'root').'-'.$shape] = [$side, $savepoint, $shape];
                }
            }
        }

        return $cases;
    }

    private static function repeatedEntries(array $pair, string $side, string $shape): array
    {
        $line = $pair[$side === 'debit' ? 0 : 1];
        $other = $pair[$side === 'debit' ? 1 : 0];
        $first = array_replace($line, ['amount' => '4.00']);
        $second = array_replace($line, ['amount' => '6.00']);
        if ($shape === 'enum') {
            $first['type'] = LedgerEntryType::from($side);
        }
        if ($shape === 'annotations') {
            $first += ['description' => 'first annotation', 'reference_type' => 'first-reference', 'reference_id' => 11, 'metadata' => ['line' => 1]];
            $second += ['description' => 'second annotation', 'reference_type' => 'second-reference', 'reference_id' => 22, 'metadata' => ['line' => 2]];
        }
        if ($shape === 'sparse-nonadjacent') {
            return [9 => $first, 40 => $other, 400 => $second];
        }

        return $side === 'debit' ? [$first, $second, $other] : [$other, $first, $second];
    }

    #[DataProvider('repeatedDrafts')]
    public function test_repeated_identity_refuses_before_writes_and_preserves_caller_commit(string $side, bool $savepoint, string $shape): void
    {
        [$tx, $pairs] = LedgerPostingCurrentReadTest::bookFixture();
        $draft = self::repeatedEntries($pairs[0], $side, $shape);
        $attributes = $tx->getAttributes();
        $paper = LedgerPostingCurrentReadTest::book();
        $marker = User::factory()->create();
        DB::beginTransaction();
        DB::table('users')->where('id', $marker->id)->update(['name' => 'draft refusal caller commit']);
        if ($savepoint) {
            DB::beginTransaction();
        }
        $error = null;
        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();
        try {
            app(LedgerPostingService::class)->post($tx, $draft);
        } catch (Throwable $caught) {
            // Catch SQL failures too: the omission control must reach the
            // caller's real commit, not abort with a PHPUnit setup error.
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

        $diagnostic = json_encode([
            'exception' => $error === null ? null : $error::class,
            'staged_journal_rows' => count($staged['ledger_entries']),
            'committed_journal_rows' => DB::table('ledger_entries')->count(),
            'caller_depth_before_commit' => $depth,
            'physical_transaction_before_commit' => $physical,
            'caller_marker' => $marker->fresh()->name,
        ], JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(FinancialException::class, $error, $diagnostic);
        $this->assertSame('ledger_duplicate_entry_identity', $error->errorCode(), $diagnostic);
        $firstIndex = $shape === 'sparse-nonadjacent' || $side === 'debit' ? 0 : 1;
        $repeatIndex = $shape === 'sparse-nonadjacent' || $side === 'credit' ? 2 : 1;
        $this->assertSame([
            'ledger_account_id' => $pairs[0][$side === 'debit' ? 0 : 1]['ledger_account_id'],
            'type' => $side,
            'first_entry_index' => $firstIndex,
            'entry_index' => $repeatIndex,
        ], $error->context());
        $this->assertSame($savepoint ? 2 : 1, $depth);
        $this->assertTrue($physical);
        $this->assertSame($attributes, $tx->getAttributes());
        $this->assertSame($paper, $staged);
        $this->assertSame($paper, LedgerPostingCurrentReadTest::book());
        $this->assertSame('draft refusal caller commit', $marker->fresh()->name);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        foreach ($queries as $query) {
            $sql = strtolower($query['query']);
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete)\b/', $sql);
            $this->assertStringNotContainsString('ledger_accounts', $sql);
            $this->assertStringNotContainsString('ledger_entries', $sql);
        }
    }

    public static function splitCases(): array
    {
        return [[Currency::THB, false], [Currency::THB, true], [Currency::BDT, false], [Currency::BDT, true]];
    }

    #[DataProvider('splitCases')]
    public function test_distinct_accounts_allow_same_side_split_and_exact_currency(Currency $currency, bool $savepoint): void
    {
        [$tx, $pairs] = LedgerPostingCurrentReadTest::bookFixture();
        DB::table('financial_transactions')->where('id', $tx->id)->update(['currency' => $currency->value]);
        DB::table('ledger_accounts')->whereIn('id', array_column(array_merge(...$pairs), 'ledger_account_id'))->update(['currency' => $currency->value]);
        $tx->refresh();
        $paper = LedgerPostingCurrentReadTest::book();
        $draft = [
            array_replace($pairs[0][0], ['type' => LedgerEntryType::Debit, 'amount' => '4.00']),
            array_replace($pairs[1][0], ['amount' => '6.00']),
            $pairs[0][1],
        ];
        DB::beginTransaction();
        if ($savepoint) {
            DB::beginTransaction();
        }
        $entries = app(LedgerPostingService::class)->post($tx, $draft);
        $this->assertCount(3, $entries);
        $this->assertSame($savepoint ? 2 : 1, DB::transactionLevel());
        foreach ($entries as $index => $entry) {
            $this->assertSame($currency, $entry->currency);
            $this->assertSame($draft[$index]['ledger_account_id'], $entry->ledger_account_id);
            $this->assertSame($draft[$index]['amount'], $entry->amount);
            $this->assertSame(0, bccomp($draft[$index]['amount'], (string) DB::table('ledger_accounts')->where('id', $entry->ledger_account_id)->value('current_balance'), 2));
        }
        app(LedgerBalanceValidator::class)->assertTransactionBalanced($tx->fresh());
        if ($savepoint) {
            DB::rollBack();
        }
        DB::commit();
        if ($savepoint) {
            $this->assertSame($paper, LedgerPostingCurrentReadTest::book());
        } else {
            $this->assertSame(3, DB::table('ledger_entries')->where('financial_transaction_id', $tx->id)->count());
            app(LedgerBalanceValidator::class)->assertTransactionBalanced($tx->fresh());
        }
        $this->assertSame(0, DB::transactionLevel());
    }

    public static function callerDepths(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('callerDepths')]
    public function test_same_account_opposite_sides_remain_distinct(bool $savepoint): void
    {
        [$tx, $pairs] = LedgerPostingCurrentReadTest::bookFixture();
        $paper = LedgerPostingCurrentReadTest::book();
        $draft = [$pairs[0][0], array_replace($pairs[0][0], ['type' => 'credit'])];
        DB::beginTransaction();
        if ($savepoint) {
            DB::beginTransaction();
        }
        $entries = app(LedgerPostingService::class)->post($tx, $draft);
        $this->assertCount(2, $entries);
        $this->assertSame($entries[0]->ledger_account_id, $entries[1]->ledger_account_id);
        $this->assertSame(LedgerEntryType::Debit, $entries[0]->type);
        $this->assertSame(LedgerEntryType::Credit, $entries[1]->type);
        $this->assertSame(0, bccomp('0.00', (string) DB::table('ledger_accounts')->where('id', $entries[0]->ledger_account_id)->value('current_balance'), 2));
        app(LedgerBalanceValidator::class)->assertTransactionBalanced($tx->fresh());
        if ($savepoint) {
            DB::rollBack();
        }
        DB::commit();
        if ($savepoint) {
            $this->assertSame($paper, LedgerPostingCurrentReadTest::book());
        } else {
            $this->assertSame(2, DB::table('ledger_entries')->where('financial_transaction_id', $tx->id)->count());
        }
    }

    public function test_arithmetic_validator_does_not_silently_merge_or_apply_storage_identity(): void
    {
        [, $pairs] = LedgerPostingCurrentReadTest::bookFixture();
        $normalised = app(LedgerBalanceValidator::class)->validate(self::repeatedEntries($pairs[0], 'debit', 'enum'), Currency::THB);
        $this->assertCount(3, $normalised);
        $this->assertSame(LedgerEntryType::Debit, $normalised[0]['type']);
        $this->assertSame(LedgerEntryType::Debit, $normalised[1]['type']);
        $this->assertSame(['4.00', '6.00', '10.00'], array_map(fn ($entry) => $entry['amount']->toString(), $normalised));
        foreach (app(LedgerBalanceValidator::class)->totals($normalised, Currency::THB) as $total) {
            $this->assertSame('10.00', $total->toString());
        }
    }

    public function test_existing_balance_validation_precedes_duplicate_identity(): void
    {
        [$tx, $pairs] = LedgerPostingCurrentReadTest::bookFixture();
        $draft = self::repeatedEntries($pairs[0], 'debit', 'plain');
        $draft[2]['amount'] = '11.00';
        $paper = LedgerPostingCurrentReadTest::book();
        DB::beginTransaction();
        try {
            app(LedgerPostingService::class)->post($tx, $draft);
            $this->fail('An unbalanced draft was admitted.');
        } catch (FinancialException $error) {
            $this->assertSame('ledger_unbalanced_posting', $error->errorCode());
        } finally {
            DB::commit();
        }
        $this->assertSame($paper, LedgerPostingCurrentReadTest::book());
    }

    public function test_database_unique_key_remains_a_backstop_for_non_service_insert(): void
    {
        [$tx, $pairs] = LedgerPostingCurrentReadTest::bookFixture();
        DB::transaction(fn () => app(LedgerPostingService::class)->post($tx, $pairs[0]));
        $paper = LedgerPostingCurrentReadTest::book();
        $row = (array) DB::table('ledger_entries')->where('financial_transaction_id', $tx->id)->first();
        unset($row['id']);
        $error = null;
        DB::beginTransaction();
        try {
            DB::table('ledger_entries')->insert($row);
        } catch (QueryException $caught) {
            $error = $caught;
        } finally {
            DB::commit();
        }
        $this->assertInstanceOf(QueryException::class, $error);
        $this->assertStringContainsString('unique', strtolower($error->getMessage()));
        $this->assertSame($paper, LedgerPostingCurrentReadTest::book());
    }
}
