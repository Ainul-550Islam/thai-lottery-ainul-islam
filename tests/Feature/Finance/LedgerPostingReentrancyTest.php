<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\Currency;
use App\Enums\TransactionType;
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

/** Disposable journals exercising callback reentrancy, not external funding. */
final class LedgerPostingReentrancyTest extends FinancialMasterTestCase
{
    private function postingFixture(Currency $currency): array
    {
        [$outer, $pairs] = LedgerPostingCurrentReadTest::bookFixture();
        DB::table('financial_transactions')->where('id', $outer->id)->update(['currency' => $currency->value]);
        DB::table('ledger_accounts')->whereIn('id', array_column(array_merge(...$pairs), 'ledger_account_id'))->update(['currency' => $currency->value]);
        $outer->refresh();
        $inner = FinancialTransaction::factory()->create(['user_id' => null, 'wallet_id' => null, 'type' => TransactionType::Adjustment, 'currency' => $currency, 'amount' => '3.00', 'description' => 'Independent synthetic callback journal']);
        $draft = $pairs[0];
        $innerDraft = array_map(fn ($entry) => array_replace($entry, ['amount' => '3.00']), $draft);

        return [$outer, $inner, $draft, $innerDraft];
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

    public static function recursive(): array
    {
        $rows = [];
        foreach (['propagate', 'catch', 'double-catch', 'same-tx'] as $kind) {
            foreach ([false, true] as $fresh) {
                foreach ([Currency::THB, Currency::BDT] as $currency) {
                    foreach ([1, 2] as $depth) {
                        $rows[$kind.'-'.($fresh ? 'fresh' : 'same').'-'.$currency->value.'-'.($depth === 1 ? 'root' : 'savepoint')] = [$kind, $fresh, $currency, $depth];
                    }
                }
            }
        }

        return $rows;
    }

    #[DataProvider('recursive')]
    public function test_reentrant_callback_cannot_post_a_second_journal_or_corrupt_projected_balances(string $kind, bool $fresh, Currency $currency, int $depth): void
    {
        [$outer, $inner, $draft, $innerDraft] = $this->postingFixture($currency);
        $service = app(LedgerPostingService::class);
        $nested = $fresh ? clone $service : $service;
        $paper = LedgerPostingCurrentReadTest::book();
        $attributes = [$outer->getAttributes(), $inner->getAttributes()];
        $marker = User::factory()->create();
        DB::beginTransaction();
        DB::table('users')->where('id', $marker->id)->update(['name' => 'reentrant posting caller marker']);
        if ($depth === 2) {
            DB::beginTransaction();
        }
        $codes = $innerQueries = [];
        $callback = $this->serializable(function () use ($kind, $nested, $outer, $inner, $innerDraft, &$codes, &$innerQueries) {
            for ($i = 0; $i < ($kind === 'double-catch' ? 2 : 1); $i++) {
                $before = count(DB::connection()->getQueryLog());
                try {
                    $nested->post($kind === 'same-tx' ? $outer : $inner, $innerDraft);
                    $codes[] = null;
                } catch (FinancialException $error) {
                    $codes[] = $error->errorCode();
                    if (in_array($kind, ['propagate', 'same-tx'], true)) {
                        throw $error;
                    }
                } finally {
                    $innerQueries[] = count(DB::connection()->getQueryLog()) - $before;
                }
            }
        });
        $draft[1]['metadata'] = ['callback' => $callback];
        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();
        $error = null;
        try {
            $service->post($outer, $draft);
        } catch (Throwable $caught) {
            $error = $caught;
        }
        $queries = DB::connection()->getQueryLog();
        DB::connection()->disableQueryLog();
        $actualDepth = DB::transactionLevel();
        $physical = DB::connection()->getPdo()->inTransaction();
        while (DB::transactionLevel() > 0) {
            DB::commit();
        }
        $rows = DB::table('ledger_entries')->orderBy('id')->get();
        $balances = DB::table('ledger_accounts')->whereIn('id', array_column($draft, 'ledger_account_id'))->orderBy('id')->pluck('current_balance')->all();
        $diagnostic = json_encode(['exception' => $error === null ? null : $error::class, 'exception_code' => $error?->getCode(), 'inner_codes' => $codes, 'committed_journal_rows' => $rows->count(), 'inner_journal_rows' => DB::table('ledger_entries')->where('financial_transaction_id', $inner->id)->count(), 'caller_depth_before_commit' => $actualDepth, 'physical_before_commit' => $physical, 'account_balances' => $balances, 'committed_caller_marker_rows' => (int) ($marker->fresh()->name === 'reentrant posting caller marker')], JSON_THROW_ON_ERROR);
        $aborts = in_array($kind, ['propagate', 'same-tx'], true);
        $this->assertSame(array_fill(0, $kind === 'double-catch' ? 2 : 1, 'ledger_posting_reentrant'), $codes, $diagnostic);
        $this->assertSame(array_fill(0, count($codes), 0), $innerQueries);
        $this->assertSame($fresh, $service !== $nested);
        $this->assertSame(1, $callback->calls);
        $this->assertSame($depth, $actualDepth);
        $this->assertTrue($physical);
        $this->assertSame($attributes, [$outer->getAttributes(), $inner->getAttributes()]);
        $this->assertSame('reentrant posting caller marker', $marker->fresh()->name);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->assertSame(0, DB::table('ledger_entries')->where('financial_transaction_id', $inner->id)->count(), $diagnostic);
        if ($aborts) {
            $this->assertInstanceOf(FinancialException::class, $error, $diagnostic);
            $this->assertSame('ledger_entry_metadata_invalid', $error->errorCode());
            $this->assertSame($paper, LedgerPostingCurrentReadTest::book(), $diagnostic);
            foreach ($queries as $query) {
                $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete)\b/i', $query['query'], $diagnostic);
            }
        } else {
            $this->assertNull($error, $diagnostic);
            $this->assertCount(2, $rows, $diagnostic);
            foreach ($balances as $balance) {
                $this->assertSame(0, bccomp('10.00', (string) $balance, 2));
            }
            app(LedgerBalanceValidator::class)->assertTransactionBalanced($outer->fresh());
        }
    }

    public static function contexts(): array
    {
        return [[Currency::THB, 1], [Currency::THB, 2], [Currency::BDT, 1], [Currency::BDT, 2]];
    }

    #[DataProvider('contexts')]
    public function test_sequential_postings_remain_allowed_and_shared_account_projection_is_thirteen(Currency $currency, int $depth): void
    {
        [$outer, $inner, $draft, $innerDraft] = $this->postingFixture($currency);
        $service = app(LedgerPostingService::class);
        for ($i = 0; $i < $depth; $i++) {
            DB::beginTransaction();
        }
        $first = $service->post($outer, $draft);
        $second = (clone $service)->post($inner, $innerDraft);
        $this->assertSame($depth, DB::transactionLevel());
        while (DB::transactionLevel() > 0) {
            DB::commit();
        }
        $this->assertCount(2, $first);
        $this->assertCount(2, $second);
        $this->assertSame(4, DB::table('ledger_entries')->count());
        foreach (array_column($draft, 'ledger_account_id') as $id) {
            $this->assertSame(0, bccomp('13.00', (string) DB::table('ledger_accounts')->where('id', $id)->value('current_balance'), 2));
        }
        app(LedgerBalanceValidator::class)->assertTransactionBalanced($outer->fresh());
        app(LedgerBalanceValidator::class)->assertTransactionBalanced($inner->fresh());
    }

    #[DataProvider('contexts')]
    public function test_payload_and_recursive_failures_release_the_lease_for_later_valid_posts(Currency $currency, int $depth): void
    {
        [$outer, $inner, $draft, $innerDraft] = $this->postingFixture($currency);
        $service = app(LedgerPostingService::class);
        for ($i = 0; $i < $depth; $i++) {
            DB::beginTransaction();
        }
        $paper = LedgerPostingCurrentReadTest::book();
        $bad = $draft;
        $bad[1]['description'] = str_repeat('x', 256);
        $recursive = $draft;
        $recursive[1]['metadata'] = ['callback' => $this->serializable(fn () => (clone $service)->post($inner, $innerDraft))];
        foreach ([[$bad, 'ledger_entry_description_too_long'], [$recursive, 'ledger_entry_metadata_invalid']] as [$entries, $code]) {
            try {
                $service->post($outer, $entries);
                $this->fail('Expected a pre-write failure.');
            } catch (FinancialException $error) {
                $this->assertSame($code, $error->errorCode());
                $this->assertSame($paper, LedgerPostingCurrentReadTest::book());
                $this->assertSame($depth, DB::transactionLevel());
            }
        }
        $this->assertCount(2, $service->post($outer, $draft));
        $this->assertCount(2, (clone $service)->post($inner, $innerDraft));
        while (DB::transactionLevel() > 0) {
            DB::commit();
        }
        foreach (array_column($draft, 'ledger_account_id') as $id) {
            $this->assertSame(0, bccomp('13.00', (string) DB::table('ledger_accounts')->where('id', $id)->value('current_balance'), 2));
        }
    }
}
