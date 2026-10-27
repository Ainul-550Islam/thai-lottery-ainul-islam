<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\Currency;
use App\Exceptions\FinancialException;
use App\Models\User;
use App\Services\Finance\LedgerBalanceValidator;
use App\Services\Finance\LedgerPostingService;
use Closure;
use Illuminate\Support\Facades\DB;
use JsonSerializable;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/** Deliberately mutating trusted callbacks in disposable books only. */
final class LedgerPostingInputDriftTest extends FinancialMasterTestCase
{
    private function postingFixture(Currency $currency): array
    {
        [$tx, $pairs] = LedgerPostingCurrentReadTest::bookFixture();
        DB::table('financial_transactions')->where('id', $tx->id)->update(['currency' => $currency->value]);
        DB::table('ledger_accounts')->whereIn('id', array_column(array_merge(...$pairs), 'ledger_account_id'))->update(['currency' => $currency->value]);
        $tx->refresh();

        return [$tx, $pairs[0]];
    }

    private function serializable(Closure $callback): JsonSerializable
    {
        return new class($callback) implements JsonSerializable
        {
            public int $calls = 0;

            public function __construct(private Closure $callback) {}

            public function jsonSerialize(): mixed
            {
                $this->calls++;
                ($this->callback)();

                return ['frozen' => true];
            }
        };
    }

    public static function drift(): array
    {
        $rows = [];
        foreach (['tx-status', 'tx-amount', 'tx-currency', 'tx-deleted', 'account-balance', 'account-type', 'account-currency', 'account-active', 'account-deleted', 'account-hard-delete'] as $kind) {
            foreach ([Currency::THB, Currency::BDT] as $currency) {
                foreach ([1, 2] as $depth) {
                    $rows[$kind.'-'.$currency->value.'-'.($depth === 1 ? 'root' : 'savepoint')] = [$kind, $currency, $depth];
                }
            }
        }

        return $rows;
    }

    #[DataProvider('drift')]
    public function test_financial_input_drift_is_refused_without_overwriting_callback_work(string $kind, Currency $currency, int $depth): void
    {
        [$tx, $draft] = $this->postingFixture($currency);
        $attributes = $tx->getAttributes();
        $marker = User::factory()->create();
        DB::beginTransaction();
        DB::table('users')->where('id', $marker->id)->update(['name' => 'input witness caller marker']);
        if ($depth === 2) {
            DB::beginTransaction();
        }
        $callbackPaper = null;
        $afterCallback = null;
        $callback = $this->serializable(function () use ($kind, $currency, $tx, $draft, &$callbackPaper, &$afterCallback) {
            $otherCurrency = $currency === Currency::THB ? Currency::BDT : Currency::THB;
            $timestamp = '2026-10-21 01:00:00';
            if (str_starts_with($kind, 'tx-')) {
                [$field, $value] = match ($kind) {
                    'tx-status' => ['status', 'completed'], 'tx-amount' => ['amount', '3.00'],
                    'tx-currency' => ['currency', $otherCurrency->value], default => ['deleted_at', $timestamp],
                };
                DB::table('financial_transactions')->where('id', $tx->id)->update([$field => $value]);
            } elseif ($kind === 'account-hard-delete') {
                DB::table('ledger_accounts')->where('id', $draft[1]['ledger_account_id'])->delete();
            } else {
                [$field, $value] = match ($kind) {
                    'account-balance' => ['current_balance', '123.00'], 'account-type' => ['type', 'asset'],
                    'account-currency' => ['currency', $otherCurrency->value], 'account-active' => ['is_active', false],
                    default => ['deleted_at', $timestamp],
                };
                DB::table('ledger_accounts')->where('id', $draft[1]['ledger_account_id'])->update([$field => $value]);
            }
            $callbackPaper = LedgerPostingCurrentReadTest::book();
            $afterCallback = count(DB::connection()->getQueryLog());
        });
        $draft[1]['metadata'] = ['callback' => $callback];
        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();
        $error = null;
        try {
            app(LedgerPostingService::class)->post($tx, $draft);
        } catch (Throwable $caught) {
            $error = $caught;
        }
        $queries = DB::connection()->getQueryLog();
        DB::connection()->disableQueryLog();
        $actualDepth = DB::transactionLevel();
        $physical = DB::connection()->getPdo()->inTransaction();
        $staged = LedgerPostingCurrentReadTest::book();
        while (DB::transactionLevel() > 0) {
            DB::commit();
        }
        $diagnostic = json_encode(['exception' => $error === null ? null : $error::class, 'exception_code' => $error?->getCode(), 'committed_journal_rows' => DB::table('ledger_entries')->count(), 'caller_depth_before_commit' => $actualDepth, 'physical_before_commit' => $physical, 'committed_caller_marker_rows' => (int) ($marker->fresh()->name === 'input witness caller marker')], JSON_THROW_ON_ERROR);
        $entity = str_starts_with($kind, 'tx-') ? 'financial_transaction' : 'ledger_account';
        $field = match ($kind) {
            'tx-status' => 'status', 'tx-amount' => 'amount', 'tx-currency', 'account-currency' => 'currency',
            'tx-deleted', 'account-deleted' => 'deleted_at', 'account-balance' => 'current_balance',
            'account-type' => 'type', 'account-active' => 'is_active', default => 'row_set',
        };
        $this->assertInstanceOf(FinancialException::class, $error, $diagnostic);
        $this->assertSame('ledger_posting_inputs_changed', $error->errorCode(), $diagnostic);
        $this->assertSame(['stage' => 'payload_preflight', 'entity' => $entity, 'field' => $field], $error->context());
        $this->assertNull($error->getPrevious());
        $this->assertSame(1, $callback->calls);
        $this->assertSame($depth, $actualDepth);
        $this->assertTrue($physical);
        $this->assertSame($attributes, $tx->getAttributes());
        $this->assertNotNull($callbackPaper);
        $this->assertSame($callbackPaper, $staged, $diagnostic);
        $this->assertSame($callbackPaper, LedgerPostingCurrentReadTest::book(), $diagnostic);
        $this->assertSame('input witness caller marker', $marker->fresh()->name);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->assertNotNull($afterCallback);
        foreach (array_slice($queries, $afterCallback) as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete)\b/i', $query['query'], $diagnostic);
        }
    }

    public static function stable(): array
    {
        $rows = [];
        foreach (['unchanged', 'annotations', 'balanced-savepoint'] as $kind) {
            foreach ([Currency::THB, Currency::BDT] as $currency) {
                foreach ([1, 2] as $depth) {
                    $rows[$kind.'-'.$currency->value.'-'.$depth] = [$kind, $currency, $depth];
                }
            }
        }

        return $rows;
    }

    #[DataProvider('stable')]
    public function test_stable_core_inputs_allow_annotations_and_balanced_savepoints(string $kind, Currency $currency, int $depth): void
    {
        [$tx, $draft] = $this->postingFixture($currency);
        $description = $tx->description;
        for ($i = 0; $i < $depth; $i++) {
            DB::beginTransaction();
        }
        $callback = $this->serializable(function () use ($kind, $tx, $draft) {
            if ($kind === 'annotations') {
                DB::table('financial_transactions')->where('id', $tx->id)->update(['description' => 'new annotation']);
                DB::table('ledger_accounts')->where('id', $draft[1]['ledger_account_id'])->update(['name' => 'new account annotation']);
            }
            if ($kind === 'balanced-savepoint') {
                DB::beginTransaction();
                DB::rollBack();
            }
        });
        $draft[1]['metadata'] = ['callback' => $callback];
        $rows = app(LedgerPostingService::class)->post($tx, $draft);
        $this->assertSame($depth, DB::transactionLevel());
        while (DB::transactionLevel() > 0) {
            DB::commit();
        }
        $this->assertSame(1, $callback->calls);
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame('10.00', $row->fresh()->amount);
            $this->assertSame($description, $row->fresh()->description);
            $this->assertSame(0, bccomp('10.00', (string) DB::table('ledger_accounts')->where('id', $row->ledger_account_id)->value('current_balance'), 2));
        }
        app(LedgerBalanceValidator::class)->assertTransactionBalanced($tx->fresh());
    }
}
