<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\Currency;
use App\Exceptions\FinancialException;
use App\Models\User;
use App\Services\Finance\LedgerBalanceValidator;
use App\Services\Finance\LedgerPostingService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/** Synthetic disposable books; not funding, settlement or production repair. */
final class LedgerPostingEntryPayloadTest extends FinancialMasterTestCase
{
    public static function invalidDrafts(): array
    {
        $cases = [];
        foreach (['description-overflow', 'reference_type-overflow', 'description-utf8', 'reference_type-utf8', 'metadata-utf8', 'metadata-inf', 'metadata-depth', 'metadata-throw'] as $kind) {
            foreach ([Currency::THB, Currency::BDT] as $currency) {
                foreach ([false, true] as $savepoint) {
                    $cases[$kind.'-'.$currency->value.'-'.($savepoint ? 'savepoint' : 'root')] = [$kind, $currency, $savepoint];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('invalidDrafts')]
    public function test_every_entry_payload_field_is_preflighted_before_writes_and_caller_can_commit(string $kind, Currency $currency, bool $savepoint): void
    {
        [$tx, $pairs] = LedgerPostingCurrentReadTest::bookFixture();
        DB::table('financial_transactions')->where('id', $tx->id)->update(['currency' => $currency->value]);
        DB::table('ledger_accounts')->whereIn('id', array_column(array_merge(...$pairs), 'ledger_account_id'))->update(['currency' => $currency->value]);
        $tx->refresh();
        $draft = $pairs[0];
        $field = explode('-', $kind)[0];
        $value = match ($kind) {
            'description-overflow', 'reference_type-overflow' => str_repeat('😀', 256),
            'description-utf8', 'reference_type-utf8' => "\xC3\x28",
            'metadata-utf8' => ["\xB1\x31"], 'metadata-inf' => [INF],
            'metadata-throw' => [new class implements \JsonSerializable
            {
                public function jsonSerialize(): mixed
                {
                    throw new \RuntimeException('private serialization payload');
                }
            }], default => [],
        };
        if ($kind === 'metadata-depth') {
            for ($i = 0; $i < 513; $i++) {
                $value = [$value];
            }
        }
        $draft[1][$field] = $value;
        $paper = LedgerPostingCurrentReadTest::book();
        $attributes = $tx->getAttributes();
        $marker = User::factory()->create();
        DB::beginTransaction();
        DB::table('users')->where('id', $marker->id)->update(['name' => 'entry payload refusal caller commit']);
        if ($savepoint) {
            DB::beginTransaction();
        }
        $error = null;
        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();
        try {
            app(LedgerPostingService::class)->post($tx, $draft);
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
        $range = str_ends_with($kind, '-overflow');
        $this->assertInstanceOf(FinancialException::class, $error, $diagnostic);
        $this->assertSame('ledger_entry_'.$field.'_'.($range ? 'too_long' : 'invalid'), $error->errorCode(), $diagnostic);
        $this->assertSame(1, $error->contextValue('entry_index'));
        $this->assertSame($field, $error->contextValue('field'));
        $this->assertSame($currency->value, $error->contextValue('currency'));
        $this->assertSame($savepoint ? 2 : 1, $depth);
        $this->assertTrue($physical);
        $this->assertSame($attributes, $tx->getAttributes());
        $this->assertSame($paper, $staged, $diagnostic);
        $this->assertSame($paper, LedgerPostingCurrentReadTest::book(), $diagnostic);
        $this->assertSame('entry payload refusal caller commit', $marker->fresh()->name);
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
    public function test_unicode_boundaries_nulls_empty_values_and_metadata_survive_real_commit(Currency $currency, bool $empty): void
    {
        [$tx, $pairs] = LedgerPostingCurrentReadTest::bookFixture();
        DB::table('financial_transactions')->where('id', $tx->id)->update(['currency' => $currency->value]);
        DB::table('ledger_accounts')->whereIn('id', array_column(array_merge(...$pairs), 'ledger_account_id'))->update(['currency' => $currency->value]);
        $tx->refresh();
        $draft = $pairs[0];
        $description = $empty ? '' : str_repeat('😀', 255);
        $reference = $empty ? '' : str_repeat('ก', 255);
        foreach ($draft as &$entry) {
            $entry['description'] = $description;
            $entry['reference_type'] = $reference;
            $entry['metadata'] = $empty ? [] : ['বাংলা' => 'ไทย', 'emoji' => '😀', 'nested' => ['ok' => true]];
        }
        unset($entry);
        $rows = DB::transaction(fn () => app(LedgerPostingService::class)->post($tx, $draft));
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame($description, $row->fresh()->description);
            $this->assertSame($reference, $row->fresh()->reference_type);
            $this->assertSame($draft[0]['metadata'], $row->fresh()->metadata);
            $this->assertSame($currency, $row->currency);
        }
        app(LedgerBalanceValidator::class)->assertTransactionBalanced($tx->fresh());
    }

    public static function currencies(): array
    {
        return [[Currency::THB], [Currency::BDT]];
    }

    #[DataProvider('currencies')]
    public function test_json_callback_cannot_run_again_after_account_writes(Currency $currency): void
    {
        [$tx, $pairs] = LedgerPostingCurrentReadTest::bookFixture();
        DB::table('financial_transactions')->where('id', $tx->id)->update(['currency' => $currency->value]);
        DB::table('ledger_accounts')->whereIn('id', array_column(array_merge(...$pairs), 'ledger_account_id'))->update(['currency' => $currency->value]);
        $tx->refresh();
        $callback = new class implements \JsonSerializable
        {
            public int $calls = 0;

            public function jsonSerialize(): mixed
            {
                if (++$this->calls !== 1) {
                    throw new \RuntimeException('Unexpected second serialization');
                }
                if (DB::table('ledger_entries')->count() !== 0 || DB::table('ledger_accounts')->where('current_balance', '<>', '0.00')->exists()) {
                    throw new \RuntimeException('Serialization ran after posting DML');
                }

                return ['frozen' => true];
            }
        };
        $draft = $pairs[0];
        $draft[1]['metadata'] = ['callback' => $callback];
        $rows = DB::transaction(fn () => app(LedgerPostingService::class)->post($tx, $draft));
        $this->assertSame(1, $callback->calls);
        $this->assertCount(2, $rows);
        $this->assertSame(['callback' => ['frozen' => true]], $rows[1]->fresh()->metadata);
        $this->assertSame('10.00', $rows[1]->fresh()->amount);
        app(LedgerBalanceValidator::class)->assertTransactionBalanced($tx->fresh());
    }

    #[DataProvider('currencies')]
    public function test_null_draft_text_uses_canonical_transaction_fallback_and_metadata_stays_sql_null(Currency $currency): void
    {
        [$tx, $pairs] = LedgerPostingCurrentReadTest::bookFixture();
        $description = str_repeat('ব', 255);
        $reference = str_repeat('😀', 255);
        DB::table('financial_transactions')->where('id', $tx->id)->update(['currency' => $currency->value, 'description' => $description, 'reference_type' => $reference]);
        DB::table('ledger_accounts')->whereIn('id', array_column(array_merge(...$pairs), 'ledger_account_id'))->update(['currency' => $currency->value]);
        $draft = array_map(fn ($entry) => array_replace($entry, ['description' => null, 'reference_type' => null, 'metadata' => null]), $pairs[0]);
        $tx->description = 'stale local description';
        $tx->reference_type = 'stale local reference';
        $rows = DB::transaction(fn () => app(LedgerPostingService::class)->post($tx, $draft));
        foreach ($rows as $row) {
            $this->assertSame($description, $row->fresh()->description);
            $this->assertSame($reference, $row->fresh()->reference_type);
            $this->assertNull($row->fresh()->metadata);
            $this->assertNull(DB::table('ledger_entries')->where('id', $row->id)->value('metadata'));
        }
        $this->assertSame('stale local description', $tx->description);
    }
}
