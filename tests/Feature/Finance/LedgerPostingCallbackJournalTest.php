<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\Currency;
use App\Exceptions\FinancialException;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Services\Finance\LedgerBalanceValidator;
use App\Services\Finance\LedgerPostingService;
use Closure;
use Illuminate\Support\Facades\DB;
use JsonSerializable;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/** Direct callback SQL is deliberately synthetic; never a production journal API. */
final class LedgerPostingCallbackJournalTest extends FinancialMasterTestCase
{
    private function postingFixture(Currency $currency): array
    {
        [$tx, $pairs] = LedgerPostingCurrentReadTest::bookFixture();
        DB::table('financial_transactions')->where('id', $tx->id)->update(['currency' => $currency->value]);
        DB::table('ledger_accounts')->whereIn('id', array_column(array_merge(...$pairs), 'ledger_account_id'))->update(['currency' => $currency->value]);
        $tx->refresh();

        return [$tx, $pairs];
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

    private function insertRow(FinancialTransaction $tx, array $draft, bool $deleted = false): void
    {
        DB::table('ledger_entries')->insert([
            'financial_transaction_id' => $tx->id, 'ledger_account_id' => $draft['ledger_account_id'],
            'wallet_id' => null, 'type' => $draft['type'], 'amount' => '3.00', 'currency' => $tx->currency->value,
            'description' => 'Synthetic direct callback row', 'balance_after' => null,
            'posted_at' => '2026-10-21 01:00:00', 'created_at' => '2026-10-21 01:00:00',
            'updated_at' => '2026-10-21 01:00:00', 'deleted_at' => $deleted ? '2026-10-21 01:00:00' : null,
        ]);
    }

    public static function intrusions(): array
    {
        $rows = [];
        foreach (['same-first', 'same-last', 'disjoint-pair', 'disjoint-single', 'soft-deleted', 'savepoint-row'] as $kind) {
            foreach ([Currency::THB, Currency::BDT] as $currency) {
                foreach ([1, 2] as $depth) {
                    foreach ([0, 1] as $position) {
                        $rows[$kind.'-'.$currency->value.'-'.($depth === 1 ? 'root' : 'savepoint').'-'.$position] = [$kind, $currency, $depth, $position];
                    }
                }
            }
        }

        return $rows;
    }

    #[DataProvider('intrusions')]
    public function test_callback_journal_rows_refuse_before_any_additional_posting_dml(string $kind, Currency $currency, int $depth, int $position): void
    {
        [$tx, $pairs] = $this->postingFixture($currency);
        $draft = $pairs[0];
        $attributes = $tx->getAttributes();
        $marker = User::factory()->create();
        DB::beginTransaction();
        DB::table('users')->where('id', $marker->id)->update(['name' => 'callback journal caller marker']);
        if ($depth === 2) {
            DB::beginTransaction();
        }
        $callbackBook = null;
        $afterCallback = null;
        $callback = $this->serializable(function () use ($kind, $tx, $pairs, &$callbackBook, &$afterCallback) {
            if ($kind === 'savepoint-row') {
                DB::beginTransaction();
            }
            if (str_starts_with($kind, 'disjoint-')) {
                $this->insertRow($tx, $pairs[1][0]);
                if ($kind === 'disjoint-pair') {
                    $this->insertRow($tx, $pairs[1][1]);
                }
            } else {
                $this->insertRow($tx, $pairs[0][$kind === 'same-last' ? 1 : 0], $kind === 'soft-deleted');
            }
            if ($kind === 'savepoint-row') {
                DB::commit();
            }
            $callbackBook = LedgerPostingCurrentReadTest::book();
            $afterCallback = count(DB::connection()->getQueryLog());
        });
        $draft[$position]['metadata'] = ['callback' => $callback];
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
        $diagnostic = json_encode(['exception' => $error === null ? null : $error::class, 'domain_code' => $error instanceof FinancialException ? $error->errorCode() : null, 'committed_journal_rows' => DB::table('ledger_entries')->count(), 'caller_depth_before_commit' => $actualDepth, 'physical_before_commit' => $physical, 'committed_caller_marker_rows' => (int) ($marker->fresh()->name === 'callback journal caller marker'), 'account_balances' => DB::table('ledger_accounts')->whereIn('id', array_column($pairs[0], 'ledger_account_id'))->orderBy('id')->pluck('current_balance')->all()], JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(FinancialException::class, $error, $diagnostic);
        $this->assertSame('ledger_duplicate_posting', $error->errorCode(), $diagnostic);
        $this->assertSame(['transaction_id' => (int) $tx->id], $error->context());
        $this->assertNull($error->getPrevious());
        $this->assertSame(1, $callback->calls);
        $this->assertSame($depth, $actualDepth);
        $this->assertTrue($physical);
        $this->assertSame($attributes, $tx->getAttributes());
        $this->assertNotNull($callbackBook);
        $this->assertSame($callbackBook, $staged, $diagnostic);
        $this->assertSame($callbackBook, LedgerPostingCurrentReadTest::book(), $diagnostic);
        $this->assertSame('callback journal caller marker', $marker->fresh()->name);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->assertSame($kind === 'disjoint-pair' ? 2 : 1, DB::table('ledger_entries')->count());
        foreach ($pairs[0] as $leg) {
            $this->assertSame(0, bccomp('0.00', (string) DB::table('ledger_accounts')->where('id', $leg['ledger_account_id'])->value('current_balance'), 2));
        }
        $this->assertNotNull($afterCallback);
        foreach (array_slice($queries, $afterCallback) as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete)\b/i', $query['query'], $diagnostic);
            if (stripos($query['query'], 'ledger_entries') !== false) {
                $this->assertDoesNotMatchRegularExpression('/for\s+update|lock\s+in\s+share\s+mode|skip\s+locked/i', $query['query']);
            }
        }
    }

    public static function benign(): array
    {
        $rows = [];
        foreach (['read-only', 'unrelated-journal', 'rolled-back-journal'] as $kind) {
            foreach ([Currency::THB, Currency::BDT] as $currency) {
                foreach ([1, 2] as $depth) {
                    foreach ([0, 1] as $position) {
                        $rows[$kind.'-'.$currency->value.'-'.$depth.'-'.$position] = [$kind, $currency, $depth, $position];
                    }
                }
            }
        }

        return $rows;
    }

    #[DataProvider('benign')]
    public function test_unrelated_and_rolled_back_journal_work_does_not_block_a_valid_post(string $kind, Currency $currency, int $depth, int $position): void
    {
        [$tx, $pairs] = $this->postingFixture($currency);
        $other = FinancialTransaction::factory()->create(['user_id' => null, 'wallet_id' => null, 'currency' => $currency]);
        for ($i = 0; $i < $depth; $i++) {
            DB::beginTransaction();
        }
        $callback = $this->serializable(function () use ($kind, $tx, $other, $pairs) {
            if ($kind === 'unrelated-journal') {
                foreach ($pairs[1] as $leg) {
                    $this->insertRow($other, $leg);
                }
            } elseif ($kind === 'rolled-back-journal') {
                DB::beginTransaction();
                $this->insertRow($tx, $pairs[0][0]);
                DB::rollBack();
            } else {
                $this->assertFalse(DB::table('ledger_entries')->where('financial_transaction_id', $tx->id)->exists());
            }
        });
        $draft = $pairs[0];
        $draft[$position]['metadata'] = ['callback' => $callback];
        $rows = app(LedgerPostingService::class)->post($tx, $draft);
        $this->assertSame($depth, DB::transactionLevel());
        while (DB::transactionLevel() > 0) {
            DB::commit();
        }
        $this->assertSame(1, $callback->calls);
        $this->assertCount(2, $rows);
        $this->assertSame(2, DB::table('ledger_entries')->where('financial_transaction_id', $tx->id)->count());
        $this->assertSame($kind === 'unrelated-journal' ? 2 : 0, DB::table('ledger_entries')->where('financial_transaction_id', $other->id)->count());
        foreach ($pairs[0] as $leg) {
            $this->assertSame(0, bccomp('10.00', (string) DB::table('ledger_accounts')->where('id', $leg['ledger_account_id'])->value('current_balance'), 2));
        }
        app(LedgerBalanceValidator::class)->assertTransactionBalanced($tx->fresh());
    }
}
