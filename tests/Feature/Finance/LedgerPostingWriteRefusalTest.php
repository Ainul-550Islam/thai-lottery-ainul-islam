<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\Currency;
use App\Exceptions\FinancialException;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\Finance\LedgerBalanceValidator;
use App\Services\Finance\LedgerPostingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

final class LedgerPostingWriteRefusalTest extends FinancialMasterTestCase
{
    private function postingFixture(Currency $currency): array
    {
        [$tx, $pairs] = LedgerPostingCurrentReadTest::bookFixture();
        DB::table('financial_transactions')->where('id', $tx->id)->update(['currency' => $currency->value]);
        DB::table('ledger_accounts')->whereIn('id', array_column(array_merge(...$pairs), 'ledger_account_id'))->update(['currency' => $currency->value]);
        $tx->refresh();

        return [$tx, $pairs[0]];
    }

    public static function refusals(): array
    {
        $rows = [];
        foreach (['account-saving', 'account-updating', 'entry-saving', 'entry-creating'] as $kind) {
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

    #[DataProvider('refusals')]
    public function test_refused_save_stops_the_writer_and_owner_rolls_back_the_money_unit(string $kind, Currency $currency, int $depth, int $position): void
    {
        $this->exerciseRefusal($kind, $currency, $depth, $position, false);
    }

    public static function committedProbes(): array
    {
        $rows = [];
        foreach ([['account-saving', 0], ['account-updating', 1], ['entry-saving', 0]] as [$kind, $position]) {
            foreach ([Currency::THB, Currency::BDT] as $currency) {
                $rows[$kind.'-'.$currency->value.'-'.$position] = [$kind, $currency, $position];
            }
        }

        return $rows;
    }

    /** These disposable commits demonstrate ownership only, not valid accounting. */
    #[DataProvider('committedProbes')]
    public function test_synthetic_committed_probe_distinguishes_refusal_from_old_silent_or_late_failure(string $kind, Currency $currency, int $position): void
    {
        $this->exerciseRefusal($kind, $currency, 1, $position, true);
    }

    private function exerciseRefusal(string $kind, Currency $currency, int $depth, int $position, bool $commitProbe): void
    {
        [$tx, $draft] = $this->postingFixture($currency);
        $before = LedgerPostingCurrentReadTest::book();
        $attributes = $tx->getAttributes();
        $marker = User::factory()->create();
        $markerBefore = $marker->name;
        DB::beginTransaction();
        DB::table('users')->where('id', $marker->id)->update(['name' => 'write refusal caller marker']);
        if ($depth === 2) {
            DB::beginTransaction();
        }
        $originalDispatcher = Model::getEventDispatcher();
        $dispatcher = clone $originalDispatcher;
        Model::setEventDispatcher($dispatcher);
        $calls = 0;
        $afterRefusal = null;
        $target = $draft[$position]['ledger_account_id'];
        [$entity, $event] = explode('-', $kind, 2);
        $class = $entity === 'account' ? LedgerAccount::class : LedgerEntry::class;
        $dispatcher->listen('eloquent.'.$event.': '.$class, function (Model $model) use ($entity, $target, &$calls, &$afterRefusal): ?bool {
            $id = $entity === 'account' ? (int) $model->getKey() : (int) $model->ledger_account_id;
            if ($id !== $target) {
                return null;
            }
            $calls++;
            $afterRefusal = count(DB::connection()->getQueryLog());

            return false;
        });
        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();
        $error = null;
        try {
            app(LedgerPostingService::class)->post($tx, $draft);
        } catch (Throwable $caught) {
            $error = $caught;
        } finally {
            Model::setEventDispatcher($originalDispatcher);
        }
        $queries = DB::connection()->getQueryLog();
        DB::connection()->disableQueryLog();
        $actualDepth = DB::transactionLevel();
        $physical = DB::connection()->getPdo()->inTransaction();
        $staged = LedgerPostingCurrentReadTest::book();
        $rowsBeforeOwnerHandling = DB::table('ledger_entries')->count();
        $balancesBeforeOwnerHandling = DB::table('ledger_accounts')->whereIn('id', array_column($draft, 'ledger_account_id'))->orderBy('id')->pluck('current_balance')->all();
        if ($commitProbe) {
            DB::commit();
        } else {
            // Roll back exactly the money unit. A savepoint owner retains its
            // earlier outer marker; a root owner explicitly starts a new unit.
            DB::rollBack();
            if ($depth === 2) {
                DB::commit();
            }
        }
        $diagnostic = json_encode(['exception' => $error === null ? null : $error::class, 'domain_code' => $error instanceof FinancialException ? $error->errorCode() : null, 'journal_rows_before_owner_handling' => $rowsBeforeOwnerHandling, 'account_balances_before_owner_handling' => $balancesBeforeOwnerHandling, 'committed_journal_rows' => DB::table('ledger_entries')->count(), 'caller_depth_before_owner_handling' => $actualDepth, 'physical_before_owner_handling' => $physical, 'committed_caller_marker_rows' => (int) ($marker->fresh()->name === 'write refusal caller marker')], JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(FinancialException::class, $error, $diagnostic);
        $this->assertSame($entity === 'account' ? 'ledger_account_write_refused' : 'ledger_entry_write_refused', $error->errorCode(), $diagnostic);
        $this->assertSame(['stage' => $entity === 'account' ? 'account_balance_write' : 'ledger_entry_write', 'ledger_account_id' => $target], $error->context());
        $this->assertNull($error->getPrevious());
        $this->assertSame(1, $calls);
        $this->assertSame($depth, $actualDepth);
        $this->assertTrue($physical);
        $this->assertSame($attributes, $tx->getAttributes());
        $this->assertSame($position, $rowsBeforeOwnerHandling, $diagnostic);
        $expectedBalances = $position === 0 ? ($entity === 'account' ? ['0.00', '0.00'] : ['10.00', '0.00']) : ($entity === 'account' ? ['10.00', '0.00'] : ['10.00', '10.00']);
        foreach ($expectedBalances as $index => $expected) {
            $this->assertSame(0, bccomp($expected, (string) $balancesBeforeOwnerHandling[$index], 2), $diagnostic);
        }
        $this->assertSame($commitProbe ? $staged : $before, LedgerPostingCurrentReadTest::book(), $diagnostic);
        $this->assertSame($commitProbe || $depth === 2 ? 'write refusal caller marker' : $markerBefore, $marker->fresh()->name);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->assertSame($originalDispatcher, Model::getEventDispatcher());
        $this->assertNotNull($afterRefusal);
        foreach (array_slice($queries, $afterRefusal) as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete)\b/i', $query['query'], $diagnostic);
        }
    }

    public static function allowed(): array
    {
        $rows = [];
        foreach (['none', 'allow-true', 'allow-null', 'retry-after-refusal'] as $kind) {
            foreach ([Currency::THB, Currency::BDT] as $currency) {
                foreach ([1, 2] as $depth) {
                    $rows[$kind.'-'.$currency->value.'-'.$depth] = [$kind, $currency, $depth];
                }
            }
        }

        return $rows;
    }

    #[DataProvider('allowed')]
    public function test_nonrefusing_events_and_sequential_retry_preserve_normal_posting(string $kind, Currency $currency, int $depth): void
    {
        [$tx, $draft] = $this->postingFixture($currency);
        $before = LedgerPostingCurrentReadTest::book();
        for ($i = 0; $i < $depth; $i++) {
            DB::beginTransaction();
        }
        $originalDispatcher = Model::getEventDispatcher();
        $dispatcher = clone $originalDispatcher;
        Model::setEventDispatcher($dispatcher);
        $refused = false;
        $calls = 0;
        if ($kind !== 'none') {
            foreach ([LedgerAccount::class => ['saving', 'updating'], LedgerEntry::class => ['saving', 'creating']] as $class => $events) {
                foreach ($events as $event) {
                    $dispatcher->listen('eloquent.'.$event.': '.$class, function () use ($kind, $class, $event, &$refused, &$calls): ?bool {
                        $calls++;
                        if ($kind === 'retry-after-refusal' && ! $refused && $class === LedgerAccount::class && $event === 'saving') {
                            $refused = true;

                            return false;
                        }

                        return $kind === 'allow-true' ? true : null;
                    });
                }
            }
        }
        try {
            if ($kind === 'retry-after-refusal') {
                try {
                    app(LedgerPostingService::class)->post($tx, $draft);
                    $this->fail('First attempt must observe the refused account save.');
                } catch (FinancialException $error) {
                    $this->assertSame('ledger_account_write_refused', $error->errorCode());
                    $this->assertSame($before, LedgerPostingCurrentReadTest::book());
                    $this->assertSame($depth, DB::transactionLevel());
                }
            }
            $rows = app(LedgerPostingService::class)->post($tx, $draft);
        } finally {
            Model::setEventDispatcher($originalDispatcher);
        }
        $this->assertSame($depth, DB::transactionLevel());
        while (DB::transactionLevel() > 0) {
            DB::commit();
        }
        $this->assertCount(2, $rows);
        $this->assertSame($kind === 'none' ? 0 : ($kind === 'retry-after-refusal' ? 9 : 8), $calls);
        $this->assertSame($kind === 'retry-after-refusal', $refused);
        $this->assertSame(2, DB::table('ledger_entries')->count());
        foreach ($draft as $entry) {
            $this->assertSame(0, bccomp('10.00', (string) DB::table('ledger_accounts')->where('id', $entry['ledger_account_id'])->value('current_balance'), 2));
        }
        app(LedgerBalanceValidator::class)->assertTransactionBalanced($tx->fresh());
        $this->assertSame($originalDispatcher, Model::getEventDispatcher());
    }
}
